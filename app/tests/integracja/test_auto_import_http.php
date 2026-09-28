<?php
declare(strict_types=1);

/**
 * Import bez tarcia — PRZELOT HTTP (sesja 8 pivotu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. WGRANIE EKSPORTU WYSTARCZY, ŻEBY ZOBACZYĆ RAPORT. Sześć nowych tagów
 *    daje sześć nowych zmiennych, a przycisk „Generuj" działa BEZ wejścia na
 *    ekran różnic. Zasada pivotu mówi: zmienna to surowa nazwa tagu, więc
 *    ekran pytający o każdy tag pyta o coś rozstrzygniętego z góry.
 * 2. PO IMPORCIE NIC NIE ZOSTAJE POZA ANALIZĄ. „Zdarzeń poza analizą: 47"
 *    przy komplecie tagów w templacie było nieprawdą o raporcie — liczba
 *    pochodziła z warstwy kanonicznej, która w pivocie jest uśpiona.
 * 3. NIEZNANY RYWAL ZAKŁADA SIĘ SAM i podpina do meczu, ze śladem w `details`,
 *    żeby operator wiedział, że nazwa przyszła z pliku.
 * 4. TEN SAM RYWAL INNĄ WIELKOŚCIĄ LITER TO TEN SAM KLUB. Dopasowanie idzie
 *    przez RÓWNOŚĆ po normalizacji — nigdy przez fragment (pułapka 7 dotyczy
 *    etykiet, ale „Pogoń" wewnątrz „Pogoń II" kosztowałoby tu historię meczów).
 *
 * Czego ten zestaw NIE dubluje: poprawiania decyzji w rewizji i wyrzucania
 * zmiennej z templatu — to jest w `test_import_n1_http.php`.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_auto_import_http.php
 */

use CoachAnalyze\AutoImport;
use CoachAnalyze\Clubs;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\ReportTemplates;

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

$baza    = $here . '/auto_import.sqlite';
$magazyn = $here . '/auto_import_storage';
$logFile = $here . '/auto_import.log';
$envFile = $here . '/.env.auto_import';
$sock    = $here . '/auto_import_redis.sock';
$port    = 9051;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=auto:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 = tenant „Klub A"
/*
 * ADMINISTRATOR, NIE ANALITYK (golden layout W0). Ten zestaw sprawdza mechanikę
 * pracy w tle — stronę zadania, wskaźnik, kolumny wersji — a te od W0 widzi
 * wyłącznie rola `admin`. Analityk dostaje kartę meczu; to sprawdza
 * `test_golden_w0_http.php`.
 */
\CoachAnalyze\Db::run("UPDATE users SET role = 'admin' WHERE email = 'operator@example.com'");

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

/**
 * Eksport: nasza drużyna pod nazwą z pliku + rywal podany z zewnątrz.
 *
 * SZEŚĆ NOWYCH TAGÓW I ANI JEDNEJ ETYKIETY. Etykieta też zakłada zmienną,
 * więc kolumna `labels` wypełniona rozmyłaby asercję „sześć tagów → sześć
 * zmiennych" w „sześć tagów i kilka etykiet → ile właściwie?".
 */
function eksport(string $rywal): string
{
    $wiersze = [
        ['STRZAŁ',          '10',  '20',  'KLUB A', '', 'X 0,5', '80', '30'],
        ['STRATA',          '30',  '40',  $rywal,   '', '',      '50', '30'],
        ['PRESSING WYSOKI', '50',  '60',  'KLUB A', '', '',      '60', '25'],
        ['SBZ PODAJĄCY',    '70',  '80',  'KLUB A', '', '',      '85', '33'],
        ['DOŚRODKOWANIE',   '90',  '100', 'KLUB A', '', '',      '80', '10'],
        ['ODBIÓR',          '110', '120', $rywal,   '', '',      '40', '20'],
        ['TRANSFORMACJA',   '130', '140', 'KLUB A', '', '',      '55', '35'],
        ['III STREFA',      '150', '160', 'KLUB A', '', '',      '',   ''],
    ];

    $out = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n";
    foreach ($wiersze as $w) {
        $fh = fopen('php://memory', 'r+');
        fputcsv($fh, $w);
        rewind($fh);
        $out .= (string) stream_get_contents($fh);
        fclose($fh);
    }
    return $out;
}

const NOWE_TAGI = [
    'PRESSING WYSOKI', 'SBZ PODAJĄCY', 'DOŚRODKOWANIE',
    'ODBIÓR', 'TRANSFORMACJA', 'III STREFA',
];

// ---------------------------------------------------------------- przygotowanie
echo "== klub z templatem, rywal spoza bazy ==\n";

