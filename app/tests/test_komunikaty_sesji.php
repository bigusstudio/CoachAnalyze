<?php
declare(strict_types=1);

/**
 * Odrzucenie tokenu CSRF musi NAZYWAĆ PRZYCZYNĘ.
 *
 * Zgłoszenie z produkcji (Windows/Firefox, ostrzeżenie o certyfikacie):
 * „Formularz stracił ważność. Spróbuj jeszcze raz." przy każdej próbie
 * logowania. Formularz był świeży — nie powstawała sesja, bo przeglądarka nie
 * zapisała ciasteczka `Secure`, więc token nie miał się gdzie zapisać.
 * Komunikat obiecywał, że powtórzenie pomoże, i użytkownik klikał w kółko.
 *
 * Czego pilnuje ten skaner:
 *  - komunikat powstaje z ROZPOZNANEJ przyczyny (`csrfMessage`), a nie z jednego
 *    tekstu na wszystkie przypadki,
 *  - żadna trasa nie sprawdza tokenu tak, że przyczyna przepada
 *    (`checkCsrf()` zamiast `csrfProblem()`),
 *  - teksty dla przyczyn NIENAPRAWIALNYCH powtórzeniem nie zachęcają do powtórzenia.
 *
 * Przebieg przez HTTP sprawdza `app/tests/integracja/test_sesja_http.php` —
 * ten zestaw jest statyczny, żeby chodził w CI bez środowiska.
 *
 * Uruchomienie:  php app/tests/test_komunikaty_sesji.php
 */

use CoachAnalyze\Session;

$root = dirname(__DIR__, 2);

// Bez autoloadera i bez bootstrapu: ten zestaw ma chodzić w CI, gdzie nie ma
// ani `.env`, ani bazy. `Session` potrzebuje wyłącznie `Config`.
require_once $root . '/app/src/Config.php';
require_once $root . '/app/src/Session.php';

/*
 * Konfiguracja na czas testu. Dwa powody: bez niej `Config` szuka `.env`,
 * którego w CI nie ma i wypisuje to do logu — a przede wszystkim APP_URL po
 * HTTPS jest WARUNKIEM sprawdzenia rozpoznania „ciasteczko Secure, żądanie
 * bez szyfrowania", czyli tego, co zapętliło logowanie na produkcji.
 */
$envTest = sys_get_temp_dir() . '/ca_test_sesja_' . getmypid() . '.env';
file_put_contents($envTest, "APP_ENV=test\nAPP_URL=https://example.test\n");
putenv('CA_ENV_PATH=' . $envTest);
register_shutdown_function(static function () use ($envTest): void {
    @unlink($envTest);
});

/*
 * Buforowanie wyjścia: `session_start()` odmawia pracy po wysłaniu nagłówków,
 * a w CLI za „nagłówki" robi pierwszy `echo`. Bez bufora sprawdzenia niżej
 * chodziłyby na zwykłej tablicy `$_SESSION` bez sesji — czyli nie sprawdzałyby
 * tego, co mają sprawdzać.
 */
ob_start();

