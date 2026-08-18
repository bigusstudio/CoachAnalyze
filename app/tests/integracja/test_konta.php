<?php
declare(strict_types=1);

/**
 * Zarządzanie kontami: role, stan, hasła, zabezpieczenia.
 *
 * Uruchomienie:  php test_konta.php
 */

use CoachAnalyze\Auth;
use CoachAnalyze\Db;
use CoachAnalyze\Stats;
use CoachAnalyze\Users;
use CoachAnalyze\View;

$root = dirname(__DIR__, 3);
$here = __DIR__;

$ok = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/*
 * BRAMKA WEJŚCIOWA: ten zestaw NIE DZIAŁA bez atrapy Redisa.
 *
 * Limiter logowania jest „fail closed" — bez Redisa `Auth::attempt()` zwraca
 * `login_unavailable` i NIE dochodzi do weryfikacji hasła. Uruchomiony bez
 * gniazda zestaw sypie wtedy pięcioma błędami, z których ani jeden nie mówi
 * o Redisie: „komunikat TAKI SAM jak przy zlym hasle", brak wpisu
 * `login.disabled`, nieudane logowanie po przywróceniu konta, a dalej
 * `account.err.reauth` przy zmianie hasła — bo skoro logowanie nie przeszło,
 * sesja nie dostała pełnego dostępu. Jedna brakująca atrapa, pięć mylących
 * objawów i godzina szukania regresji, której nie ma.
 *
 * Dlatego mówimy o tym wprost i NIE udajemy, że test się wykonał: brak atrapy
 * kończy się kodem różnym od zera. Cicho pominięty zestaw jest gorszy niż
 * zestaw, który głośno odmawia startu.
 */
$sock = $argv[1] ?? null;
if ($sock === null || !file_exists($sock)) {
    fwrite(STDERR, implode("\n", [
        'Ten zestaw wymaga atrapy Redisa (limiter logowania jest „fail closed").',
        '',
        '  php fake_redis.php /tmp/ca.sock &',
        '  php test_konta.php /tmp/ca.sock',
        '',
        $sock === null
            ? 'Nie podano ścieżki gniazda jako pierwszego argumentu.'
            : "Gniazdo nie istnieje: {$sock}",
        '',
    ]) . "\n");
    exit(2);
}

$baza    = $here . '/konta.sqlite';
$envFile = $here . '/.env.konta';
@unlink($baza);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $here, 'APP_URL=https://app.example.test',
    'SESSION_NAME=ca_test', 'PASSWORD_MIN_LENGTH=8',
    // Limiter logowania siedzi w Redisie i „fail closed" — bez niego `attempt()`
    // odmawia logowania niezaleznie od hasla. Atrapa sprawdzona wyzej, w bramce.
    'REDIS_SOCKET=' . $sock,
    'REDIS_PREFIX=konta:', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza, false);

// Konto zalozone przez seed jest operatorem — robimy z niego administratora.
Db::run("UPDATE users SET role = 'admin' WHERE id = 1");
$ADMIN = 1;

// ================================================================ HASŁA
echo "== hasla generowane, nie wpisywane ==\n";

$h1 = Users::generatePassword();
$h2 = Users::generatePassword();

check('haslo ma co najmniej 16 znakow', strlen($h1) >= 16, (string) strlen($h1));
check('dwa wywolania daja rozne hasla', $h1 !== $h2);
check('bez znakow mylacych przy przepisywaniu',
    preg_match('/[0OlI1]/', $h1 . $h2) !== 1,
    'haslo bywa dyktowane przez telefon');

$roznorodnosc = [];
for ($i = 0; $i < 200; $i++) {
    $roznorodnosc[Users::generatePassword()] = true;
}
check('200 losowan daje 200 roznych hasel', count($roznorodnosc) === 200);

// ================================================================ ZAKŁADANIE
echo "\n== zakladanie konta ==\n";

$nowe = Users::create([
    'email' => 'trener@example.test', 'display_name' => 'Trener', 'role' => 'operator',
], $ADMIN);

check('konto zalozone', $nowe['id'] > 0);
check('haslo zwrocone DO POKAZANIA', strlen($nowe['password']) >= 16);

$konto = Users::find($nowe['id']);
check('w bazie jest HASH, nie haslo',
    $konto['pass_hash'] !== $nowe['password'] && str_starts_with((string) $konto['pass_hash'], '$argon2id'));
check('haslo dziala', password_verify($nowe['password'], (string) $konto['pass_hash']));
check('konto czynne', $konto['status'] === 'active');
check('wymuszona zmiana hasla wlaczona', (int) $konto['must_change_password'] === 1);
check('zapisany autor', (int) $konto['created_by'] === $ADMIN);

echo "\n== haslo NIE trafia do audytu ==\n";
$wpisy = Db::all("SELECT action, meta_json FROM audit_log WHERE action = 'user.created'");
check('audyt odnotowal zalozenie', count($wpisy) === 1);
$meta = (string) ($wpisy[0]['meta_json'] ?? '');
check('audyt NIE zawiera hasla', !str_contains($meta, $nowe['password']), 'haslo w audycie');
check('audyt zawiera role i adres',
    str_contains($meta, 'operator') && str_contains($meta, 'trener@example.test'));

