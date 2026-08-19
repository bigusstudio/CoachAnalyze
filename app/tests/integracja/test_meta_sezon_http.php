<?php
declare(strict_types=1);

/**
 * Meta meczu: sezon i edycja po fakcie — PRZELOT HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. Sezon wybrany przy imporcie ZAPISUJE SIĘ i widać go w kolumnie listy
 *    meczów, a filtr po sezonie faktycznie zawęża wynik.
 * 2. Metę da się poprawić PO IMPORCIE — to była realna luka: mecz wgrany bez
 *    daty zostawał bez daty i bez sezonu na zawsze, bo formularz istniał
 *    wyłącznie jako krok przed diffem. Stąd puste kolumny „Sezon".
 * 3. Edycja zmienia datę, sezon i wynik, a przy istniejącym raporcie mówi
 *    wprost, że nagłówek odświeży się dopiero przy „Przelicz".
 * 4. Pusty wybór rywala przy edycji NIE kasuje przypisania — przy imporcie
 *    znaczy „jeszcze nie wiem", przy poprawce znaczyłby „usuń", o co nikt
 *    nie prosił.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_meta_sezon_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\Seasons;

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

$baza    = $here . '/metasezon.sqlite';
$magazyn = $here . '/metasezon_storage';
$logFile = $here . '/metasezon.log';
$envFile = $here . '/.env.metasezon';
$sock    = $here . '/metasezon_redis.sock';
$port    = 9006;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=meta:',
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

$CSV = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n";

// ============================================================ A. schema
echo "== A. kolumna sezonu istnieje — migracja nie jest potrzebna ==\n";

$kolumny = array_column(Db::all('PRAGMA table_info(matches)'), 'name');
check('matches ma kolumnę season_id', in_array('season_id', $kolumny, true),
    'jest w migracji 001, nie trzeba dokładać nowej');

$migracje = glob($root . '/app/migrations/*.sql') ?: [];
$numery = [];
foreach ($migracje as $plik) {
    if (preg_match('/(\d{3})_/', basename($plik), $m) === 1) { $numery[] = (int) $m[1]; }
}
check('najwyższa migracja to 013', $numery !== [] && max($numery) === 13,
    'stan: ' . implode(', ', $numery));
check('nie dołożono migracji dla sezonu', !in_array(14, $numery, true),
    'kolumna już jest — pusta migracja tylko zaśmieciłaby historię');

// ============================================================ B. import z sezonem
echo "\n== B. sezon wybrany przy imporcie zapisuje się ==\n";

$konfig = [
    'schema_version'   => 1,
    'team_us_rule'     => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans'],
    'variables' => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans'], 'visible' => true],
    ],
];
ReportTemplates::saveNewVersion(1, $konfig, 1);

$login = http('GET', '/login');
check('zalogowano', http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]])['status'] === 302);

// Drugi sezon, żeby filtr miał co odsiewać.
ca_test_db($baza);
$sezonStary = Seasons::create(1, [
    'label' => '2025/2026', 'date_from' => '2025-07-01', 'date_to' => '2026-06-30',
]);
$sezonBiezacy = (int) Db::one("SELECT id FROM seasons WHERE label = '2026/2027'")['id'];
check('są dwa sezony do wyboru', $sezonStary > 0 && $sezonBiezacy > 0);

$formularz = http('GET', '/klub/1/import');
check('upload przyjęty', http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => csrfZ($formularz['body'])], ['csv' => ['mecz.csv', $CSV]]
)])['status'] === 302);
check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$importId = (int) $import['id'];
$matchId  = (int) $import['match_id'];

$meta = http('GET', '/import/' . $importId . '/meta');
check('formularz mety odpowiada', $meta['status'] === 200);
check('jest pole wyboru sezonu', str_contains($meta['body'], 'name="season_id"'));
check('lista sezonów zawiera oba', str_contains($meta['body'], '2026/2027')
    && str_contains($meta['body'], '2025/2026'));

/*
 * PODPOWIEDŹ SEZONU: mecz bez daty dostaje sezon bieżący. Sprawdzamy, że
 * któryś sezon jest zaznaczony — pusty wybór był powodem, dla którego kolumna
 * „Sezon" bywała pusta mimo działającego modelu sezonów.
 */