$ok = 0;
$fail = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $ok, $fail;
    if ($condition) {
        $ok++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$indexPhp  = (string) file_get_contents($root . '/app/public/index.php');
$sessionPhp = (string) file_get_contents($root . '/app/src/Session.php');
$teksty    = require $root . '/app/src/lang/pl.php';

// ---------------------------------------------------------------- teksty
echo "== każda przyczyna ma własny tekst ==\n";

$klucze = [
    'login.err.csrf',            // stary formularz — TU powtórzenie pomaga
    'login.err.session_lost',    // sesja wygasła
    'login.err.cookie_blocked',  // przeglądarka nie odesłała ciasteczka
    'login.err.cookie_insecure', // ciasteczko Secure, żądanie bez HTTPS
    'login.err.session_storage', // serwer nie umie zapisać sesji
];

foreach ($klucze as $klucz) {
    check("tekst {$klucz} istnieje", isset($teksty[$klucz]) && trim((string) $teksty[$klucz]) !== '');
}

$wartosci = array_map(static fn(string $k): string => (string) ($teksty[$k] ?? ''), $klucze);
check('żadne dwa komunikaty nie są identyczne',
    count(array_unique($wartosci)) === count($wartosci),
    'komunikat wspólny dla dwóch przyczyn nie niesie przyczyny');

/*
 * Zachęta do powtórzenia jest sensowna WYŁĄCZNIE przy starym formularzu.
 * Przy odrzuconym ciasteczku powtórzenie da dokładnie ten sam wynik — i to
 * właśnie zapętliło użytkownika na produkcji.
 */
foreach (['login.err.cookie_blocked', 'login.err.cookie_insecure'] as $klucz) {
    check("tekst {$klucz} nie zachęca do powtórzenia próby",
        stripos((string) ($teksty[$klucz] ?? ''), 'spróbuj') === false,
        (string) ($teksty[$klucz] ?? ''));
}

check('tekst o braku ciasteczka mówi, czego dotyczy',
    str_contains((string) $teksty['login.err.cookie_blocked'], 'ciasteczk'));
check('tekst o braku szyfrowania wskazuje https',
    str_contains((string) $teksty['login.err.cookie_insecure'], 'https://'));

// ---------------------------------------------------------------- trasy
echo "\n== każde odrzucenie tokenu przechodzi przez rozpoznanie przyczyny ==\n";

check('Session udostępnia csrfProblem()', str_contains($sessionPhp, 'function csrfProblem('));

check('cztery przyczyny to cztery różne wartości',
    count(array_unique([Session::CSRF_OK, Session::CSRF_NO_COOKIE,
        Session::CSRF_NO_TOKEN, Session::CSRF_MISMATCH])) === 4);

check('warstwa tras NIE używa checkCsrf() — traci przyczynę',
    !str_contains($indexPhp, 'Session::checkCsrf('),
    'checkCsrf() zwraca bool; komunikat wymaga nazwanej przyczyny');

/*
 * Wszędzie, gdzie zapisujemy odrzucenie w audycie, w pobliżu musi powstać
 * komunikat z przyczyny. Ta sama technika, co w test_bramki_rol: bliskość
 * w kodzie zamiast wiary w to, że nikt nie skopiuje starego wzorca.
 */
$linie = explode("\n", $indexPhp);
$brakujace = [];
foreach ($linie as $nr => $linia) {
    if (!str_contains($linia, 'Audit::CSRF_FAIL')) {
        continue;
    }
    $okno = implode("\n", array_slice($linie, $nr, 12));
    if (!str_contains($okno, 'csrfMessage(')) {
        $brakujace[] = 'linia ' . ($nr + 1);
    }
}
check('każde zapisane odrzucenie daje komunikat z przyczyny', $brakujace === [],
    implode(', ', $brakujace));

$wystapienia = substr_count($indexPhp, "'login.err.csrf'");
check('tekst o starym formularzu pada TYLKO w csrfMessage()', $wystapienia === 1,
    'wystąpień: ' . $wystapienia . ' — pozostałe miejsca omijają rozpoznanie przyczyny');

echo "\n== ekrany przedlogowe pokazują ostrzeżenie ==\n";

foreach (['login', 'password_forgot'] as $widok) {
    $html = (string) file_get_contents($root . '/app/src/Views/' . $widok . '.php');
    check("widok {$widok} wypisuje \$warning", str_contains($html, '$warning'));
}
check('ostrzeżenie powstaje przed pierwszą próbą (loginCookieWarning)',
    str_contains($indexPhp, 'function loginCookieWarning('));

// ---------------------------------------------------------------- zachowanie
echo "\n== rozpoznanie przyczyny faktycznie działa ==\n";

// Bez tego zielony wynik znaczyłby tylko tyle, że funkcje istnieją.
$_COOKIE = [];
check('brak ciasteczka → no_cookie',
    Session::csrfProblem('cokolwiek') === Session::CSRF_NO_COOKIE);
check('sesja naprawdę wystartowała (inaczej sprawdzenia niżej nic nie znaczą)',
    session_status() === PHP_SESSION_ACTIVE);

// Ciasteczko wraca, sesja po stronie serwera pusta — sesja wygasła.
$_COOKIE[Session::COOKIE] = bin2hex(random_bytes(8));
check('ciasteczko bez tokenu w sesji → no_token',
    Session::csrfProblem('cokolwiek') === Session::CSRF_NO_TOKEN);

$token = Session::csrfToken();
check('żywa sesja, zły token → mismatch',
    Session::csrfProblem(str_repeat('0', 64)) === Session::CSRF_MISMATCH);
check('żywa sesja, pusty token → mismatch (a nie brak sesji)',
    Session::csrfProblem('') === Session::CSRF_MISMATCH);
check('żywa sesja, dobry token → ok', Session::csrfProblem($token) === Session::CSRF_OK);
check('checkCsrf() nadal odpowiada zgodnie z csrfProblem()',
    Session::checkCsrf($token) === true && Session::checkCsrf('nie ten') === false);

check('magazyn sesji jest sprawdzalny (storageProblem)',
    Session::storageProblem() === null || is_string(Session::storageProblem()));

echo "\n== ciasteczko Secure przy żądaniu bez szyfrowania ==\n";

check('APP_URL po HTTPS znaczy ciasteczko flagą Secure', Session::secureCookie() === true);

// Objaw z produkcji: aplikacja wie o sobie, że stoi na HTTPS, a żądanie
// przychodzi bez szyfrowania. Ciasteczko nie ma jak wrócić.
$_SERVER = ['SERVER_PORT' => '80'];
check('HTTPS w APP_URL + żądanie po HTTP → rozpoznane', Session::cookieUnreachable() === true);

/*
 * Fałszywy alarm byłby gorszy niż brak komunikatu: mówiłby zalogowanemu
 * człowiekowi, że logowanie nie zadziała. Dlatego każdy sposób, w jaki serwer
 * ogłasza szyfrowanie, musi wyłączać rozpoznanie.
 */
foreach ([
    'HTTPS=on'                   => ['HTTPS' => 'on'],
    'SERVER_PORT=443'            => ['SERVER_PORT' => '443'],
    'REQUEST_SCHEME=https'       => ['REQUEST_SCHEME' => 'https'],
    'X-Forwarded-Proto: https'   => ['HTTP_X_FORWARDED_PROTO' => 'https, http'],
    'X-Forwarded-SSL: on'        => ['HTTP_X_FORWARDED_SSL' => 'on'],
] as $opis => $serwer) {
    $_SERVER = $serwer;
    check("{$opis} wyłącza rozpoznanie (bez fałszywego alarmu)",
        Session::cookieUnreachable() === false);
}

$_SERVER = ['HTTPS' => 'off', 'SERVER_PORT' => '80'];
check('HTTPS=off nie udaje szyfrowania', Session::cookieUnreachable() === true);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
