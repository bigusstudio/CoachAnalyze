<?php
declare(strict_types=1);

/**
 * Reset i zmiana hasła — przelot przez PRAWDZIWY HTTP.
 *
 * Sprawdzane twierdzenia, każde wprost z wymagań:
 *  - odpowiedź na prośbę o reset jest IDENTYCZNA dla adresu istniejącego
 *    i nieistniejącego (formularz nie sonduje listy kont),
 *  - surowy token nie istnieje NIGDZIE poza mailem: w bazie skrót,
 *    w jobs.payload_json sam adres,
 *  - odnośnik działa raz i tylko przed upływem godziny,
 *  - limit próśb w Redisie blokuje po trzeciej prośbie na adres,
 *  - zmiana hasła unieważnia POZOSTAŁE sesje, a bieżącą zostawia,
 *  - stare hasło przestaje logować, nowe działa.
 *
 * Środowisko: wbudowany serwer PHP + atrapa Redisa + atrapa SMTP + cron.
 *
 * Uruchomienie:  php test_haslo_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Stats;

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
$baza     = $here . '/haslo.sqlite';
$magazyn  = $here . '/haslo_storage';
$logFile  = $here . '/haslo.log';
$envFile  = $here . '/.env.haslo';
$sock     = $here . '/haslo_redis.sock';
$smtpLog  = $here . '/haslo_smtp.log';
$port     = 8947;
$smtpPort = 8948;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
@unlink($smtpLog);
@unlink($smtpLog . '.ready');
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn,
    'LOG_PATH=' . $logFile,
    'APP_URL=http://127.0.0.1:' . $port,
    'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock,
    'SMTP_HOST=127.0.0.1',
    'SMTP_PORT=' . $smtpPort,
    'SMTP_ENCRYPTION=none',
    'SMTP_TIMEOUT=5',
    'MAIL_FROM=reset@example.test',
    // Niskie parametry argon2id — test haszuje wiele haseł, a koszt
    // produkcyjny niczego tu nie sprawdza.
    'ARGON_MEMORY_COST=8192',
    'ARGON_TIME_COST=1',
    '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza, false);

// ---------------------------------------------------------------- procesy
$procesy = [];
$cicho = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

$procesy[] = proc_open('php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock), $cicho, $r1);
$procesy[] = proc_open(
    'php ' . escapeshellarg($here . '/fake_smtp.php') . ' ' . $smtpPort . ' ' . escapeshellarg($smtpLog) . ' ok',
    $cicho,
    $r2
);
$procesy[] = proc_open(
    'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    $cicho,
    $r3
);

for ($i = 0; $i < 50 && (!file_exists($sock) || !file_exists($smtpLog . '.ready')); $i++) {
    usleep(100000);
}
for ($i = 0; $i < 50; $i++) {
    $probka = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($probka)) {
        fclose($probka);
        break;
    }
    usleep(100000);
}

register_shutdown_function(static function () use ($procesy, $baza, $envFile, $logFile, $sock, $smtpLog, $magazyn): void {
    foreach ($procesy as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
    @unlink($baza);
    @unlink($envFile);
    @unlink($logFile);
    @unlink($sock);
    @unlink($smtpLog);
    @unlink($smtpLog . '.ready');
    exec('rm -rf ' . escapeshellarg($magazyn));
});

// ---------------------------------------------------------------- pomocnicze
$bazaUrl    = 'http://127.0.0.1:' . $port;
$ciasteczka = [];

/** @return array{status:int, location:?string, body:string} */
function http(string $method, string $path, array $form = []): array
{
    global $bazaUrl, $ciasteczka;

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

    $body = @file_get_contents($bazaUrl . $path, false, $ctx);

    $status = 0;
    $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) {
            $status = (int) $m[1];
            $location = null;
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        } elseif (stripos($h, 'Set-Cookie:') === 0
            && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'location' => $location, 'body' => (string) $body];
}

function cron(): int
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile)
        . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1', $out, $kod);
    return $kod;
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/**
 * Treść maili z przechwyconej rozmowy SMTP. Mailer koduje części base64
 * w kawałkach po 76 znaków, więc surowy zapis nie zawiera tokenu w jednej
 * linii — bloki trzeba skleić i odkodować.
 */
function odkodujMaila(string $rozmowa): string
{
    $czysto = '';
    $blok = '';
    foreach (explode("\n", $rozmowa) as $linia) {
        $linia = trim($linia);
        if ($linia !== '' && preg_match('#^[A-Za-z0-9+/=]+$#', $linia) === 1 && strlen($linia) >= 40) {
            $blok .= $linia;
            continue;
        }
        if ($blok !== '') {
            $czysto .= (string) base64_decode($blok, true) . "\n";
            $blok = '';
        }
    }
    if ($blok !== '') {
        $czysto .= (string) base64_decode($blok, true);
    }
    return $czysto;
}

// ---------------------------------------------------------------- prośba o reset
echo "== prośba o reset: odpowiedź identyczna dla obu adresów ==\n";

$formularz = http('GET', '/haslo/zapomniane');
check('formularz prośby odpowiada', $formularz['status'] === 200, 'status ' . $formularz['status']);
$csrf = csrfZ($formularz['body']);
check('formularz niesie token CSRF', $csrf !== '');

