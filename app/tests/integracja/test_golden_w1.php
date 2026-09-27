<?php
declare(strict_types=1);

/**
 * Golden layout W1 — „dane, które kłamią" i poprawki z odbioru W0 (SQLite).
 *
 *   - etykieta zmiennej = surowa nazwa, nigdy wielkość tytułowa (także „Gol"),
 *   - `regeneruj_raporty.php --match N` i `--nieaktualne`,
 *   - pulpit: kolejność po dacie meczu, a bez niej po dacie raportu; najnowszy
 *     raport meczu z identyfikatorem i datą,
 *   - pokrycie nie nazywa „niedostępną" sekcji, którą najnowszy raport ma,
 *     i mówi, z czego powstało,
 *   - wersja silnika w stopce bez pamięci sesji (przeżywała wdrożenia).
 *
 * Uruchomienie:  php test_golden_w1.php
 */

use CoachAnalyze\AutoImport;
use CoachAnalyze\Configurator;
use CoachAnalyze\Db;
use CoachAnalyze\Engine;
use CoachAnalyze\Imports;
use CoachAnalyze\ReportTemplates;
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

$baza    = $here . '/golden_w1.sqlite';
$envFile = $here . '/.env.golden_w1';
$magazyn = $here . '/golden_w1_storage';

@unlink($baza);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'APP_URL=http://127.0.0.1',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);

register_shutdown_function(static function () use ($baza, $envFile, $magazyn): void {
    @unlink($baza);
    @unlink($envFile);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

function skrypt(string ...$argi): array
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' php '
        . escapeshellarg($root . '/app/repairs/regeneruj_raporty.php')
        . ' ' . implode(' ', array_map('escapeshellarg', $argi)) . ' 2>&1', $w, $kod);
    return [$kod, implode("\n", $w)];
}

// ===========================================================================
echo "== etykieta = surowa nazwa, nigdy Title Case ==\n";

