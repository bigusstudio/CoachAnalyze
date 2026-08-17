<?php
declare(strict_types=1);

/**
 * Sesja i token CSRF przy logowaniu — przelot przez PRAWDZIWY HTTP.
 *
 * Zgłoszenie z produkcji (Windows/Firefox): „Formularz stracił ważność.
 * Spróbuj jeszcze raz." przy KAŻDEJ próbie logowania. Przyczyną nie był stary
 * formularz, tylko brak sesji — przeglądarka nie zapisała ciasteczka `Secure`
 * przy ostrzeżeniu o certyfikacie. Komunikat obiecywał, że powtórzenie pomoże,
 * więc użytkownik klikał w nieskończoność.
 *
 * Sprawdzane twierdzenia:
 *  - formularz logowania DZIAŁA dla kogoś, kto nie ma jeszcze żadnej sesji
 *    (token nie jest przywiązany w sposób, który zawsze odrzuca pierwsze wejście),
 *  - żądanie BEZ ciasteczka sesji dostaje komunikat o ciasteczku/sesji,
 *    a nie o ważności formularza,
 *  - ciasteczko przy pustej sesji daje „Sesja wygasła", nie „formularz stracił ważność",
 *  - stary komunikat ZOSTAJE tam, gdzie jest prawdziwy: sesja żyje, token się nie zgadza,
 *  - po odrzuceniu formularz da się wysłać ponownie — pętla się rozplątuje,
 *  - trasy przedlogowe nie gubią komunikatu przez `flash` w nieistniejącej sesji,
 *  - ciasteczko `Secure` przy żądaniu bez HTTPS jest rozpoznawane i nazwane.
 *
 * Środowisko: dwa wbudowane serwery PHP (APP_URL http i https) + atrapa Redisa.
 *
 * Uruchomienie:  php test_sesja_http.php
 */

use CoachAnalyze\Db;

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

// ---------------------------------------------------------------- środowisko
$baza      = $here . '/sesja.sqlite';
$magazyn   = $here . '/sesja_storage';
$logFile   = $here . '/sesja.log';
$envFile   = $here . '/.env.sesja';
$envHttps  = $here . '/.env.sesja_https';
$sock      = $here . '/sesja_redis.sock';
$port      = 8951;
$portHttps = 8952;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

$wspolne = [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn,
    'LOG_PATH=' . $logFile,
    'REDIS_SOCKET=' . $sock,
    // Niskie parametry argon2id — test loguje się wielokrotnie, a koszt
    // produkcyjny niczego tu nie sprawdza.
    'ARGON_MEMORY_COST=8192',
    'ARGON_TIME_COST=1',
];

file_put_contents($envFile, implode("\n", array_merge($wspolne, [
    'APP_URL=http://127.0.0.1:' . $port,
    '',
])));

/*
 * Druga konfiguracja odtwarza SYTUACJĘ Z PRODUKCJI: aplikacja wie o sobie, że
 * stoi na HTTPS (APP_URL), więc znaczy ciasteczko sesji flagą `Secure`, a
 * żądanie przychodzi bez szyfrowania. Przeglądarka takie ciasteczko odrzuca
 * po cichu i sesja nie powstaje NIGDY — dokładnie to widział użytkownik.
 */
file_put_contents($envHttps, implode("\n", array_merge($wspolne, [
    'APP_URL=https://127.0.0.1:' . $portHttps,
    '',
])));

putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza, false);

// ---------------------------------------------------------------- procesy
$procesy = [];
$cicho = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

