<?php
declare(strict_types=1);
/** Weryfikacja Etapu 3: klient Redis, limit prób, logowanie, dziennik. */

use CoachAnalyze\Audit;
use CoachAnalyze\Auth;
use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\RateLimit;
use CoachAnalyze\RedisClient;

$root = dirname(__DIR__, 3);

/*
 * BRAMKA WEJŚCIOWA — jak w `test_konta.php`. Bez atrapy Redisa ten zestaw
 * kończył się ostrzeżeniem „Undefined array key 1" i błędem typu w konstruktorze
 * `RedisClient`, czyli komunikatem o PHP zamiast o brakującej atrapie.
 */
$sock = $argv[1] ?? null;
if ($sock === null || !file_exists($sock)) {
    fwrite(STDERR, implode("\n", [
        'Ten zestaw wymaga atrapy Redisa.',
        '',
        '  php fake_redis.php /tmp/ca.sock &',
        '  php test_etap3.php /tmp/ca.sock',
        '',
        $sock === null
            ? 'Nie podano ścieżki gniazda jako pierwszego argumentu.'
            : "Gniazdo nie istnieje: {$sock}",
        '',
    ]) . "\n");
    exit(2);
}

ob_start();
require $root . '/app/src/bootstrap.php';

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $fail++; echo "  BŁĄD $name" . ($detail ? " — $detail" : '') . "\n"; }
}

// ---------------------------------------------------------------- Redis
echo "== klient Redis (RESP przez gniazdo uniksowe) ==\n";
$r = new RedisClient($sock, 'test:');
check('PING', $r->ping());
check('INCR zwraca 1 przy pierwszym trafieniu', $r->incr('a') === 1);
check('INCR zwraca 2 przy drugim', $r->incr('a') === 2);
check('GET zwraca wartość', $r->get('a') === '2');
check('GET nieistniejącego klucza to null', $r->get('nie-ma') === null);
check('EXPIRE ustawia okno', $r->expire('a', 60));
check('TTL w zakresie', ($t = $r->ttl('a')) > 0 && $t <= 60, "ttl=$t");
check('DEL usuwa', $r->del('a') === 1 && $r->get('a') === null);
$r->close();

// ---------------------------------------------------------------- baza
echo "\n== baza (SQLite w pamięci, kształt zgodny z 001_init.sql) ==\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
@$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE,
    pass_hash TEXT, display_name TEXT, role TEXT DEFAULT "operator", status TEXT NOT NULL DEFAULT "active",
        must_change_password INT NOT NULL DEFAULT 0, created_by INT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, last_login_at TEXT NULL)');
$pdo->exec('CREATE TABLE audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL,
    action TEXT, entity TEXT NULL, entity_id INTEGER NULL, meta_json TEXT NULL, ip BLOB NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
Db::setPdo($pdo);

$hash = Auth::hashPassword('bardzo-dlugie-haslo-testowe');
check('argon2id, nie bcrypt', str_starts_with($hash, '$argon2id$'), substr($hash, 0, 12));
Db::run('INSERT INTO users (email, pass_hash, display_name, role) VALUES (?,?,?,?)',
    ['operator@example.com', $hash, 'Operator', 'operator']);

// ---------------------------------------------------------------- logowanie
echo "\n== logowanie ==\n";
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$limiter = new RateLimit(new RedisClient($sock, 'rl:'));
$auth = new Auth($limiter);

$res = $auth->attempt('operator@example.com', 'zle-haslo');
check('złe hasło odrzucone', $res['ok'] === false && $res['error'] === 'invalid_credentials');

$res = $auth->attempt('nie-ma@example.com', 'cokolwiek');
check('nieistniejące konto — ten sam kod błędu', $res['error'] === 'invalid_credentials');

// czas odpowiedzi ma być porównywalny (ochrona przed sondowaniem listy kont)
$t1 = microtime(true); $auth->attempt('operator@example.com', 'zle'); $d1 = microtime(true) - $t1;
$t2 = microtime(true); $auth->attempt('nie-ma-2@example.com', 'zle'); $d2 = microtime(true) - $t2;
$ratio = max($d1, $d2) / max(0.0001, min($d1, $d2));
check('czas odpowiedzi zbliżony dla konta istniejącego i nie', $ratio < 3.0,
    sprintf('istnieje %.0f ms, nie istnieje %.0f ms', $d1 * 1000, $d2 * 1000));

$res = $auth->attempt('operator@example.com', 'bardzo-dlugie-haslo-testowe');
check('poprawne hasło przyjęte', $res['ok'] === true);
check('hash nie wycieka w wyniku', !isset($res['user']['pass_hash']));
check('last_login_at zapisane',
    Db::one('SELECT last_login_at FROM users WHERE id=1')['last_login_at'] !== null);

// ---------------------------------------------------------------- limit prób
echo "\n== limit prób logowania ==\n";
$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
$limiter2 = new RateLimit(new RedisClient($sock, 'rl2:'));
$auth2 = new Auth($limiter2);
$blocked = null;
for ($i = 1; $i <= RateLimit::LOGIN_LIMIT + 2; $i++) {
    $res = $auth2->attempt('ofiara@example.com', 'zle-haslo');
    if ($res['error'] === 'rate_limited' && $blocked === null) {
        $blocked = $i;
    }
}
check('blokada po przekroczeniu limitu', $blocked !== null, "zablokowano przy próbie #$blocked");
check('limit zadziałał na progu', $blocked === RateLimit::LOGIN_LIMIT + 1,
    "oczekiwano #" . (RateLimit::LOGIN_LIMIT + 1) . ", było #$blocked");
check('komunikat niesie czas do odblokowania',
    ($res['retry_after'] ?? 0) > 0, 'retry_after=' . ($res['retry_after'] ?? 0));

// udane logowanie zeruje liczniki
$_SERVER['REMOTE_ADDR'] = '192.0.2.50';
$auth3 = new Auth(new RateLimit(new RedisClient($sock, 'rl3:')));
$auth3->attempt('operator@example.com', 'zle');
$auth3->attempt('operator@example.com', 'bardzo-dlugie-haslo-testowe');
$after = $auth3->attempt('operator@example.com', 'zle');
check('udane logowanie zeruje licznik', $after['error'] === 'invalid_credentials');

// awaria Redisa = logowanie zablokowane (fail closed)
$auth4 = new Auth(new RateLimit(new RedisClient('/tmp/nie-ma-takiego.sock', 'x:')));
$res = $auth4->attempt('operator@example.com', 'bardzo-dlugie-haslo-testowe');
check('brak Redisa blokuje logowanie (fail closed)', $res['error'] === 'login_unavailable');

// ---------------------------------------------------------------- dziennik
echo "\n== audit_log ==\n";
$akcje = array_column(Db::all('SELECT action FROM audit_log ORDER BY id'), 'action');
foreach ([Audit::LOGIN_OK, Audit::LOGIN_FAIL, Audit::LOGIN_RATE_LIMITED, Audit::LOGIN_BACKEND_DOWN] as $a) {
    check("odnotowano $a", in_array($a, $akcje, true));
}
$ip = Db::one('SELECT ip FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1', [Audit::LOGIN_OK])['ip'];
check('ip zapisane binarnie (inet_pton)', $ip !== null && @inet_ntop($ip) !== false,
    'ntop=' . (is_string($ip) ? (string) @inet_ntop($ip) : 'null'));

$out=ob_get_clean(); echo $out; echo "\n=== OK: $ok, BŁĘDÓW: $fail ===\n";
exit($fail === 0 ? 0 : 1);