$konfig = Configurator::config([
    ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRATA'], 'canon' => 'loss',
     'display_label' => 'STRATA', 'color' => '#8899AA', 'sections' => ['bilans'], 'visible' => true],
], Configurator::SEKCJE);
ReportTemplates::saveNewVersion(1, $konfig, 1);
$meta = ['teams' => [], 'dictionary' => [
    'tags' => [['tag' => 'Gol', 'count' => 3], ['tag' => 'ZDOBYCIE SBZ', 'count' => 11],
               ['tag' => '1x1 OFF', 'count' => 17], ['tag' => 'III STREFA', 'count' => 12],
               ['tag' => 'Strata na PP', 'count' => 4]],
    'labels' => [['label' => 'NASZA POŁOWA', 'count' => 5]],
]];
Db::run("INSERT INTO matches (owner_id, club_id, status) VALUES (1, 1, 'draft')");
$m = (int) Db::pdo()->lastInsertId();
Db::run('INSERT INTO imports (match_id, csv_path, checksum_csv, coverage_json) VALUES (:m, :c, :s, :j)',
    ['m' => $m, 'c' => '/dev/null', 's' => 'x', 'j' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
$imp = (int) Db::pdo()->lastInsertId();
AutoImport::autoZmienne($imp, Imports::find($imp));
$zmienne = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config'])['variables'];
$bledne = [];
foreach ($zmienne as $z) {
    if ($z['display_label'] !== $z['source']['raw']) {
        $bledne[] = $z['source']['raw'] . ' → ' . $z['display_label'];
    }
}
check('auto-zmienne: etykieta = surowa nazwa (Gol, ZDOBYCIE SBZ, 1x1 OFF, III STREFA, Strata na PP)',
    $bledne === [] && count($zmienne) === 7, implode(', ', $bledne) . ' / zmiennych: ' . count($zmienne));
foreach (['Gol', 'ZDOBYCIE SBZ', '1x1 OFF', 'III STREFA', 'Strata na PP', 'iii strefa'] as $raw) {
    check("etykietaZNazwy nie zmienia wielkości liter: {$raw}", Configurator::etykietaZNazwy($raw) === $raw);
}

// ===========================================================================
echo "\n== pulpit: kolejność i najnowszy raport ==\n";

$sezon = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];
Db::run('DELETE FROM matches');
Db::run('DELETE FROM reports');
$wstaw = static function (?string $data, ?string $raportAt, string $status = 'done') use ($sezon): int {
    Db::run('INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at, status)
             VALUES (1, 1, :s, 1, 2, :d, :st)', ['s' => $sezon, 'd' => $data, 'st' => $status]);
    $id = (int) Db::pdo()->lastInsertId();
    if ($raportAt !== null) {
        Db::run("INSERT INTO reports (match_id, club_id, html_path, generated_at, engine_version)
                 VALUES (:m, 1, '/x', :g, '0.16.1')", ['m' => $id, 'g' => $raportAt]);
    }
    return $id;
};
$stary   = $wstaw('2026-08-02', '2026-08-03 10:00:00');
$bezDaty = $wstaw(null, '2026-09-20 18:30:00');
$nowszy  = $wstaw('2026-09-10', '2026-09-11 09:00:00');
// Raport na BIEŻĄCEJ wersji silnika — `--nieaktualne` ma go pominąć.
Db::run("INSERT INTO reports (match_id, club_id, html_path, generated_at, engine_version)
         VALUES (:m, 1, '/y', '2026-09-26 21:00:00', :v)", ['m' => $nowszy, 'v' => Engine::version()]);
$najnowszyRaport = (int) Db::pdo()->lastInsertId();

$kolejnosc = array_map(static fn($r) => (int) $r['id'], Stats::seasonMatches($sezon, 10));
check('mecz bez daty sortuje się po dacie raportu, nie ląduje na końcu',
    $kolejnosc === [$bezDaty, $nowszy, $stary], json_encode($kolejnosc));
$wiersz = array_values(array_filter(Stats::seasonMatches($sezon, 10), static fn($r) => (int) $r['id'] === $nowszy))[0];
check('wiersz niesie NAJNOWSZY raport meczu', (int) $wiersz['report_id'] === $najnowszyRaport
    && str_starts_with((string) $wiersz['report_at'], '2026-09-26 21:00'), json_encode($wiersz));
check('„Ostatni mecz" = ta sama kolejność', (int) Stats::lastFinishedMatch()['id'] === $bezDaty);

// ===========================================================================
echo "\n== regeneruj_raporty.php --match / --nieaktualne ==\n";

[$kod, $out] = skrypt('--match', (string) $nowszy, '--dry-run');
check('--match N bierze wyłącznie raporty meczu', $kod === 0 && str_contains($out, '2 raportów'), $out);
[$kod, $out] = skrypt('--nieaktualne', '--dry-run');
$wersja = Engine::version();
check('--nieaktualne porównuje z wdrożoną wersją', str_contains($out, 'Wdrożony silnik: ' . $wersja), $out);
check('--nieaktualne pomija raport na bieżącej wersji',
    str_contains($out, '3 raportów') && !str_contains($out, 'raport ' . str_pad((string) $najnowszyRaport, 5)), $out);
[$kod, $out] = skrypt('--nieaktualne', '--match', (string) $nowszy);
check('--nieaktualne --match wybiera jeden raport (bez surowych plików — pominięty z powodem)',
    $kod === 0 && str_contains($out, ': 1 raportów (nieaktualne)') && str_contains($out, 'pominięty'), $out);
[$kod] = skrypt('--match', '0');
check('--match 0 to błąd użycia', $kod === 2);

// ===========================================================================
echo "\n== pokrycie nie zaprzecza raportowi ==\n";

Db::run("INSERT INTO imports (match_id, csv_path, checksum_csv, coverage_json, sections_json)
         VALUES (:m, '/dev/null', 'x', '{}', :s)",
    ['m' => $nowszy, 's' => json_encode(['available' => ['bilans'], 'unavailable' => [
        ['id' => 'mapy', 'reason' => 'brak pozycji'], ['id' => 'tl_iii', 'reason' => 'brak III strefy']]])]);
Db::run('UPDATE reports SET params_json = :p WHERE id = :r',
    ['p' => json_encode(['sections' => ['bilans', 'mapy']]), 'r' => $najnowszyRaport]);
$raport = Imports::report(Imports::latestForMatch($nowszy));
$ids = array_column($raport['sections_unavailable'], 'id');
check('sekcja obecna w raporcie nie jest „niedostępna"', !in_array('mapy', $ids, true), json_encode($ids));
check('sekcji nieobecnej w raporcie powód zostaje', in_array('tl_iii', $ids, true));
check('zapis sprzed podpisu: źródło nieznane (null), nie zgadywane', $raport['zrodlo'] === null);

Imports::saveInspection((int) Imports::latestForMatch($nowszy)['id'],
    ['engine_version' => '0.16.4', 'sections_available' => ['bilans'], 'sections_unavailable' => []],
    ['rodzaj' => 'raport', 'template_version' => 5]);
$zr = Imports::report(Imports::latestForMatch($nowszy))['zrodlo'];
check('pokrycie po renderze niesie szablon, silnik i datę',
    $zr['rodzaj'] === 'raport' && $zr['template_version'] === 5 && $zr['engine_version'] === '0.16.4' && $zr['at'] !== '',
    json_encode($zr));

// ===========================================================================
echo "\n== stopka: wersja bez pamięci sesji ==\n";

$zrodlo = (string) file_get_contents($root . '/app/src/Engine.php');
check('Engine::version nie czyta wersji z sesji', !str_contains($zrodlo, 'Session::get('));
file_put_contents($magazyn . '/.engine_version', '9.9.9');
$wersjaZArtefaktu = (function (): string {
    $r = new ReflectionProperty(Engine::class, 'memo');
    $r->setValue(null, null);
    return Engine::version();
})();
check('stopka czyta artefakt wdrożenia', $wersjaZArtefaktu === '9.9.9', $wersjaZArtefaktu);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
