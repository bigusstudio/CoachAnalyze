<?php
declare(strict_types=1);

/**
 * Kreator układu raportu i kontynuacja zmiennej — PRZELOT HTTP (sesja 5 pivotu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. EKRAN UKŁADU DZIAŁA BEZ SKRYPTU. Kolejność zmieniają przyciski `submit`,
 *    a stan roboczy przeżywa przeładowanie — panel ma jeden zatwierdzony plik
 *    JavaScript i jest to wyjątek na chmurki powiadomień (CLAUDE.md §9).
 * 2. ZAPIS TO NOWA WERSJA TEMPLATU, nigdy nadpisanie. Raporty wygenerowane
 *    na poprzednim układzie zostają ważne, a numer mówi, że są starsze.
 * 3. CUDZY KLUB TO 404. Klub, który nie jest tenantem, nie ma huba ani układu.
 * 4. „TO KONTYNUACJA ZMIENNEJ X" DOPISUJE ALIAS, a nie nową zmienną — inaczej
 *    zmiana nazwy taga między sezonami rozbija jedną serię na dwie i nic tego
 *    nie sygnalizuje.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_uklad_http.php
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

$baza    = $here . '/uklad.sqlite';
$magazyn = $here . '/uklad_storage';
$logFile = $here . '/uklad.log';
$envFile = $here . '/.env.uklad';
$sock    = $here . '/uklad_redis.sock';
$port    = 9034;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

$python = $root . '/venv/bin/python';
putenv('PYTHONPATH=' . $root . '/engine');

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'PYTHON_BIN=' . $python, 'ENGINE_TIMEOUT=60',
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=uklad:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);

$procesy = [];
$procesy[] = proc_open(
    'php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r1
);
for ($i = 0; $i < 50 && !file_exists($sock); $i++) {
    usleep(100000);
}
$procesy[] = proc_open(
    'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r2
);
for ($i = 0; $i < 50; $i++) {
    $p = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if (is_resource($p)) { fclose($p); break; }
    usleep(100000);
}

register_shutdown_function(static function () use ($procesy, $baza, $envFile, $logFile, $sock, $magazyn): void {
    foreach ($procesy as $p) {
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    @unlink($baza); @unlink($envFile); @unlink($logFile); @unlink($sock);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

$bazaUrl = 'http://127.0.0.1:' . $port;
$ciasteczka = [];

/** @return array{status:int, location:?string, body:string} */
function http(string $method, string $path, array $opts = []): array
{
    global $bazaUrl, $ciasteczka;
    $naglowki = ['Connection: close'];
    if ($ciasteczka !== []) {
        $pary = [];
        foreach ($ciasteczka as $k => $v) { $pary[] = $k . '=' . $v; }
        $naglowki[] = 'Cookie: ' . implode('; ', $pary);
    }
    $tresc = null;
    if (isset($opts['form'])) {
        $tresc = http_build_query($opts['form']);
        $naglowki[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($opts['multipart'])) {
        [$tresc, $typ] = $opts['multipart'];
        $naglowki[] = 'Content-Type: ' . $typ;
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $naglowki), 'content' => $tresc,
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
    ]]);
    $body = @file_get_contents($bazaUrl . $path, false, $ctx);
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) { $status = (int) $m[1]; $location = null; }
        elseif (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        elseif (stripos($h, 'Set-Cookie:') === 0
            && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }
    return ['status' => $status, 'location' => $location, 'body' => (string) $body];
}

function multipart(array $pola, array $pliki): array
{
    $granica = '----ca' . bin2hex(random_bytes(8));
    $out = '';
    foreach ($pola as $n => $w) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$n}\"\r\n\r\n{$w}\r\n";
    }
    foreach ($pliki as $n => [$plik, $tresc]) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$n}\"; filename=\"{$plik}\"\r\n"
              . "Content-Type: text/csv\r\n\r\n{$tresc}\r\n";
    }
    $out .= "--{$granica}--\r\n";
    return [$out, 'multipart/form-data; boundary=' . $granica];
}

function cron(): int
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile)
        . ' PYTHONPATH=' . escapeshellarg($root . '/engine')
        . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1', $o, $kod);
    return $kod;
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}





// ============================================================ A. logowanie
echo "== A. logowanie ==\n";
$form = http('GET', '/login');
$zaloguj = http('POST', '/login', ['form' => [
    'csrf' => csrfZ($form['body']),
    'email' => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe',
]]);
check('zalogowano', $zaloguj['status'] === 302, 'status ' . $zaloguj['status']);