check('jakiś sezon jest zaznaczony domyślnie',
    preg_match('/<option value="\d+"\s*selected/', $meta['body']) === 1,
    'bez podpowiedzi operator zostawia „wykryj z daty" i przy braku daty nie ma nic');

http('POST', '/import/' . $importId . '/meta', ['form' => [
    'csrf' => csrfZ($meta['body']),
    'nowy_rywal' => 'GKS Sezonowy',
    'played_at'  => '2025-10-12',
    'season_id'  => (string) $sezonStary,
    'score_us'   => '2',
    'score_them' => '1',
]]);

ca_test_db($baza);
$mecz = Db::one('SELECT * FROM matches WHERE id = :m', ['m' => $matchId]);
check('sezon zapisany', (int) $mecz['season_id'] === $sezonStary,
    'season_id: ' . var_export($mecz['season_id'], true));
check('data zapisana', substr((string) $mecz['played_at'], 0, 10) === '2025-10-12');

// ============================================================ C. lista i filtr
echo "\n== C. lista meczów pokazuje sezon, filtr działa ==\n";

$lista = http('GET', '/mecze');
check('lista meczów odpowiada', $lista['status'] === 200);
check('kolumna sezonu NIE jest pusta', str_contains($lista['body'], '2025/2026'),
    'to była pierwotna obserwacja: wszędzie „—"');
check('data widoczna', str_contains($lista['body'], '2025-10-12'));

$filtrTrafiony = http('GET', '/mecze?sezon=' . $sezonStary);
check('filtr po właściwym sezonie pokazuje mecz',
    str_contains($filtrTrafiony['body'], '2025-10-12'));

$filtrPusty = http('GET', '/mecze?sezon=' . $sezonBiezacy);
check('filtr po innym sezonie mecz odsiewa',
    !str_contains($filtrPusty['body'], '2025-10-12'),
    'filtr ma zawężać, a nie tylko wyglądać');

check('lista prowadzi do edycji mety',
    str_contains($lista['body'], '/mecze/' . $matchId . '/meta'),
    'bez wejścia z listy operator nie ma jak uzupełnić pustych kolumn');

// ============================================================ D. edycja po fakcie
echo "\n== D. edycja mety istniejącego meczu ==\n";

$edycja = http('GET', '/mecze/' . $matchId . '/meta');
check('ekran edycji odpowiada', $edycja['status'] === 200, 'status ' . $edycja['status']);
check('formularz kieruje na trasę edycji',
    str_contains($edycja['body'], 'action="/mecze/' . $matchId . '/meta"'));
check('pola są wypełnione obecnymi wartościami',
    str_contains($edycja['body'], 'value="2025-10-12"')
    && preg_match('/name="score_us"[^>]*value="2"/', $edycja['body']) === 1);
check('obecny sezon jest zaznaczony',
    preg_match('/<option value="' . $sezonStary . '"\s*selected/', $edycja['body']) === 1);

// Bez raportu nie ma o czym uprzedzać.
check('bez raportu nie ma noty o przeliczeniu',
    !str_contains($edycja['body'], 'Przelicz'),
    'rada o czymś, czego nie ma, jest tylko szumem');

$zapis = http('POST', '/mecze/' . $matchId . '/meta', ['form' => [
    'csrf'       => csrfZ($edycja['body']),
    'played_at'  => '2026-09-20',
    'season_id'  => (string) $sezonBiezacy,
    'score_us'   => '3',
    'score_them' => '0',
    'is_home'    => '1',
    'competition' => 'IV liga',
    // Rywal celowo pusty — sprawdzamy, że edycja go NIE kasuje.
    'club_away_id' => '',
]]);
check('zapis edycji przekierowuje', $zapis['status'] === 302, (string) $zapis['location']);

