<?php
declare(strict_types=1);

/**
 * Golden layout W6 — ostatnie poprawki przed przeglądem u klienta.
 *
 *   1. Pressing skuteczny = SKUTECZNY / (SKUTECZNY + NISKUTECZNY) wszędzie:
 *      Pulpit (KPI i karta), Sezon (kolumna i SUMA), /api/metryki i raport
 *      (blok liczb v21 w Node). JDRZ: 88% (15/17), nie 9% (1/11).
 *   2. Pasek sezonu: kafelek pokazuje wynik ręczny albo z tagów; „–" tylko bez obu.
 *   3. deploy.sh: werdykt z `.deployed_rev` i wersji silnika z artefaktu.
 *   4. Alert dysku w GB, nie w procentach.
 *
 * Mecz „JDRZ" jest SYNTETYCZNY (CLAUDE.md §7) — te same proporcje co na produkcji.
 *
 * Uruchomienie:  php test_golden_w6_http.php
 */

use CoachAnalyze\Alerts;
use CoachAnalyze\Db;
use CoachAnalyze\Metrics;

$root = dirname(__DIR__, 3);
$here = __DIR__;

$ok = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$baza    = $here . '/golden_w6.sqlite';
$magazyn = $here . '/golden_w6_storage';
$envFile = $here . '/.env.golden_w6';
$sock    = $here . '/golden_w6_redis.sock';
$port    = 9111;

@unlink($baza);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);
file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza, 'STORAGE_PATH=' . $magazyn,
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=golden_w6:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);

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
register_shutdown_function(static function () use ($procesy, $baza, $envFile, $sock, $magazyn): void {
    foreach ($procesy as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
    @unlink($baza); @unlink($envFile); @unlink($sock);
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
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) { $status = (int) $m[1]; }
        elseif (stripos($h, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }
    return ['status' => $status, 'body' => (string) $body];
}
function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

// ---------------------------------------------------------------- dane
$sezon = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];
// Czyste konto klubu 1: bez meczów z seeda, żeby liczby były tylko z JDRZ.
Db::run('DELETE FROM reports WHERE match_id IN (SELECT id FROM matches WHERE club_id = 1)');
Db::run('DELETE FROM matches WHERE club_id = 1');

$mecz = static function (?string $data, ?int $su = null, ?int $st = null) use ($sezon): int {
    Db::run("INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at,
                                  score_us, score_them, status)
             VALUES (1, 1, :s, 1, 2, :d, :su, :st, 'done')",
        ['s' => $sezon, 'd' => $data, 'su' => $su, 'st' => $st]);
    return (int) Db::pdo()->lastInsertId();
};
$zd = static function (int $m, string $tag, string $strona, array $etykiety = [], int $gol = 0): void {
    Db::run("INSERT INTO events (match_id, tag_name, labels_json, team_side, t_ms, half, minute, is_goal)
             VALUES (:m, :t, :l, :s, 1000, 1, 1, :g)",
        ['m' => $m, 't' => $tag, 'l' => json_encode($etykiety, JSON_UNESCAPED_UNICODE), 's' => $strona, 'g' => $gol]);
};

$jdrz = $mecz('2026-09-27');
$zdarzeniaRaportu = [];
for ($i = 0; $i < 15; $i++) { $zd($jdrz, 'SKUTECZNY', 'none', ['POSIADANIE']); $zdarzeniaRaportu[] = ['tag' => 'SKUTECZNY', 'b' => $i, 'team' => null, 'labels' => ['POSIADANIE']]; }
for ($i = 0; $i < 2; $i++) { $zd($jdrz, 'NISKUTECZNY', 'none'); $zdarzeniaRaportu[] = ['tag' => 'NISKUTECZNY', 'b' => 50 + $i, 'team' => null, 'labels' => []]; }
for ($i = 0; $i < 11; $i++) { $zd($jdrz, 'ZDOBYCIE SBZ', 'us', $i === 0 ? ['PRESSING'] : ['POZYCYJNIE']); }
$zd($jdrz, 'STRZAŁ', 'us', ['CELNY'], 1);
$zd($jdrz, 'STRZAŁ', 'them', ['NIECELNY']);

$reczny = $mecz('2026-09-13', 2, 1);          // wynik ręczny, bez zdarzeń
$pusty  = $mecz('2026-09-06');                // ani wyniku, ani zdarzeń

// ===========================================================================
echo "== 1. Pressing skuteczny: jedna definicja ==\n";

$def = Metrics::definicje();
$p = Metrics::compute($def['pressing'], ['club_id' => 1, 'match_id' => $jdrz]);
check('Metrics: pressing JDRZ = 15/17', $p['n'] === 15 && $p['d'] === 17 && round((float) $p['value'] * 100) === 88.0,
    json_encode($p));
$sbzP = Metrics::compute($def['sbz_po_pressingu'], ['club_id' => 1, 'match_id' => $jdrz]);
check('dawna miara pod własną nazwą „Wejścia w SBZ po pressingu" = 1/11',
    $sbzP['n'] === 1 && $sbzP['d'] === 11 && $def['sbz_po_pressingu']['label'] === 'Wejścia w SBZ po pressingu');

$login = http('GET', '/login');
http('POST', '/login', ['form' => ['email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body'])]]);
$pulpit = http('GET', '/pulpit');
check('Pulpit odpowiada', $pulpit['status'] === 200);
check('Pulpit: „Pressing skuteczny" 88% (z 17)', preg_match('#Pressing skuteczny.{0,400}?88%#su', $pulpit['body']) === 1
    && str_contains($pulpit['body'], 'z 17 zdarzeń') && !str_contains($pulpit['body'], '>9%<'));
