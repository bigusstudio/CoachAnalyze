<?php
declare(strict_types=1);
/** Weryfikacja trwałego logowania: rotacja, unieważnianie, wygasanie, uprawnienia. */

use CoachAnalyze\Auth;
use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\Remember;
use CoachAnalyze\Session;
use CoachAnalyze\View;

$root = dirname(__DIR__, 3);
ob_start();
require $root . '/app/src/bootstrap.php';
require __DIR__ . '/seed.php';

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $fail++; echo "  BŁĄD $name" . ($detail ? " — $detail" : '') . "\n"; }
}

Config::reset(['APP_ENV' => 'test', 'ARGON_MEMORY_COST' => '8192', 'ARGON_TIME_COST' => '1']);
$db = '/tmp/ca_rem_' . getmypid() . '.sqlite';
@unlink($db);
ca_test_db($db, false);
// tabelę remember_tokens tworzy teraz wspólny seed

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/120.0 Safari/537';
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$userId = (int) Db::one('SELECT id FROM users LIMIT 1')['id'];

// ------------------------------------------------------------------ token
echo "== token ==\n";
$t1 = Remember::issue($userId);
check('długość 64 znaki (256 bitów)', strlen($t1) === 64, (string) strlen($t1));
check('wyłącznie znaki szesnastkowe', preg_match('/^[0-9a-f]{64}$/', $t1) === 1);

$tokeny = [];
for ($i = 0; $i < 200; $i++) { $tokeny[] = Remember::issue($userId); }
check('brak powtórzeń w 200 losowaniach', count(array_unique($tokeny)) === 200);
Db::run('DELETE FROM remember_tokens');

$src = (string) file_get_contents($root . '/app/src/Remember.php');
check('token z CSPRNG', str_contains($src, 'random_bytes')
    && !preg_match('/\b(mt_rand|rand|uniqid)\s*\(/', $src));

echo "\n== w bazie tylko skrót ==\n";
$t = Remember::issue($userId);
$wiersz = Db::one('SELECT token_hash FROM remember_tokens ORDER BY id DESC LIMIT 1');
check('wartość jawna NIE jest zapisana', $wiersz['token_hash'] !== $t);
check('zapisany jest sha256 tokenu', $wiersz['token_hash'] === hash('sha256', $t));
check('token nie występuje nigdzie w tabeli',
    Db::one('SELECT COUNT(*) c FROM remember_tokens WHERE token_hash = :t', ['t' => $t])['c'] === 0);

// ------------------------------------------------------------------ rotacja
echo "\n== rotacja przy każdym użyciu ==\n";
$r1 = Remember::consume($t);
check('poprawny token przyjęty', $r1['ok'] === true);
check('zwrócono NOWY token', ($r1['token'] ?? '') !== $t && strlen($r1['token'] ?? '') === 64);
check('stary token nie działa drugi raz', Remember::consume($t)['ok'] === false);
check('nowy token działa', Remember::consume($r1['token'])['ok'] === true);
check('liczba tokenów nie rośnie przy rotacji',
    (int) Db::one('SELECT COUNT(*) c FROM remember_tokens WHERE user_id = :u', ['u' => $userId])['c'] === 1);
check('użycie odnotowane w audit_log',
    (int) Db::one("SELECT COUNT(*) c FROM audit_log WHERE action='login.remember'")['c'] >= 1);
check('last_used_at zapisane',
    Db::one('SELECT last_used_at FROM remember_tokens ORDER BY id DESC LIMIT 1')['last_used_at'] !== null);

// ------------------------------------------------------------------ wygasanie
echo "\n== wygasanie ==\n";
Db::run('DELETE FROM remember_tokens');
$stary = Remember::issue($userId);
Db::run("UPDATE remember_tokens SET expires_at = :e", ['e' => \CoachAnalyze\Stats::now('-1 day')]);
check('wygasły token odrzucony', Remember::consume($stary)['ok'] === false);
check('wygasły token skasowany przy próbie',
    (int) Db::one('SELECT COUNT(*) c FROM remember_tokens')['c'] === 0);

$swiezy = Remember::issue($userId);
$termin = Db::one('SELECT expires_at FROM remember_tokens ORDER BY id DESC LIMIT 1')['expires_at'];
$dni = (strtotime((string) $termin) - time()) / 86400;
check('ważność 30 dni', $dni > 29.5 && $dni < 30.5, sprintf('%.1f dnia', $dni));

check('śmieci odrzucone', Remember::consume('nie-token')['ok'] === false);
check('pusty token odrzucony', Remember::consume('')['ok'] === false);

// ------------------------------------------------- unieważnianie hurtem
echo "\n== unieważnianie ==\n";
Db::run('DELETE FROM remember_tokens');
$a = Remember::issue($userId); $b = Remember::issue($userId); $c = Remember::issue($userId);
check('trzy urządzenia', count(Remember::devices($userId)) === 3);
check('forgetAll kasuje wszystkie', Remember::forgetAll($userId, 'test') === 3);
check('żaden nie działa po unieważnieniu',
    !Remember::consume($a)['ok'] && !Remember::consume($b)['ok'] && !Remember::consume($c)['ok']);

$x = Remember::issue($userId); $y = Remember::issue($userId);
$idX = (int) Db::one('SELECT id FROM remember_tokens WHERE token_hash = :h', ['h' => hash('sha256', $x)])['id'];
check('pojedyncze urządzenie wylogowane', Remember::forget($idX, $userId) === true);
check('pozostałe działają', Remember::consume($y)['ok'] === true);
check('cudzy token nieusuwalny', Remember::forget($idX, 999) === false);