ca_test_db($baza);
$po = Db::one('SELECT * FROM matches WHERE id = :m', ['m' => $matchId]);
check('data zmieniona', substr((string) $po['played_at'], 0, 10) === '2026-09-20');
check('sezon zmieniony', (int) $po['season_id'] === $sezonBiezacy,
    'season_id: ' . var_export($po['season_id'], true));
check('wynik zmieniony', (int) $po['score_us'] === 3 && (int) $po['score_them'] === 0);
check('gdzie grany zapisane', (int) $po['is_home'] === 1);
check('rozgrywki zapisane', (string) $po['competition'] === 'IV liga');

$rywal = Db::one("SELECT id FROM clubs WHERE name = 'GKS Sezonowy'");
check('pusty wybór rywala NIE skasował przypisania',
    $rywal !== null && (int) $po['club_away_id'] === (int) $rywal['id'],
    'przy edycji pusty select znaczyłby „usuń", o co nikt nie prosił');

$listaPo = http('GET', '/mecze?sezon=' . $sezonBiezacy);
check('mecz przeniósł się do nowego sezonu na liście',
    str_contains($listaPo['body'], '2026-09-20'));

// ============================================================ E. nota o raporcie
echo "\n== E. przy istniejącym raporcie: nota o „Przelicz” ==\n";

$diff = http('GET', '/import/' . $importId . '/diff');
if ($diff['status'] === 200) {
    preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $diff['body'], $mm);
    $dec = [];
    foreach (array_unique($mm[1]) as $k) { $dec[$k] = \CoachAnalyze\TemplateDiff::POMIN; }
    http('POST', '/import/' . $importId . '/diff', ['form' => [
        'csrf' => csrfZ($diff['body']), 'decyzja' => $dec,
    ]]);
}
$pokrycie = http('GET', '/import/' . $importId);
http('POST', '/import/' . $importId . '/generuj', ['form' => ['csrf' => csrfZ($pokrycie['body'])]]);
check('cron wygenerował raport', cron() === 0);

ca_test_db($baza);
check('raport istnieje',
    Db::one('SELECT id FROM reports WHERE match_id = :m', ['m' => $matchId]) !== null);

$edycja2 = http('GET', '/mecze/' . $matchId . '/meta');
check('przy raporcie ekran uprzedza o nagłówku',
    str_contains($edycja2['body'], 'Przelicz'),
    'meta nie wpływa na liczby, ale nagłówek gotowego HTML-a zostaje stary');

$zapis2 = http('POST', '/mecze/' . $matchId . '/meta', ['form' => [
    'csrf' => csrfZ($edycja2['body']),
    'played_at' => '2026-09-21', 'season_id' => (string) $sezonBiezacy,
]]);
check('zapis przy raporcie przechodzi', $zapis2['status'] === 302);

$poZapisie = http('GET', (string) $zapis2['location']);
check('komunikat mówi o „Przelicz", nie o utracie danych',
    str_contains($poZapisie['body'], 'Przelicz'));
check('komunikat mówi, że liczby się nie zmieniły',
    str_contains($poZapisie['body'], 'nie zmieniły'));

// ============================================================ F. wejścia
echo "\n== F. wejścia do edycji z trzech miejsc ==\n";

check('z widoku pokrycia',
    str_contains(http('GET', '/import/' . $importId)['body'], '/mecze/' . $matchId . '/meta'));
check('z huba klubu',
    str_contains(http('GET', '/klub/1')['body'], '/mecze/' . $matchId . '/meta'));
check('z historii meczu',
    str_contains(http('GET', '/mecze/' . $matchId . '/historia')['body'], '/mecze/' . $matchId . '/meta'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