$istnieje = http('POST', '/haslo/zapomniane', ['email' => 'operator@example.com', 'csrf' => $csrf]);
$nieMa    = http('POST', '/haslo/zapomniane', ['email' => 'nikt@example.com', 'csrf' => $csrf]);

check('adres istniejący: przekierowanie na logowanie',
    $istnieje['status'] === 302 && $istnieje['location'] === '/login',
    $istnieje['status'] . ' → ' . (string) $istnieje['location']);
check('ODPOWIEDŹ IDENTYCZNA dla adresu nieistniejącego',
    $nieMa['status'] === $istnieje['status'] && $nieMa['location'] === $istnieje['location'],
    $nieMa['status'] . ' → ' . (string) $nieMa['location']);

ca_test_db($baza);
$zadania = Db::all("SELECT * FROM jobs WHERE type = 'reset_mail'");
check('OBA adresy kolejkują zadanie (żądanie nie sprawdza istnienia)', count($zadania) === 2,
    'zadań: ' . count($zadania));
check('payload zadania NIE zawiera tokenu — sam adres',
    !preg_match('/[a-f0-9]{64}/', (string) $zadania[0]['payload_json']));

// ---------------------------------------------------------------- wysyłka
echo "\n== cron wysyła mail; token istnieje tylko w mailu ==\n";

check('cron przeszedł', cron() === 0);

ca_test_db($baza);
check('oba zadania done — nieistniejący adres obsłużony CISZĄ',
    (int) Db::one("SELECT COUNT(*) AS ile FROM jobs WHERE type = 'reset_mail' AND status = 'done'")['ile'] === 2);

$rozmowa = (string) @file_get_contents($smtpLog);
check('poszedł DOKŁADNIE jeden mail',
    substr_count($rozmowa, 'MAIL FROM') === 1, 'rozmów: ' . substr_count($rozmowa, 'MAIL FROM'));

preg_match('#/haslo/reset/([a-f0-9]{64})#', odkodujMaila($rozmowa), $m);
$token = $m[1] ?? '';
check('mail niesie odnośnik z tokenem', $token !== '');

$wiersz = Db::one('SELECT * FROM password_resets');
check('w bazie jest jeden wpis resetu', $wiersz !== null);
check('w bazie leży SKRÓT, nie token',
    $wiersz !== null && $wiersz['token_hash'] === hash('sha256', $token)
    && $wiersz['token_hash'] !== $token);
check('ważność ustawiona na godzinę',
    $wiersz !== null && (string) $wiersz['expires_at'] > Stats::now('+55 minutes')
    && (string) $wiersz['expires_at'] <= Stats::now('+60 minutes'));

// ---------------------------------------------------------------- użycie odnośnika
echo "\n== odnośnik: jednorazowy, z kontrolą długości hasła ==\n";

$ekran = http('GET', '/haslo/reset/' . $token);
check('ważny odnośnik pokazuje formularz', $ekran['status'] === 200, 'status ' . $ekran['status']);
check('formularz zna adres konta (menedżer haseł)',
    str_contains($ekran['body'], 'operator@example.com'));
$csrfReset = csrfZ($ekran['body']);

$zaKrotkie = http('POST', '/haslo/reset/' . $token, ['nowe' => 'krotkie', 'csrf' => $csrfReset]);
check('za krótkie hasło wraca na formularz',
    $zaKrotkie['status'] === 302 && $zaKrotkie['location'] === '/haslo/reset/' . $token);

$zapis = http('POST', '/haslo/reset/' . $token, ['nowe' => 'zupelnie-nowe-haslo-1', 'csrf' => $csrfReset]);
check('zapis nowego hasła przekierowuje na logowanie',
    $zapis['status'] === 302 && $zapis['location'] === '/login',
    $zapis['status'] . ' → ' . (string) $zapis['location']);

$ponownie = http('GET', '/haslo/reset/' . $token);
check('ZUŻYTY odnośnik przestaje działać (404)', $ponownie['status'] === 404,
    'status ' . $ponownie['status']);

$zly = http('GET', '/haslo/reset/' . str_repeat('a', 64));
check('zły token wygląda tak samo jak zużyty', $zly['status'] === $ponownie['status']);

// ---------------------------------------------------------------- logowanie po zmianie
echo "\n== stare hasło martwe, nowe działa ==\n";

