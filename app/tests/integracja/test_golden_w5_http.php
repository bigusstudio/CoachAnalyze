<?php
declare(strict_types=1);

/**
 * Golden layout W5 — ZASADA NADRZĘDNA na pełnym przebiegu (panel + silnik).
 *
 * Eksport „na wzór Hetmana" (raport 30, mecz 27) jest SYNTETYCZNY — pliki
 * klienta nie trafiają do repozytorium (CLAUDE.md §7). Templat klubu jak
 * Pogoń v9: zmienne tylko w bilansie, osie i pojedynki bez przypisanych
 * zmiennych, ASYSTA na liście „nie pytaj" (club_ignored_tags).
 *
 *   1. pokrycie (ekran [op] i zapis importu) = lista sekcji raportu,
 *   2. „poza analizą" / „pominięte" nie występują w pokryciu; ASYSTA liczona,
 *   4. baner informacyjny regenerowanego raportu z listy zapisanej przy imporcie,
 *   7. zawodnicy z CSV = pozycje na ekranie Zawodnicy (nasi + rywal).
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_golden_w5_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\Rebuilds;
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

$baza    = $here . '/golden_w5.sqlite';
$magazyn = $here . '/golden_w5_storage';
$logFile = $here . '/golden_w5.log';
$envFile = $here . '/.env.golden_w5';
$sock    = $here . '/golden_w5_redis.sock';
$port    = 9101;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);
mkdir($magazyn . '/reports', 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'PYTHON_BIN=' . $root . '/venv/bin/python', 'ENGINE_TIMEOUT=60',
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=golden_w5:', 'HTML_TEMPLATE=v21', 'CA_HTML_TEMPLATE=v21',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);
putenv('CA_HTML_TEMPLATE=v21');
putenv('PYTHONPATH=' . $root . '/engine');

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);
Db::run("UPDATE users SET role = 'admin' WHERE email = 'operator@example.com'");

$procesy = [];
$procesy[] = proc_open('php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r1);
for ($i = 0; $i < 50 && !file_exists($sock); $i++) { usleep(100000); }
$procesy[] = proc_open('php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r2);
for ($i = 0; $i < 50; $i++) {
    $p = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if (is_resource($p)) { fclose($p); break; }
    usleep(100000);
}
register_shutdown_function(static function () use ($procesy, $baza, $envFile, $logFile, $sock, $magazyn): void {
    foreach ($procesy as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    @unlink($baza); @unlink($envFile); @unlink($logFile); @unlink($sock);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

$bazaUrl = 'http://127.0.0.1:' . $port;
$ciasteczka = [];
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
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $naglowki),
        'content' => $tresc, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30]]);
    $body = @file_get_contents($bazaUrl . $path, false, $ctx);
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) { $status = (int) $m[1]; $location = null; }
        elseif (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        elseif (stripos($h, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }
    return ['status' => $status, 'location' => $location, 'body' => (string) $body];
}
function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}
function cron(): void
{
    global $root, $envFile;
    for ($i = 0; $i < 10; $i++) {
        exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' CA_HTML_TEMPLATE=v21 PYTHONPATH='
            . escapeshellarg($root . '/engine') . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1');
        if ((int) Db::one("SELECT COUNT(*) AS c FROM jobs WHERE status = 'queued' AND type <> 'send_mail'")['c'] === 0) {
            return;
        }
    }
}

// ---------------------------------------------------------------- eksport „Hetmana"
$wiersze = [
    // tag, begin, team, labels, x, y, gracz
    ['STRZAŁ', 60, 'KLUB A', 'CELNY,POZYCYJNIE', '88', '34', 'Kowalski Jan'],
    ['STRZAŁ', 400, 'KLUB B', 'NIECELNY', '80', '30', 'Rywal Adam'],
    ['ZDOBYCIE SBZ', 120, 'KLUB A', 'STRZAŁ,POZYCYJNIE', '70', '34', 'Nowak Piotr'],
    ['ZDOBYCIE SBZ', 900, 'KLUB B', 'BRAK STRZAŁU', '72', '30', 'Rywal Adam'],
    ['III STREFA', 200, 'KLUB A', 'UDANA', '', '', 'Nowak Piotr'],
    ['III STREFA', 1200, 'KLUB B', 'NIEUDANA', '', '', ''],
    ['1x1 OFF', 300, '', 'WYGRANY', '', '', 'Zieliński Ola'],
    ['1x1 DEF.', 320, '', 'PRZEGRANY', '', '', ''],
    ['STRATA', 340, '', 'ICH POŁOWA,REAKCJA', '', '', ''],
    ['ODBIÓR', 360, '', 'NASZA POŁOWA', '', '', ''],
    ['PIERWSZY KONTAKT', 380, '', 'WYGRANY', '', '', ''],
    ['ASYSTA', 58, 'KLUB A', '', '', '', 'Nowak Piotr'],
    ['ASYSTA', 390, 'KLUB A', '', '', '', 'Kowalski Jan'],
    ['NISKUTECZNY', 1500, '', 'STRZAŁ Z SBZ', '', '', ''],
];
$csv = fopen($magazyn . '/uploads/hetman.csv', 'w');
fputcsv($csv, ['tag_name', 'begin', 'end', 'team', 'labels', 'comment', 'pos_x_meters', 'pos_y_meters',
               'pos_target_x_meters', 'pos_target_y_meters', 'players'], ',', '"', '\\');
foreach ($wiersze as [$tag, $b, $team, $labels, $x, $y, $gracz]) {
    fputcsv($csv, [$tag, $b, $b + 5, $team, $labels, '', $x, $y, '', '', $gracz], ',', '"', '\\');
}
fclose($csv);

$UKLAD = ['przeglad', 'makro', 'bilans', 'mapy', 'tl_sbz', 'tl_iii', 'tl_bilans', 'duels', 'noteam'];
$zmienna = static fn(string $id, string $typ, string $raw): array => [
    'id' => $id, 'source' => ['type' => $typ, 'raw' => $raw], 'canon' => null,
    'display_label' => $raw, 'color' => '#112233', 'sections' => ['bilans'], 'visible' => true, 'aliases' => [],
];
ReportTemplates::saveNewVersion(1, [
    'schema_version' => 2,
    'team_us_rule' => ['markers' => ['NASZA', 'MASZA']],
    'sections' => array_map(static fn(int $i, string $w): array =>
        ['id' => 's' . ($i + 1), 'size' => '1', 'widgets' => [$w], 'title' => ''], array_keys($UKLAD), $UKLAD),
    'variables' => [$zmienna('v_001', 'tag', 'STRZAŁ'), $zmienna('v_002', 'tag', 'NISKUTECZNY'),
                    $zmienna('v_003', 'label', 'STRZAŁ Z SBZ')],
], 1);
// ASYSTA: „nie pytaj o ten tag" — W5: bez wpływu na raport i pokrycie.
\CoachAnalyze\IgnoredTags::add(1, 'tag', 'ASYSTA', 1);

$sezon = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];
Db::run("INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at, status)
         VALUES (1, 1, :s, 1, 2, '2026-09-20', 'done')", ['s' => $sezon]);
$mecz = (int) Db::pdo()->lastInsertId();
Db::run("INSERT INTO imports (match_id, csv_path, checksum_csv) VALUES (:m, :c, 'x')",
    ['m' => $mecz, 'c' => $magazyn . '/uploads/hetman.csv']);
$import = (int) Db::pdo()->lastInsertId();
// Zmienna dodana przy imporcie (jak raport 29/30) — zapis w pokryciu importu.
Imports::zapiszDodane($import, ['STRZAŁ Z SBZ']);
$plik = $magazyn . '/reports/r.html';
file_put_contents($plik, '<html>stary</html>');
Db::run("INSERT INTO reports (match_id, club_id, html_path, engine_version, template_version, generated_at)
         VALUES (:m, 1, :p, '0.16.7', 1, '2026-09-27 10:00:00')", ['m' => $mecz, 'p' => $plik]);
$raport = (int) Db::pdo()->lastInsertId();

// ---------------------------------------------------------------- regeneracja
Rebuilds::queue($raport, 1, Rebuilds::newBatchId());
cron();
$zad = Db::one("SELECT status, error_text FROM jobs WHERE type = 'rebuild_report' ORDER BY id DESC LIMIT 1");
check('regeneracja raportu zakończona', ($zad['status'] ?? '') === 'done', (string) ($zad['error_text'] ?? ''));
$html = (string) file_get_contents($plik);

echo "\n== 1. pokrycie = raport ==\n";
preg_match_all('/<section (?:data-brak-danych="1" )?id="[^"]+" data-widget="([^"]+)"/', $html, $mm);
check('raport ma dokładnie sekcje z Układu, w jego kolejności', $mm[1] === $UKLAD, implode(',', $mm[1]));
$pokrycie = Imports::report(Imports::find($import));
$dost = (array) ($pokrycie['sections_available'] ?? []);
$niedost = array_column((array) ($pokrycie['sections_unavailable'] ?? []), 'id');
foreach (['tl_sbz', 'tl_iii', 'duels', 'mapy'] as $s) {
    check("pokrycie: {$s} dostępna (jak w raporcie)", in_array($s, $dost, true) && !in_array($s, $niedost, true),
        json_encode($pokrycie['sections_unavailable'] ?? [], JSON_UNESCAPED_UNICODE));
}
preg_match_all('/<section data-brak-danych="1" id="[^"]+" data-widget="([^"]+)"/', $html, $wysz);
check('pokrycie i raport zgodne: niedostępne = wyszarzone', $niedost == $wysz[1],
    json_encode([$niedost, $wysz[1]]));

echo "\n== 2. bez „poza analizą” i „pominięte” ==\n";
$login = http('GET', '/login');
http('POST', '/login', ['form' => ['email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body'])]]);
$ekran = http('GET', '/import/' . $import);
check('ekran pokrycia odpowiada', $ekran['status'] === 200, (string) $ekran['status']);
$tekst = mb_strtolower(strip_tags($ekran['body']));
check('„poza analizą" nie występuje w pokryciu', !str_contains($tekst, 'poza analizą'));
check('„pominięt…" nie występuje w pokryciu', !str_contains($tekst, 'pominięt'));
$dane = json_decode((string) preg_replace('/^.*?const DATA = (.*?);\n.*$/s', '$1', $html), true);
check('ASYSTA (z „nie pytaj") jest w danych raportu',
    count(array_filter($dane['events'] ?? [], static fn($e) => $e['tag'] === 'ASYSTA')) === 2);
check('baner ostrzegawczy nie mówi o ASYŚCIE', !str_contains($html, 'ASYSTA (2) — pominięty'));
check('baner informacyjny: zmienna-etykieta dodana przy imporcie (z pokrycia importu)',
    preg_match('#<div class="baner baner--info[^>]*>[^<]*STRZAŁ Z SBZ#u', $html) === 1);

echo "\n== 7. zawodnicy z CSV = Zawodnicy (nasi + rywal) ==\n";
$zCsv = array_values(array_unique(array_filter(array_column($wiersze, 6))));
$zaw = http('GET', '/zawodnicy');
check('ekran Zawodnicy odpowiada', $zaw['status'] === 200);
$rywal = (string) strstr($zaw['body'], 'data-grupa="rywal"');
$nasi = (string) strstr($zaw['body'], 'data-grupa="rywal"', true);
$naEkranie = [];
foreach ($zCsv as $g) {
    $naEkranie[$g] = (str_contains($nasi, $g) ? 1 : 0) + (str_contains($rywal, $g) ? 1 : 0);
}
check('każdy zawodnik z CSV dokładnie raz na ekranie', array_sum($naEkranie) === count($zCsv)
    && !in_array(0, $naEkranie, true), json_encode($naEkranie, JSON_UNESCAPED_UNICODE));
check('„Rywal Adam" w grupie „Zawodnicy rywala"', str_contains($rywal, 'Rywal Adam') && !str_contains($nasi, 'Rywal Adam'));
check('Drużyna bez zmian: rywal poza listą', !str_contains(http('GET', '/druzyna')['body'], 'Rywal Adam'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