$procesy[] = proc_open('php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock), $cicho, $r1);
$procesy[] = proc_open(
    'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    $cicho,
    $r2
);
$procesy[] = proc_open(
    'php -S 127.0.0.1:' . $portHttps . ' ' . escapeshellarg($root . '/app/public/index.php'),
    $cicho,
    $r3,
    null,
    ['CA_ENV_PATH' => $envHttps, 'PATH' => (string) getenv('PATH')]
);

for ($i = 0; $i < 50 && !file_exists($sock); $i++) {
    usleep(100000);
}
foreach ([$port, $portHttps] as $p) {
    for ($i = 0; $i < 50; $i++) {
        $probka = @fsockopen('127.0.0.1', $p, $errno, $errstr, 0.2);
        if (is_resource($probka)) {
            fclose($probka);
            break;
        }
        usleep(100000);
    }
}

register_shutdown_function(static function () use ($procesy, $baza, $envFile, $envHttps, $logFile, $sock, $magazyn): void {
    foreach ($procesy as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
    @unlink($baza);
    @unlink($envFile);
    @unlink($envHttps);
    @unlink($logFile);
    @unlink($sock);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

// ---------------------------------------------------------------- pomocnicze
$ciasteczka = [];

/**
 * @param array<string,string> $form
 * @return array{status:int, location:?string, body:string, setCookie:string}
 */
function http(string $method, string $path, array $form = [], ?int $naPorcie = null): array
{
    global $port, $ciasteczka;

    $naglowki = ['Connection: close'];
    if ($ciasteczka !== []) {
        $pary = [];
        foreach ($ciasteczka as $k => $v) {
            $pary[] = $k . '=' . $v;
        }
        $naglowki[] = 'Cookie: ' . implode('; ', $pary);
    }

    $tresc = null;
    if ($form !== []) {
        $tresc = http_build_query($form);
        $naglowki[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $naglowki),
        'content'         => $tresc,
        'follow_location' => 0,
        'ignore_errors'   => true,
        'timeout'         => 30,
    ]]);

    $body = @file_get_contents('http://127.0.0.1:' . ($naPorcie ?? $port) . $path, false, $ctx);

    $status = 0;
    $location = null;
    $setCookie = '';
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) {
            $status = (int) $m[1];
            $location = null;
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        } elseif (stripos($h, 'Set-Cookie:') === 0) {
            $setCookie .= trim(substr($h, 11)) . "\n";
            if (preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
                $ciasteczka[trim($m[1])] = trim($m[2]);
            }
        }
    }

    return ['status' => $status, 'location' => $location, 'body' => (string) $body, 'setCookie' => $setCookie];
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

const HASLO = 'bardzo-dlugie-haslo-testowe';
const KONTO = 'operator@example.com';

// ============================================================ 1. pierwsze wejście
echo "== pierwsze wejście: BEZ ŻADNEJ SESJI, aż do zalogowania ==\n";

$ciasteczka = [];
$ekran = http('GET', '/login');
check('formularz logowania odpowiada', $ekran['status'] === 200, 'status ' . $ekran['status']);
check('pierwsze wejście zakłada sesję (Set-Cookie ca_session)',
    str_contains($ekran['setCookie'], 'ca_session='), $ekran['setCookie']);
check('ciasteczko sesji jest HttpOnly', stripos($ekran['setCookie'], 'httponly') !== false);
check('ciasteczko sesji ma SameSite=Lax', stripos($ekran['setCookie'], 'samesite=lax') !== false);
check('na HTTP ciasteczko NIE jest Secure (inaczej nie wróciłoby)',
    stripos($ekran['setCookie'], 'secure') === false, $ekran['setCookie']);

$csrf = csrfZ($ekran['body']);
check('formularz niesie token CSRF', $csrf !== '');

$zalogowanie = http('POST', '/login', ['email' => KONTO, 'password' => HASLO, 'csrf' => $csrf]);
check('LOGOWANIE PRZY PIERWSZYM WEJŚCIU PRZECHODZI',
    $zalogowanie['status'] === 302 && $zalogowanie['location'] === '/',
    $zalogowanie['status'] . ' → ' . (string) $zalogowanie['location']);
check('panel wpuszcza', http('GET', '/')['status'] === 200);

// ============================================================ 2. bez ciasteczka
echo "\n== żądanie BEZ ciasteczka sesji: komunikat o ciasteczku, nie o formularzu ==\n";

$ciasteczka = [];
$bezCiasteczka = http('POST', '/login', ['email' => KONTO, 'password' => HASLO, 'csrf' => $csrf]);

check('próba odrzucona (401)', $bezCiasteczka['status'] === 401, 'status ' . $bezCiasteczka['status']);
check('KOMUNIKAT MÓWI O CIASTECZKU SESJI',
    str_contains($bezCiasteczka['body'], 'ciasteczka sesji'));
check('komunikat NIE mówi „formularz stracił ważność"',
    !str_contains($bezCiasteczka['body'], 'stracił ważność'));
check('komunikat mówi wprost, że POWTÓRZENIE NIE POMOŻE',
    str_contains($bezCiasteczka['body'], 'niczego tu nie zmieni'));
check('formularz wraca z nowym tokenem (da się spróbować po naprawie)',
    csrfZ($bezCiasteczka['body']) !== '');

// ============================================================ 3. sesja wygasła
echo "\n== ciasteczko jest, sesji nie ma: komunikat o wygaśnięciu sesji ==\n";

// Identyfikator sesji, której serwer nigdy nie widział — dokładnie to zostaje
// w przeglądarce po sprzątnięciu plików sesji albo restarcie magazynu.
$ciasteczka = ['ca_session' => bin2hex(random_bytes(16))];
$wygasla = http('POST', '/login', ['email' => KONTO, 'password' => HASLO, 'csrf' => $csrf]);

check('próba odrzucona (401)', $wygasla['status'] === 401, 'status ' . $wygasla['status']);
check('KOMUNIKAT MÓWI O WYGAŚNIĘCIU SESJI',
    str_contains($wygasla['body'], 'Sesja wygasła'));
check('komunikat NIE mówi „formularz stracił ważność"',
    !str_contains($wygasla['body'], 'stracił ważność'));

// ============================================================ 4. prawdziwy stary formularz
echo "\n== sesja żyje, token się nie zgadza: STARY KOMUNIKAT ZOSTAJE ==\n";

$ciasteczka = [];
http('GET', '/login');                       // świeża, żywa sesja
$zlyToken = http('POST', '/login', ['email' => KONTO, 'password' => HASLO,
    'csrf' => str_repeat('0', 64)]);

check('próba odrzucona (401)', $zlyToken['status'] === 401, 'status ' . $zlyToken['status']);
check('TU komunikat o ważności formularza jest prawdziwy i zostaje',
    str_contains($zlyToken['body'], 'stracił ważność'));
check('nie miesza się z komunikatem o ciasteczku',
    !str_contains($zlyToken['body'], 'ciasteczka sesji'));

// ============================================================ 5. pętla się rozplątuje
echo "\n== po odrzuceniu druga próba PRZECHODZI (pętla się rozplątuje) ==\n";

$druga = http('POST', '/login', ['email' => KONTO, 'password' => HASLO,
    'csrf' => csrfZ($zlyToken['body'])]);
check('powtórzenie z tokenem ze zwróconej strony loguje',
    $druga['status'] === 302 && $druga['location'] === '/',
    $druga['status'] . ' → ' . (string) $druga['location']);

// ============================================================ 6. trasy przedlogowe
echo "\n== /haslo/zapomniane bez sesji: komunikat NIE GINIE w przekierowaniu ==\n";

/*
 * Ścieżka przekierowania przenosi komunikat przez `flash`, czyli PRZEZ SESJĘ.
 * Gdy sesji nie ma, komunikat przepadłby po drodze i użytkownik zobaczyłby
 * czysty formularz bez słowa wyjaśnienia — ta sama cicha pętla, tylko bez tekstu.
 */
$ciasteczka = [];
$tokenReset = csrfZ(http('GET', '/haslo/zapomniane')['body']);

$ciasteczka = [];
$bezSesji = http('POST', '/haslo/zapomniane', ['email' => KONTO, 'csrf' => $tokenReset]);
check('BEZ SESJI odpowiedź nie jest przekierowaniem', $bezSesji['status'] !== 302,
    'status ' . $bezSesji['status'] . ' → ' . (string) $bezSesji['location']);
check('komunikat o ciasteczku jest W TEJ SAMEJ ODPOWIEDZI',
    str_contains($bezSesji['body'], 'ciasteczka sesji'));
check('formularz nadal jest na stronie', csrfZ($bezSesji['body']) !== '');

// Dla porównania: przy ŻYWEJ sesji stara ścieżka z przekierowaniem zostaje.
$ciasteczka = [];
http('GET', '/haslo/zapomniane');
$zlyTokenReset = http('POST', '/haslo/zapomniane', ['email' => KONTO, 'csrf' => str_repeat('0', 64)]);
check('przy żywej sesji zostaje przekierowanie z komunikatem we `flash`',
    $zlyTokenReset['status'] === 302 && $zlyTokenReset['location'] === '/haslo/zapomniane',
    $zlyTokenReset['status'] . ' → ' . (string) $zlyTokenReset['location']);
check('komunikat dowieziony przekierowaniem',
    str_contains(http('GET', '/haslo/zapomniane')['body'], 'stracił ważność'));

// ============================================================ 7. Secure bez HTTPS
echo "\n== ciasteczko Secure przy żądaniu bez szyfrowania (objaw z produkcji) ==\n";

$ciasteczka = [];
$ekranHttps = http('GET', '/login', [], $portHttps);
check('serwer odpowiada', $ekranHttps['status'] === 200, 'status ' . $ekranHttps['status']);
check('ciasteczko sesji ma flagę Secure (to jest ta sytuacja)',
    stripos($ekranHttps['setCookie'], 'secure') !== false, $ekranHttps['setCookie']);
check('OSTRZEŻENIE POKAZANE ZANIM KTOKOLWIEK KLIKNĄŁ „Zaloguj"',
    str_contains($ekranHttps['body'], 'bez szyfrowania'));
check('ostrzeżenie nazywa HTTPS jako warunek',
    str_contains($ekranHttps['body'], 'https://'));

$csrfHttps = csrfZ($ekranHttps['body']);
$ciasteczka = [];                            // przeglądarka odrzuciła ciasteczko Secure
$proba = http('POST', '/login', ['email' => KONTO, 'password' => HASLO, 'csrf' => $csrfHttps], $portHttps);
check('próba odrzucona (401)', $proba['status'] === 401, 'status ' . $proba['status']);
check('KOMUNIKAT WSKAZUJE SZYFROWANIE, nie ważność formularza',
    str_contains($proba['body'], 'bez szyfrowania') && !str_contains($proba['body'], 'stracił ważność'));

// Ta sama diagnoza trafia do logu — bez niej administrator nie ma czego szukać.
check('przyczyna zapisana w logu serwera',
    str_contains((string) @file_get_contents($logFile), 'flagę Secure'));

// ============================================================ 8. ślad w audycie
echo "\n== odrzucenia zostają w audycie ==\n";

ca_test_db($baza);
$csrfFail = (int) Db::one("SELECT COUNT(*) AS ile FROM audit_log WHERE action = 'csrf.fail'")['ile'];
check('każde odrzucenie tokenu zapisane w audit_log', $csrfFail >= 6, 'wpisów: ' . $csrfFail);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