$ciasteczka = [];
$csrf = csrfZ(http('GET', '/login')['body']);
$stare = http('POST', '/login', ['email' => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe', 'csrf' => $csrf]);
check('stare hasło nie loguje', $stare['status'] !== 302, 'status ' . $stare['status']);

$ciasteczka = [];
$csrf = csrfZ(http('GET', '/login')['body']);
$nowe = http('POST', '/login', ['email' => 'operator@example.com',
    'password' => 'zupelnie-nowe-haslo-1', 'csrf' => $csrf]);
check('nowe hasło loguje', $nowe['status'] === 302, 'status ' . $nowe['status']);

// ---------------------------------------------------------------- limit próśb
echo "\n== limit próśb w Redisie ==\n";

/*
 * Limit na adres: 3 prośby na godzinę. Jedna poszła na początku testu,
 * więc dwie kolejne przechodzą, a trzecia (czwarta łącznie) jest odbita —
 * z komunikatem, który nie mówi nic o istnieniu konta.
 */
$ciasteczka = [];
$csrf = csrfZ(http('GET', '/haslo/zapomniane')['body']);
$wyniki = [];
for ($i = 0; $i < 3; $i++) {
    $w = http('POST', '/haslo/zapomniane', ['email' => 'operator@example.com', 'csrf' => $csrf]);
    $wyniki[] = $w['location'];
}
check('druga i trzecia prośba przechodzą',
    $wyniki[0] === '/login' && $wyniki[1] === '/login', implode(' | ', array_map('strval', $wyniki)));
check('czwarta prośba odbita limitem',
    $wyniki[2] === '/haslo/zapomniane', (string) $wyniki[2]);

// ---------------------------------------------------------------- sesje przy zmianie hasła
echo "\n== zmiana hasła unieważnia POZOSTAŁE sesje, bieżącą zostawia ==\n";

// Przeglądarka A i przeglądarka B — obie zalogowane na to samo konto.
$ciasteczka = [];
$csrf = csrfZ(http('GET', '/login')['body']);
http('POST', '/login', ['email' => 'operator@example.com',
    'password' => 'zupelnie-nowe-haslo-1', 'csrf' => $csrf]);
$jarA = $ciasteczka;
$csrfA = csrfZ(http('GET', '/konto')['body']);

$ciasteczka = [];
$csrf = csrfZ(http('GET', '/login')['body']);
http('POST', '/login', ['email' => 'operator@example.com',
    'password' => 'zupelnie-nowe-haslo-1', 'csrf' => $csrf]);
$jarB = $ciasteczka;

check('obie przeglądarki zalogowane',
    ($ciasteczka = $jarA) !== null && http('GET', '/konto')['status'] === 200
    && ($ciasteczka = $jarB) !== null && http('GET', '/konto')['status'] === 200);

// Przeglądarka A zmienia hasło (stare + nowe).
$ciasteczka = $jarA;
$zmiana = http('POST', '/konto/haslo', [
    'obecne' => 'zupelnie-nowe-haslo-1',
    'nowe'   => 'trzecie-haslo-w-tym-tescie',
    'csrf'   => $csrfA,
]);
check('zmiana hasła przechodzi', $zmiana['status'] === 302 && $zmiana['location'] === '/konto',
    $zmiana['status'] . ' → ' . (string) $zmiana['location']);

$ciasteczka = $jarA;
check('sesja zmieniająca PRACUJE DALEJ', http('GET', '/konto')['status'] === 200);

$ciasteczka = $jarB;
$poZmianie = http('GET', '/konto');
check('druga sesja WYGASŁA natychmiast',
    $poZmianie['status'] === 302 && str_contains((string) $poZmianie['location'], '/login'),
    $poZmianie['status'] . ' → ' . (string) $poZmianie['location']);

// ---------------------------------------------------------------- wygaśnięcie tokenu
echo "\n== token po terminie jest martwy ==\n";

ca_test_db($baza);
Db::run("INSERT INTO jobs (type, payload_json, status, attempts, created_at)
         VALUES ('reset_mail', :p, 'queued', 0, :t)", [
    'p' => json_encode(['email' => 'operator@example.com']),
    't' => Stats::now(),
]);
/*
 * Atrapa SMTP obsługuje JEDNO połączenie i kończy pracę (patrz nagłówek
 * fake_smtp.php) — pierwsze zużył mail z początku testu. Przed drugą rundą
 * wysyłki podnosimy ją ponownie na tym samym porcie.
 *
 * Przy okazji: dwie prośby z badania limitu wyżej wciąż wiszą w kolejce
 * z odłożonym terminem (`available_at`) — nie przeszkadzają, worker ich
 * nie podejmie przed czasem.
 */
@unlink($smtpLog . '.ready');
$procesy[] = proc_open(
    'php ' . escapeshellarg($here . '/fake_smtp.php') . ' ' . $smtpPort . ' ' . escapeshellarg($smtpLog) . ' ok',
    $cicho,
    $r4
);
for ($i = 0; $i < 50 && !file_exists($smtpLog . '.ready'); $i++) {
    usleep(100000);
}

check('cron wydał świeży token', cron() === 0);

$rozmowa = (string) @file_get_contents($smtpLog);
preg_match_all('#/haslo/reset/([a-f0-9]{64})#', odkodujMaila($rozmowa), $m);
$swiezy = end($m[1]) ?: '';
check('świeży token w mailu', $swiezy !== '' && $swiezy !== $token);

ca_test_db($baza);
Db::run("UPDATE password_resets SET expires_at = :kiedys WHERE used_at IS NULL",
    ['kiedys' => Stats::now('-1 minute')]);
$przedawniony = http('GET', '/haslo/reset/' . $swiezy);
check('przedawniony odnośnik daje to samo 404', $przedawniony['status'] === 404,
    'status ' . $przedawniony['status']);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