// ------------------------------------------- unieważnienie przy zmianie hasła
echo "\n== zmiana hasła unieważnia wszystko ==\n";
Db::run('DELETE FROM remember_tokens');
Db::run('UPDATE users SET pass_hash = :h WHERE id = :id',
    ['h' => Auth::hashPassword('stare-haslo-testowe'), 'id' => $userId]);
$przed = Remember::issue($userId);
Session::login($userId, Session::LEVEL_PASSWORD);

$auth = new Auth();
$wynik = $auth->changePassword($userId, 'stare-haslo-testowe', 'nowe-haslo-testowe');
check('zmiana hasła powiodła się', $wynik['ok'] === true, $wynik['error'] ?? '');
check('token unieważniony po zmianie hasła', Remember::consume($przed)['ok'] === false);
check('zero tokenów w bazie', (int) Db::one('SELECT COUNT(*) c FROM remember_tokens')['c'] === 0);
check('nowe hasło działa',
    password_verify('nowe-haslo-testowe', Db::one('SELECT pass_hash FROM users WHERE id=:i', ['i'=>$userId])['pass_hash']));

check('złe obecne hasło odrzucone',
    ($auth->changePassword($userId, 'nie-to-haslo', 'inne-haslo-dlugie'))['error'] === 'account.err.current');
check('za krótkie nowe hasło odrzucone',
    ($auth->changePassword($userId, 'nowe-haslo-testowe', 'krotkie'))['error'] === 'account.err.short');

// ------------------------------------------- ograniczone uprawnienia
echo "\n== logowanie tokenem NIE daje pełnych uprawnień ==\n";
$tok = Remember::issue($userId);
Session::destroy();
$r = (new Auth())->loginFromCookie($tok);
check('zalogowano ciasteczkiem', $r['ok'] === true);
check('sesja ma poziom ograniczony', Session::level() === Session::LEVEL_REMEMBERED, Session::level());
check('brak pełnych uprawnień', Session::hasFullAccess() === false);
check('zmiana hasła ODRZUCONA przy sesji z ciasteczka',
    ($auth->changePassword($userId, 'nowe-haslo-testowe', 'jeszcze-inne-haslo'))['error'] === 'account.err.reauth');

Session::destroy();
Session::login($userId, Session::LEVEL_PASSWORD);
check('po podaniu hasła poziom pełny', Session::hasFullAccess() === true);

// ------------------------------------------------------------------ urządzenia
echo "\n== lista urządzeń ==\n";
Db::run('DELETE FROM remember_tokens');
Remember::issue($userId);
$dev = Remember::devices($userId)[0];
check('etykieta czytelna dla człowieka', $dev['label'] === 'Chrome · macOS', (string) $dev['label']);
check('etykieta bez numeru wersji', !preg_match('/\d+\.\d+/', (string) $dev['label']));
check('bieżące urządzenie rozpoznane', $dev['is_current'] === true);
check('user_agent_hash to skrót, nie treść',
    $dev['user_agent_hash'] === hash('sha256', $_SERVER['HTTP_USER_AGENT']));

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Safari/604';
Remember::issue($userId);
$wszystkie = Remember::devices($userId);
check('drugie urządzenie z inną etykietą',
    count(array_unique(array_column($wszystkie, 'label'))) === 2,
    implode(' | ', array_column($wszystkie, 'label')));
check('poprzednie urządzenie NIE jest oznaczone jako bieżące',
    count(array_filter($wszystkie, fn($d) => $d['is_current'])) === 1);

check('wygasłe nie trafiają na listę', (function () use ($userId) {
    Db::run("UPDATE remember_tokens SET expires_at = :e WHERE id = (SELECT MIN(id) FROM remember_tokens)",
        ['e' => \CoachAnalyze\Stats::now('-1 day')]);
    return count(Remember::devices($userId)) === 1;
})());

check('purgeExpired sprząta', Remember::purgeExpired() >= 1);

// ------------------------------------------------------------------ widok
echo "\n== widoki ==\n";
$login = View::render('login', ['csrf' => 'x', 'error' => null, 'notice' => null, 'email' => '']);
check('pole wyboru na ekranie logowania', str_contains($login, 'name="zapamietaj"'));
check('opis mówi o ograniczeniu uprawnień', str_contains($login, 'poprosimy o nie ponownie'));

$konto = View::render('account', [
    'user' => ['email' => 'a@b.pl'], 'devices' => Remember::devices($userId),
    'fullAuth' => false, 'notice' => null, 'error' => null,
]);
check('przy sesji z ciasteczka formularz hasła jest ZABLOKOWANY',
    !str_contains($konto, 'name="obecne"') && str_contains($konto, 'account.err.reauth') === false
    && str_contains($konto, 'zaloguj się, podając hasło'));
check('przycisk Wyloguj wszędzie dostępny', str_contains($konto, 'wyloguj-wszedzie'));
check('akcje urządzeń idą POST-em z tokenem', substr_count($konto, 'name="csrf"') >= 2);

$kontoPelne = View::render('account', [
    'user' => ['email' => 'a@b.pl'], 'devices' => [], 'fullAuth' => true,
    'notice' => null, 'error' => null,
]);
check('przy pełnej sesji formularz hasła dostępny', str_contains($kontoPelne, 'name="obecne"'));

@unlink($db);
$out = ob_get_clean(); echo $out;
echo "\n=== OK: $ok, BŁĘDÓW: $fail ===\n";
exit($fail === 0 ? 0 : 1);