echo "\n== walidacja ==\n";
foreach ([
    ['email' => 'nie-adres', 'display_name' => 'X', 'role' => 'operator'],
    ['email' => 'a@b.test', 'display_name' => '', 'role' => 'operator'],
    ['email' => 'a@b.test', 'display_name' => 'X', 'role' => 'wymyslona'],
    ['email' => 'trener@example.test', 'display_name' => 'X', 'role' => 'operator'],
] as $i => $zle) {
    $blad = null;
    try {
        Users::create($zle, $ADMIN);
    } catch (\Throwable $e) {
        $blad = $e->getMessage();
    }
    check('niepoprawne dane odrzucone (' . $i . ')', $blad !== null, json_encode($zle));
}

// ================================================================ ROLE
echo "\n== zmiana roli ==\n";

Users::setRole($nowe['id'], 'viewer', $ADMIN);
check('rola zmieniona', Users::find($nowe['id'])['role'] === 'viewer');
check('audyt odnotowal zmiane',
    count(Db::all("SELECT id FROM audit_log WHERE action = 'user.role_changed'")) === 1);

$blad = null;
try {
    Users::setRole($ADMIN, 'viewer', $ADMIN);
} catch (\Throwable $e) {
    $blad = $e->getMessage();
}
check('admin NIE zmieni wlasnej roli', $blad !== null, 'system zostalby bez administratora');
check('rola admina nietknieta', Users::find($ADMIN)['role'] === 'admin');

echo "\n== ostatni administrator ==\n";
check('jest dokladnie jeden czynny admin', Users::activeAdminCount() === 1);

$drugi = Users::create([
    'email' => 'admin2@example.test', 'display_name' => 'Drugi admin', 'role' => 'admin',
], $ADMIN);
check('dwoch adminow', Users::activeAdminCount() === 2);

// Teraz degradacja jednego z nich jest dozwolona.
Users::setRole($drugi['id'], 'operator', $ADMIN);
check('degradacja przy dwoch adminach przechodzi', Users::activeAdminCount() === 1);

// Wroc do dwoch i sprobuj zdegradowac OSTATNIEGO przez kogos innego.
Users::setRole($drugi['id'], 'admin', $ADMIN);
Db::run("UPDATE users SET role = 'operator' WHERE id = :id", ['id' => $ADMIN]);
check('teraz jeden admin (drugi)', Users::activeAdminCount() === 1);

$blad = null;
try {
    Users::setRole($drugi['id'], 'operator', $ADMIN);
} catch (\Throwable $e) {
    $blad = $e->getMessage();
}
check('NIE da sie zdegradowac ostatniego admina', $blad !== null);
check('komunikat tlumaczy powod',
    $blad !== null && str_contains($blad, 'jedyny czynny administrator'), (string) $blad);

Db::run("UPDATE users SET role = 'admin' WHERE id = :id", ['id' => $ADMIN]);

// ================================================================ STAN KONTA
echo "\n== dezaktywacja zamiast usuwania ==\n";

Users::setStatus($nowe['id'], 'disabled', $ADMIN);
$konto = Users::find($nowe['id']);
check('konto istnieje nadal', $konto !== null, 'usuniecie zerwaloby powiazania z audytem');
check('stan to disabled', $konto['status'] === 'disabled');
check('audyt odnotowal', count(Db::all("SELECT id FROM audit_log WHERE action = 'user.disabled'")) === 1);

$blad = null;
try {
    Users::setStatus($ADMIN, 'disabled', $ADMIN);
} catch (\Throwable $e) {
    $blad = $e->getMessage();
}
check('admin NIE wylaczy sam siebie', $blad !== null);

Users::setStatus($nowe['id'], 'active', $ADMIN);
check('konto da sie przywrocic', Users::find($nowe['id'])['status'] === 'active');

echo "\n== dezaktywacja uniewaznia dostep ==\n";

Db::run("INSERT INTO remember_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (:u, 'hash1', :e, :t)",
    ['u' => $nowe['id'], 'e' => Stats::now('+30 days'), 't' => Stats::now()]);
check('token trwaly istnieje',
    (int) Db::one('SELECT COUNT(*) AS i FROM remember_tokens WHERE user_id = :u',
        ['u' => $nowe['id']])['i'] === 1);

Users::setStatus($nowe['id'], 'disabled', $ADMIN);
check('tokeny uniewaznione przy wylaczeniu',
    (int) Db::one('SELECT COUNT(*) AS i FROM remember_tokens WHERE user_id = :u',
        ['u' => $nowe['id']])['i'] === 0,
    'konto wylaczone na liscie, a dzialajace w przegladarce');