$konfig = [
    'schema_version' => 1,
    'team_us_rule'   => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'tl_bilans', 'mapy', 'duels', 'noteam'],
    'variables' => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
        ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRATA'],
         'canon' => 'loss', 'display_label' => 'Straty', 'color' => '#8899AA',
         'sections' => ['bilans', 'tl_bilans'], 'visible' => true],
    ],
];
check('klub ma templat v1', ReportTemplates::saveNewVersion(1, $konfig, 1) === 1);

$klubowPrzed = (int) Db::one('SELECT COUNT(*) AS c FROM clubs')['c'];
check('„LKS Wilki" nie istnieje przed importem',
    Clubs::matchByExportName('LKS Wilki') === null);

$login = http('GET', '/login');
$zal = http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]]);
check('zalogowano', $zal['status'] === 302);

// ---------------------------------------------------------------- import 1
echo "\n== import: nic poza wgraniem pliku ==\n";

$formularz = http('GET', '/klub/1/import');
$upload = http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => csrfZ($formularz['body'])], ['csv' => ['mecz1.csv', eksport('LKS WILKI')]]
)]);
check('upload przyjęty', $upload['status'] === 302, (string) $upload['location']);
check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$importId = (int) $import['id'];
$matchId = (int) $import['match_id'];