$sezonEkran = http('GET', '/sezon');
check('Sezon: kolumna „Pressing skuteczny"', str_contains($sezonEkran['body'], 'Pressing skuteczny'));
check('Sezon: wiersz i SUMA 88% (15/17)', substr_count($sezonEkran['body'], '88% (15/17)') >= 2,
    (string) substr_count($sezonEkran['body'], '88% (15/17)'));
$api = http('GET', '/api/metryki?club=1&match=' . $jdrz);
$apiJ = json_decode($api['body'], true);
$apiP = null;
foreach ((array) ($apiJ['metrics'] ?? []) as $m) { if (($m['id'] ?? '') === 'pressing') { $apiP = $m; } }
check('/api/metryki: pressing 15/17', $apiP !== null && (int) $apiP['n'] === 15 && (int) $apiP['d'] === 17,
    substr($api['body'], 0, 200));

$node = trim((string) shell_exec('command -v node'));
if ($node === '') {
    echo "  POMINIĘTE raport v21 w Node — brak node\n";
} else {
    $szablon = (string) file_get_contents($root . '/engine/coachanalyze/templates/dashboard_template_v21.html');
    preg_match('#/\*<liczby-v21>\*/(.*?)/\*</liczby-v21>\*/#s', $szablon, $mb);
    $kod = $mb[1] . "\nconst o=liczPrzeglad(" . json_encode($zdarzeniaRaportu, JSON_UNESCAPED_UNICODE)
        . ",'T','R');console.log(JSON.stringify(o.us.pr));";
    $plik = $magazyn . '/liczby.js';
    file_put_contents($plik, $kod);
    $pr = json_decode((string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($plik)), true);
    check('raport v21: pressingów skutecznych 15 z 17 (88%)', $pr === [15, 2]
        && round(100 * 15 / 17) === round((float) $p['value'] * 100), json_encode($pr));
}

// ===========================================================================
echo "\n== 2. Pasek sezonu: wynik na kafelku ==\n";

$kafel = static function (string $html, int $id): string {
    return preg_match('#<a class="q[^"]*" href="/mecze/' . $id . '".*?</a>#s', $html, $m) === 1 ? $m[0] : '';
};
$kJ = $kafel($pulpit['body'], $jdrz);
check('JDRZ: wynik z tagów 1:0 i kolor wygranej', str_contains($kJ, 'q--w') && preg_match('#q__wynik[^>]*>\s*1:0\s*<#', $kJ) === 1, $kJ);
$kR = $kafel($pulpit['body'], $reczny);
check('wynik ręczny 2:1 bez zdarzeń — pokazany, kolor wygranej', str_contains($kR, 'q--w')
    && preg_match('#data-zrodlo="reczny"[^>]*>\s*2:1\s*<#', $kR) === 1, $kR);
$kP = $kafel($pulpit['body'], $pusty);
check('bez wyniku i bez goli w tagach — kreska', preg_match('#data-zrodlo="brak"[^>]*>\s*'
    . preg_quote(\CoachAnalyze\View::t('common.dash'), '#') . '\s*<#u', $kP) === 1, $kP);

// ===========================================================================
echo "\n== 3. deploy.sh: werdykt z ostatniego wdrożenia i wersji silnika ==\n";

$deploy = (string) file_get_contents($root . '/deploy/deploy.sh');
check('zapisuje rewizję wdrożoną w shared/.deployed_rev', str_contains($deploy, 'PLIK_REWIZJI="$BASE/shared/.deployed_rev"')
    && str_contains($deploy, 'printf \'%s\n\' "$AKTUALNA" > "$PLIK_REWIZJI"'));
check('zapis rewizji dopiero po kontrolach (za „exit 1" przy FAIL)',
    strpos($deploy, 'WDROŻENIE Z BŁĘDAMI') < strpos($deploy, '> "$PLIK_REWIZJI"'));
check('wersja silnika sprzed nadpisania artefaktu', strpos($deploy, 'SILNIK_POPRZEDNI=$(cat "$STORAGE_REAL/.engine_version"')
    < strpos($deploy, 'printf \'%s\' "$WERSJA_SILNIKA" > "$STORAGE_REAL/.engine_version"'));
check('zmiana wersji silnika = POTRZEBNA', str_contains($deploy, 'REGENERACJA="POTRZEBNA — silnik $SILNIK_POPRZ -> $WERSJA_SILNIKA"'));
check('diff od rewizji wdrożonej, nie od HEAD sprzed pull', str_contains($deploy, 'diff --name-only "$WDROZONA" HEAD'));
$poGotowe = (string) strstr($deploy, 'echo "==> Gotowe:');
check('podsumowanie dalej mieści się w tail -8', preg_match_all('/^\s*echo /m', $poGotowe) <= 6);

// ===========================================================================
echo "\n== 4. Alert dysku w GB ==\n";

$gb = 1024 ** 3;
check('162 GB wolnego (7,1% dysku współdzielonego) — brak alertu', Alerts::poziomMiejsca(162 * $gb) === null);
check('4 GB — ostrzeżenie', Alerts::poziomMiejsca(4 * $gb) === Alerts::LEVEL_WARN);
check('0,5 GB — błąd', Alerts::poziomMiejsca(0.5 * $gb) === Alerts::LEVEL_ERROR);
check('komunikat bez procentów', !str_contains(\CoachAnalyze\View::t('alert.disk', '4 GB'), '%'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