echo "\n== wylaczone konto nie zaloguje sie ==\n";
// `RateLimit` jest `final` — nie podmieniamy go atrapa, tylko dajemy mu
// atrape Redisa. Sprawdzamy przy okazji prawdziwa sciezke logowania.
$auth = new Auth();
$wynik = $auth->attempt('trener@example.test', $nowe['password']);
check('logowanie odrzucone', $wynik['ok'] === false);
check('komunikat TAKI SAM jak przy zlym hasle',
    $wynik['error'] === 'invalid_credentials',
    'inny komunikat zdradzalby, ktore adresy istnieja');
check('audyt odnotowal probe na wylaczonym koncie',
    count(Db::all("SELECT id FROM audit_log WHERE action = 'login.disabled'")) === 1);

Users::setStatus($nowe['id'], 'active', $ADMIN);
check('po przywroceniu logowanie dziala',
    $auth->attempt('trener@example.test', $nowe['password'])['ok'] === true);

// ================================================================ RESET HASŁA
echo "\n== reset hasla przez administratora ==\n";

$stary = Users::find($nowe['id'])['pass_hash'];
$noweHaslo = Users::resetPassword($nowe['id'], $ADMIN);

check('zwrocone nowe haslo', strlen($noweHaslo) >= 16);
check('hash sie zmienil', Users::find($nowe['id'])['pass_hash'] !== $stary);
check('nowe haslo dziala',
    password_verify($noweHaslo, (string) Users::find($nowe['id'])['pass_hash']));
check('flaga wymuszonej zmiany zalozona',
    (int) Users::find($nowe['id'])['must_change_password'] === 1);

$wpis = Db::one("SELECT meta_json FROM audit_log WHERE action = 'user.password_reset'");
check('audyt odnotowal reset', $wpis !== null);
check('audyt NIE zawiera nowego hasla',
    !str_contains((string) ($wpis['meta_json'] ?? ''), $noweHaslo));

echo "\n== flage zdejmuje TYLKO wlasciciel konta ==\n";
$wynik = $auth->changePassword($nowe['id'], $noweHaslo, 'wlasne-dlugie-haslo-99');
check('zmiana hasla przez wlasciciela przeszla', $wynik['ok'] === true, json_encode($wynik));
check('flaga zdjeta', (int) Users::find($nowe['id'])['must_change_password'] === 0);

// ================================================================ UPRAWNIENIA
echo "\n== uprawnienia rol ==\n";

$admin    = ['role' => 'admin'];
$operator = ['role' => 'operator'];
$viewer   = ['role' => 'viewer'];

check('admin zarzadza kontami', Users::can($admin, 'accounts'));
check('operator NIE zarzadza kontami', !Users::can($operator, 'accounts'));
check('viewer NIE zarzadza kontami', !Users::can($viewer, 'accounts'));

check('operator wgrywa eksporty', Users::can($operator, 'upload'));
check('viewer NIE wgrywa', !Users::can($viewer, 'upload'), 'upload wprowadza dane, to nie podglad');
check('viewer NIE udostepnia', !Users::can($viewer, 'share'), 'udostepnienie wypuszcza dane poza panel');
check('viewer NIE generuje raportow', !Users::can($viewer, 'generate'));

check('viewer oglada raporty', Users::can($viewer, 'reports'));
check('viewer oglada notatki', Users::can($viewer, 'notes'));

check('nieznana rola nie moze nic', !Users::can(['role' => 'wymyslona'], 'reports'));
check('brak uzytkownika nie moze nic', !Users::can(null, 'reports'));

check('isAdmin rozpoznaje admina', Users::isAdmin($admin));
check('isAdmin odrzuca operatora', !Users::isAdmin($operator));

// ================================================================ LISTA
echo "\n== lista kont ==\n";
$lista = Users::all();
check('lista niepusta', count($lista) >= 3);
$pierwszy = $lista[0];
foreach (['email', 'display_name', 'role', 'status', 'created_at', 'last_login_at'] as $pole) {
    check("lista niesie pole {$pole}", array_key_exists($pole, $pierwszy));
}
check('lista NIE niesie hasha hasla', !array_key_exists('pass_hash', $pierwszy),
    'hash nie ma po co trafiac do widoku');

// ================================================================ TEKSTY
echo "\n== teksty interfejsu ==\n";
$brak = [];
foreach ([
    'nav.users', 'users.title', 'users.new', 'users.create', 'users.scope_warning',
    'users.password.heading', 'users.password.once', 'users.password.must_change',
    'users.password.generated', 'users.must_change',
    'users.role.admin', 'users.role.operator', 'users.role.viewer',
    'users.status.active', 'users.status.disabled',
    'users.err.self_role', 'users.err.self_disable', 'users.err.last_admin',
    'users.err.forbidden', 'users.err.taken',
] as $k) {
    if (View::t($k) === $k) {
        $brak[] = $k;
    }
}
check('wszystkie klucze maja polskie teksty', $brak === [], implode(', ', $brak));

check('ostrzezenie o zakresie danych mowi wprost o separacji',
    str_contains(View::t('users.scope_warning'), 'wszystkich klubów')
    && str_contains(View::t('users.scope_warning'), 'osobnego wdrożenia'));

@unlink($baza);
@unlink($envFile);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