// ---------------------------------------------------------------- rywal
echo "\n== rywal założony z nazwy w eksporcie ==\n";

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * NIEZNANY RYWAL BYŁ DRUGIM ZATRZYMANIEM IMPORTU.
 *
 * Operator widział „nie rozpoznano drużyny" i przycisk „Załóż klub", który
 * otwierał formularz z jednym sensownym wypełnieniem: nazwą z pliku. Pytanie
 * o coś, co wiadomo, kosztuje dwa kliknięcia i uczy klikać bez czytania.
 *
 * Klub powstaje więc sam, a operator dostaje ślad („klub założony
 * automatycznie") i odsyłacz do uzupełnienia skrótu i herbu.
 * ═══════════════════════════════════════════════════════════════════════════
 */
$rywal = Clubs::matchByExportName('LKS WILKI');
check('rywal powstał sam, bez formularza', $rywal !== null);
check('rywal ma is_own_team = 0',
    $rywal !== null && (int) $rywal['is_own_team'] === 0,
    'tenant z automatu byłby błędem, którego nikt nie zauważy do porównań sezonowych');
check('nazwa z eksportu trafiła do aliasów',
    $rywal !== null && str_contains((string) $rywal['aliases_json'], 'LKS WILKI'));
check('klub niesie ślad, że powstał automatycznie',
    $rywal !== null && isset(Clubs::decodeDetails($rywal['details'] ?? null)[AutoImport::ZNACZNIK_AUTO]));
check('barwa z kolejki, nie pusta',
    $rywal !== null && in_array((string) $rywal['color_primary'], AutoImport::BARWY, true),
    'barwa: ' . var_export($rywal['color_primary'] ?? null, true));
check('dziennik notuje założenie klubu',
    Db::one("SELECT id FROM audit_log WHERE action = 'club.auto'") !== null);

$mecz = Db::one('SELECT * FROM matches WHERE id = :m', ['m' => $matchId]);
check('mecz wskazuje rywala przez club_away_id',
    $rywal !== null && (int) $mecz['club_away_id'] === (int) $rywal['id'],
    'club_away_id: ' . var_export($mecz['club_away_id'], true));
check('nasza drużyna przypisana z eksportu',
    (int) $mecz['club_home_id'] === 1);

// ---------------------------------------------------------------- zmienne
echo "\n== sześć nowych tagów → sześć nowych zmiennych ==\n";

check('powstała wersja 2 templatu', ReportTemplates::currentVersion(1) === 2,
    'wersja: ' . ReportTemplates::currentVersion(1));

$config = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
$nazwy = array_column(array_column($config['variables'], 'source'), 'raw');

foreach (NOWE_TAGI as $tag) {
    check("tag {$tag} jest w templacie bez kliknięcia", in_array($tag, $nazwy, true));
}
check('dokładnie sześć zmiennych przybyło',
    count($nazwy) === 2 + count(NOWE_TAGI),
    'zmiennych: ' . count($nazwy) . ' — ' . implode(', ', $nazwy));
check('wersja automatyczna NIE unieważnia raportów na v1',
    ReportTemplates::isOutdated(1, 1) === false);

// ---------------------------------------------------------------- pokrycie
echo "\n== ekran pokrycia: zero pytań, zero zdarzeń poza analizą ==\n";

$pokrycie = http('GET', '/import/' . $importId);
check('pokrycie odpowiada OD RAZU — bez bramki meta i bez bramki diffu',
    $pokrycie['status'] === 200,
    $pokrycie['status'] . ' → ' . (string) $pokrycie['location']);
check('pokrycie mówi, że klub powstał automatycznie',
    str_contains($pokrycie['body'], 'klub założony automatycznie'));
check('pokrycie wymienia zmienne dodane automatycznie',
    str_contains($pokrycie['body'], 'Nowe zmienne dodane automatycznie')
    && str_contains($pokrycie['body'], 'TRANSFORMACJA'));
check('„Załóż klub" zniknął — nie ma czego zakładać',
    !str_contains($pokrycie['body'], 'kluby/nowy?nazwa='));

/*
 * ZERO POZA ANALIZĄ. Do sesji 8 ta liczba brała się z `coverage.unanalysed`,
 * czyli ze zdarzeń bez POJĘCIA KANONICZNEGO — a pojęcie jest w pivocie puste
 * z założenia. Klub z kompletem tagów w templacie widział więc „poza analizą"
 * komplet swoich zdarzeń, co było nieprawdą o raporcie, który je pokazywał.
 */
$raportPokrycia = Imports::report(Imports::find($importId));
// Golden layout W5: pokrycie w ogóle nie liczy „poza analizą" — nic z pliku
// nie wypada z raportu, więc nie ma czego liczyć.
check('pokrycie bez licznika „poza analizą" (W5)',
    $raportPokrycia['excluded']['count'] === null,
    var_export($raportPokrycia['excluded']['count'], true));
check('lista tagów poza analizą pusta',
    $raportPokrycia['excluded']['unrecognised'] === []
    && $raportPokrycia['excluded']['ignored'] === [],
    implode(',', $raportPokrycia['excluded']['unrecognised']));
check('ekran mówi to wprost',
    str_contains($pokrycie['body'], 'Każdy tag z eksportu ma zmienną w słowniku klubu'));

// ---------------------------------------------------------------- generowanie
echo "\n== raport bez ani jednego kliknięcia w diffie ==\n";

check('ekran różnic nie był odwiedzony', empty($import['diff_done_at']),
    'ta asercja pilnuje, żeby test nie udowadniał czegoś innego, niż obiecuje');

$generuj = http('POST', '/import/' . $importId . '/generuj',
    ['form' => ['csrf' => csrfZ($pokrycie['body'])]]);
check('generowanie przyjęte, BEZ odbicia na /diff',
    $generuj['status'] === 302
    && preg_match('#^/zadania/(\d+)$#', (string) $generuj['location']) === 1,
    (string) $generuj['location']);
check('cron wygenerował raport', cron() === 0);

ca_test_db($baza);
$raport = Db::one('SELECT * FROM reports ORDER BY id DESC LIMIT 1');
check('raport zapisany', $raport !== null);
check('plik raportu powstał',
    $raport !== null && is_file((string) $raport['html_path']));
check('raport niesie wersję templatu v2',
    $raport !== null && (int) $raport['template_version'] === 2,
    'template_version: ' . var_export($raport['template_version'] ?? null, true));

// ---------------------------------------------------------------- import 2
echo "\n== drugi mecz z tym samym rywalem, inna wielkość liter ==\n";

/*
 * „lks wilki" TO TEN SAM KLUB CO „LKS WILKI".
 *
 * Dopasowanie idzie przez RÓWNOŚĆ po normalizacji (wielkość liter, spacje,
 * łączniki). Drugi wiersz w `clubs` rozbiłby historię meczów na dwa kluby
 * o tej samej nazwie, a naprawa znaczyłaby ręczne scalanie — po miesiącach,
 * gdy nikt już nie pamięta, który mecz jest w którym.
 */
$formularz2 = http('GET', '/klub/1/import');
$upload2 = http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => csrfZ($formularz2['body'])], ['csv' => ['mecz2.csv', eksport('lks  wilki')]]
)]);
check('drugi upload przyjęty', $upload2['status'] === 302);
check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import2 = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$mecz2 = Db::one('SELECT * FROM matches WHERE id = :m', ['m' => (int) $import2['match_id']]);

check('NIE powstał drugi klub',
    (int) Db::one('SELECT COUNT(*) AS c FROM clubs')['c'] === $klubowPrzed + 1,
    'klubów: ' . Db::one('SELECT COUNT(*) AS c FROM clubs')['c']);
check('drugi mecz wskazuje TEN SAM klub rywala',
    $rywal !== null && (int) $mecz2['club_away_id'] === (int) $rywal['id'],
    'club_away_id: ' . var_export($mecz2['club_away_id'], true));
check('drugi import nie zakłada zmiennych po raz drugi',
    ReportTemplates::currentVersion(1) === 2,
    'wersja różniąca się wyłącznie numerem to szum w historii');

$pokrycie2 = http('GET', '/import/' . (int) $import2['id']);
check('drugi import też idzie prosto na pokrycie', $pokrycie2['status'] === 200,
    $pokrycie2['status'] . ' → ' . (string) $pokrycie2['location']);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