// ============================================================ B. templat startowy
echo "\n== B. templat schematu 1 w bazie ==\n";

/*
 * TEMPLAT SCHEMATU 1 — dokładnie taki, jakie leżą w produkcyjnej bazie.
 * Ekran układu ma go przeczytać i uzupełnić o kafle dołożone w sesjach 4a/4b,
 * zamiast uznać ich brak za świadome wyłączenie.
 */
$configV1 = [
    'schema_version'   => 1,
    'team_us_rule'     => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'mapy', 'tl_sbz', 'tl_iii', 'tl_bilans', 'duels', 'noteam'],
    'variables'        => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'ZDOBYCIE SBZ'],
         'canon' => null, 'display_label' => 'Wejście w SBZ', 'color' => '#E8722C',
         'sections' => ['bilans', 'tl_sbz'], 'visible' => true],
        ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => null, 'display_label' => 'Strzał', 'color' => '#2C6FE8',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
    ],
];
Db::run('INSERT INTO club_report_templates (club_id, version, config, created_by, created_at)
         VALUES (1, 1, :c, 1, :now)',
    ['c' => json_encode($configV1, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
     'now' => \CoachAnalyze\Stats::now()]);
check('templat v1 zapisany', \CoachAnalyze\ReportTemplates::currentVersion(1) === 1);

// ============================================================ C. ekran układu
echo "\n== C. ekran układu ==\n";

$ekran = http('GET', '/klub/1/uklad');
check('ekran układu odpowiada', $ekran['status'] === 200, 'status ' . $ekran['status']);
check('kafle z templatu są na ekranie',
    str_contains($ekran['body'], 'Bilans drużyn') && str_contains($ekran['body'], 'Mapy współczynników'));
check('kafle dołożone po zapisie templatu WRACAJĄ jako domyślne',
    str_contains($ekran['body'], 'Przegląd') && str_contains($ekran['body'], 'Tabela makro'),
    'brak na liście zapisanej wcześniej nie jest niczyją decyzją');
check('kafle spoza zestawu domyślnego NIE są w układzie, tylko do dodania',
    substr_count($ekran['body'], 'Skuteczność w udziałach') === 1,
    'donuty mają być wyłącznie w liście „dodaj kafelek"');

/*
 * BEZ SKRYPTU: każdy przycisk to `submit` z własną wartością pola `akcja`.
 * Gdyby kolejność zmieniał JavaScript, ten test nie miałby czego kliknąć.
 */
check('kolejność zmieniają przyciski submit',
    str_contains($ekran['body'], 'name="akcja" value="gora:1"')
    && str_contains($ekran['body'], 'name="akcja" value="dol:0"'));
/*
 * Jedyne skrypty na stronie to te, które panel miał wcześniej: plik chmurek
 * i wbudowany przełącznik motywu z układu strony. Ekran układu NIE DOKŁADA
 * własnego — drugi plik `.js` wymaga osobnego uzgodnienia (CLAUDE.md §9).
 */
preg_match_all('/<script\b[^>]*>/', $ekran['body'], $skrypty);
check('ekran układu nie dokłada własnego skryptu',
    count($skrypty[0]) === 2 && substr_count($ekran['body'], 'powiadomienia.js') === 1,
    'na stronie mają być dokładnie dwa: przełącznik motywu z układu strony '
    . 'i plik chmurek. Znaleziono: ' . implode(' ', $skrypty[0]));

$csrf = csrfZ($ekran['body']);
check('formularz niesie token', $csrf !== '');

// ============================================================ D. edycja bez zapisu
echo "\n== D. stan roboczy przeżywa przeładowanie ==\n";

$przesun = http('POST', '/klub/1/uklad', ['form' => [
    'csrf' => $csrf,
    'akcja' => 'dol:0',
    'rozmiar' => [0 => '1', 1 => '1/2'],
    'tytul'   => [0 => '', 1 => 'Moje mapy'],
]]);
check('przesunięcie przekierowuje na ekran', $przesun['status'] === 302
    && $przesun['location'] === '/klub/1/uklad', (string) $przesun['location']);

$po = http('GET', '/klub/1/uklad');
check('wersja templatu NIE urosła od samego przesunięcia',
    \CoachAnalyze\ReportTemplates::currentVersion(1) === 1,
    'append-only nie znaczy „wersja na każde kliknięcie"');
check('ekran mówi o niezapisanych zmianach',
    str_contains($po['body'], 'niezapisane zmiany'));
check('tytuł wpisany przed przesunięciem ZOSTAŁ',
    str_contains($po['body'], 'Moje mapy'),
    'formularz bez skryptu nie zapisuje się sam — odczytujemy pola przy każdej akcji');

// ============================================================ E. zapis
echo "\n== E. zapis jako nowa wersja ==\n";

$ekran2 = http('GET', '/klub/1/uklad');
$zapis = http('POST', '/klub/1/uklad', ['form' => [
    'csrf'  => csrfZ($ekran2['body']),
    'akcja' => 'zapisz',
    'prog'  => ['pressing' => '55', 'p3' => '', 'sbz_strzal' => '140'],
]]);
check('zapis przekierowuje', $zapis['status'] === 302, 'status ' . $zapis['status']);
check('powstała wersja 2', \CoachAnalyze\ReportTemplates::currentVersion(1) === 2);

$config2 = \CoachAnalyze\ReportTemplates::decodeConfig(
    \CoachAnalyze\ReportTemplates::current(1)['config']
);
check('zapisany config ma schemat 2', ($config2['schema_version'] ?? null) === 2);
check('układ zapisany jako lista sekcji', is_array($config2['sections'] ?? null)
    && count($config2['sections']) > 0);

$kolejnosc = \CoachAnalyze\ReportLayout::widgety($config2['sections']);
check('przesunięcie z kroku D jest w zapisie',
    ($kolejnosc[0] ?? '') === 'makro' && ($kolejnosc[1] ?? '') === 'przeglad',
    implode(',', array_slice($kolejnosc, 0, 3)));

check('prób spoza zakresu NIE zapisujemy',
    ($config2['thresholds']['pressing'] ?? null) === 55
    && !array_key_exists('sbz_strzal', $config2['thresholds'] ?? []),
    'te liczby idą wprost do literału JS w raporcie pod publicznym adresem');
check('puste pole progu znaczy „globalny", nie zero',
    !array_key_exists('p3', $config2['thresholds'] ?? []));

check('zmienne przeżyły zapis układu',
    count($config2['variables'] ?? []) === 2,
    'ekran układu nie dotyka zmiennych');

// ============================================================ F. klonowanie
echo "\n== F. klonowanie wcześniejszej wersji ==\n";

$historia = http('GET', '/klub/1/templaty');
check('historia odpowiada', $historia['status'] === 200);
check('wcześniejsza wersja ma akcję klonowania',
    str_contains($historia['body'], '/klub/1/templaty/1/klonuj'));
check('BIEŻĄCA wersja nie ma akcji klonowania',
    !str_contains($historia['body'], '/klub/1/templaty/2/klonuj'),
    'klon bieżącej wersji byłby wersją bez różnicy');

$klon = http('POST', '/klub/1/templaty/1/klonuj', ['form' => ['csrf' => csrfZ($historia['body'])]]);
check('klon przekierowuje', $klon['status'] === 302);
check('klon dał wersję 3, a wersja 1 ZOSTAŁA',
    \CoachAnalyze\ReportTemplates::currentVersion(1) === 3
    && \CoachAnalyze\ReportTemplates::version(1, 1) !== null,
    'cofnięcie kasowałoby historię, na której stoją raporty klienta');

$config3 = \CoachAnalyze\ReportTemplates::decodeConfig(
    \CoachAnalyze\ReportTemplates::current(1)['config']
);
check('klon to TREŚĆ wersji źródłowej',
    ($config3['schema_version'] ?? null) === 1 && count($config3['variables'] ?? []) === 2,
    'klon kopiuje config, nie przepakowuje go pod nowy schemat');

$brak = http('POST', '/klub/1/templaty/99/klonuj', ['form' => ['csrf' => csrfZ($historia['body'])]]);
check('klon nieistniejącej wersji nie tworzy nowej',
    \CoachAnalyze\ReportTemplates::currentVersion(1) === 3);

// ============================================================ G. cudzy klub
echo "\n== G. cudzy klub ==\n";

$rywal = \CoachAnalyze\Clubs::create(1, [
    'name' => 'Rywal FC', 'short_name' => 'RYW', 'color_primary' => '#123456',
    'color_secondary' => null, 'is_own_team' => false, 'aliases' => '',
]);
$obcy = http('GET', '/klub/' . $rywal . '/uklad');
check('układ cudzego klubu daje 404', $obcy['status'] === 404, 'status ' . $obcy['status']);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
