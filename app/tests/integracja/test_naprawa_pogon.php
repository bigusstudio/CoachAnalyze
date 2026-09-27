<?php
declare(strict_types=1);

/**
 * Regresja v6 Pogoni (raport 28, mecz 26) — naprawa templatu na PRAWDZIWYM
 * templacie v5, z przelotem przez silnik.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO SIĘ STAŁO. `napraw_auto_etykiety.php` scalał duplikaty „ta sama nazwa",
 * zostawiając zmienną o niższym `id`. Na Pogoni był to martwy „Strzał" (v_003,
 * zestaw startowy innego klubu), a żywy „STRZAŁ" (v_041) został jego aliasem.
 * Szablon PRZEMIANOWUJE zdarzenie na surową nazwę zmiennej, której alias pasuje,
 * więc wszystkie strzały stały się „Strzał": Przegląd (liczy „STRZAŁ") pokazał
 * 0:0, xG 0,00 i gole bez strzału po stronie tenanta. Tabela makro liczyła
 * „Strzał 11/24", bo idzie po zmiennych — stąd wrażenie, że „alias działa".
 *
 * CO TEN ZESTAW DOWODZI
 *   1. kontrola ciągłości ODRZUCA wynik starego scalenia (bramka działa),
 *   2. naprawa v5 zostawia żywe zmienne jako zmienne, z kanonem,
 *   3. raport z naprawionym templatem liczy strzały — a z templatem v6 nie
 *      (kontrola negatywna: test umie zobaczyć regresję, której pilnuje),
 *   4. dwie żywe zmienne o tej samej nazwie nie są scalane,
 *   5. `--przywroc N` odtwarza wersję bez SQL i bez unieważniania raportów,
 *   6. AutoImport nie robi z wariantu zapisu TAGU aliasu.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Templat: `dane/pogon_v5_templat.json` — przepisany z wyniku SQL z 2026-09-27.
 * Nazwy tagów, nie zdarzenia meczowe (CLAUDE.md §7). Zdarzenia są syntetyczne.
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_naprawa_pogon.php
 */

use CoachAnalyze\AutoImport;
use CoachAnalyze\Configurator;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\NaprawaTemplatu;
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

$baza    = $here . '/naprawa_pogon.sqlite';
$envFile = $here . '/.env.naprawa_pogon';
$magazyn = $here . '/naprawa_pogon_storage';
$tmp     = $magazyn . '/tmp';

@unlink($baza);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($tmp, 0770, true);

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

$python = $root . '/venv/bin/python';
$dane = json_decode((string) file_get_contents($here . '/dane/pogon_v5_templat.json'), true);
$klub = 1;

/** @return array<string,array<string,mixed>> */
function poNazwie(array $zmienne): array
{
    $out = [];
    foreach ($zmienne as $z) {
        $out[$z['source']['type'] . '|' . $z['source']['raw']] = $z;
    }
    return $out;
}

function naprawa(string ...$argi): array
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' php '
        . escapeshellarg($root . '/app/repairs/napraw_auto_etykiety.php')
        . ' ' . implode(' ', array_map('escapeshellarg', $argi)) . ' 2>&1', $wyjscie, $kod);
    return [$kod, implode("\n", $wyjscie)];
}

function katalog(int $klub, array $kat): void
{
    Db::run('DELETE FROM tag_catalog WHERE club_id = :c', ['c' => $klub]);
    foreach ($kat as $kind => $nazwy) {
        foreach ($nazwy as $n) {
            Db::run('INSERT INTO tag_catalog (club_id, kind, name, seen_matches, seen_events) VALUES (:c, :k, :n, 1, 1)',
                ['c' => $klub, 'k' => $kind, 'n' => $n]);
        }
    }
}

/**
 * Raport v21 z danym templatem i syntetycznym meczem → liczby Przeglądu
 * policzone narzędziem odbioru (które stosuje aliasy z `VARS` jak szablon).
 *
 * @return array<string,array{0:string,1:string}>
 */
function przeglad(array $templat, string $nazwa): array
{
    global $root, $tmp, $python;
    $csv = ca_test_csv([
        ['tag_name', 'begin', 'end', 'team', 'labels', 'comment', 'pos_x_meters', 'pos_y_meters'],
        ['STRZAŁ',       '100',  '105',  'POGOŃ', 'POZYCYJNIE, CELNY', 'X 0,40', '90', '34'],
        ['Gol',          '101',  '106',  'POGOŃ', '',                  '',       '',   ''],
        ['STRZAŁ',       '900',  '905',  'JDRZ',  'KONTRATAK, NIECELNY', 'xG 0,10', '85', '30'],
        ['STRZAŁ',       '1500', '1505', 'JDRZ',  'SFG, CELNY',        'x 0,20', '92', '36'],
        ['Gol',          '1501', '1506', 'JDRZ',  '',                  '',       '',   ''],
        ['ZDOBYCIE SBZ', '200',  '210',  'POGOŃ', 'POZYCYJNIE, STRZAŁ', '',      '88', '30'],
        ['SBZ PODAJĄCY', '1400', '1410', 'JDRZ',  'INNE',              '',       '87', '31'],
        ['ODBIÓR',       '300',  '305',  '',      'ICH POŁOWA',        '',       '',   ''],
        ['SKUTECZNY',    '400',  '405',  '',      'INNE',              '',       '',   ''],
    ]);
    $pTemplat = "{$tmp}/{$nazwa}_templat.json";
    $pConfig = "{$tmp}/{$nazwa}_config.json";
    file_put_contents($pTemplat, json_encode($templat, JSON_UNESCAPED_UNICODE));
    file_put_contents($pConfig, json_encode([
        'match_id' => 26,
        'teams' => ['us' => ['name' => 'POGOŃ', 'club_id' => 1], 'them' => ['name' => 'JDRZ', 'club_id' => 2]],
        'match' => ['tenant_club_id' => 1],
    ], JSON_UNESCAPED_UNICODE));
    $html = "{$tmp}/{$nazwa}.html";
    exec(implode(' ', array_map('escapeshellarg', [
        $python, '-m', 'coachanalyze', 'build', '--csv', $csv, '--config', $pConfig,
        '--template', $pTemplat, '--html-template', 'v21',
        '--out-html', $html, '--out-meta', "{$tmp}/{$nazwa}_meta.json",
    ])) . ' 2>&1', $w, $kod);
    if ($kod !== 0) {
        return ['_blad' => [(string) $kod, implode("\n", $w)]];
    }
    exec(escapeshellarg($python) . ' ' . escapeshellarg($root . '/engine/tools/przeglad_liczby.py')
        . ' ' . escapeshellarg($html) . ' 2>&1', $linie);
    $out = [];
    foreach ($linie as $l) {
        if (preg_match('/^(\S.*?)\s{2,}(\S+)\s+(\S+)$/u', $l, $m) === 1) {
            $out[trim($m[1])] = [$m[2], $m[3]];
        }
    }
    return $out;
}

$v5 = Configurator::config(
    $dane['variables'],
    $dane['sections_enabled'],
    ['NASZA', 'MASZA']
);
$zmienneV5 = $v5['variables'];
katalog($klub, $dane['katalog']);

// ===========================================================================
echo "== 1. bramka ciągłości odrzuca scalenie z v6 ==\n";

$katalogKlubu = NaprawaTemplatu::katalog($klub);
$v6 = [];
foreach ($zmienneV5 as $z) {
    $raw = $z['source']['raw'];
    if ($raw === 'STRZAŁ' || $raw === 'INNE') {
        continue;                               // wchłonięte w v6
    }
    if ($z['id'] === 'v_003') {
        $z['aliases'] = ['STRZAŁ'];
    }
    if ($z['id'] === 'v_030') {
        $z['aliases'] = ['INNE'];
    }
    $v6[] = $z;
}
$bledyV6 = NaprawaTemplatu::sprawdzCiaglosc($zmienneV5, $v6, $katalogKlubu);
check('scalenie z v6 odrzucone: żywy STRZAŁ przestał być zmienną',
    (bool) preg_grep('/„STRZAŁ" przestała być zmienną/u', $bledyV6), implode(' | ', $bledyV6));
check('scalenie z v6 odrzucone: żywe INNE przestało być zmienną',
    (bool) preg_grep('/„INNE" przestała być zmienną/u', $bledyV6), implode(' | ', $bledyV6));

// ===========================================================================
echo "\n== 2. naprawa prawdziwego v5 ==\n";

check('templat v5 zapisany jako v1 klubu testowego', ReportTemplates::saveNewVersion($klub, $v5, 1) === 1);

[$kod, $out] = naprawa('--club', (string) $klub, '--takze-reczne', '--zapisz');
check('naprawa przechodzi kontrolę i zapisuje', $kod === 0 && ReportTemplates::currentVersion($klub) === 2, $out);

$po = ReportTemplates::decodeConfig(ReportTemplates::current($klub)['config'])['variables'];
$z = poNazwie($po);
check('STRZAŁ (v_041) zostaje zmienną z kanonem shot',
    ($z['tag|STRZAŁ']['id'] ?? '') === 'v_041' && ($z['tag|STRZAŁ']['canon'] ?? null) === 'shot');
check('martwy „Strzał" (v_003) wchłonięty jako alias STRZAŁ',
    !isset($z['tag|Strzał']) && in_array('Strzał', $z['tag|STRZAŁ']['aliases'] ?? [], true));
check('INNE (v_078) zostaje zmienną, martwe „Inne" jest jej aliasem',
    isset($z['label|INNE']) && !isset($z['label|Inne'])
    && in_array('Inne', $z['label|INNE']['aliases'] ?? [], true));
check('SBZ PODAJĄCY → alias ZDOBYCIE SBZ, III STREFA PODAJĄCY/… → alias III STREFA',
    !isset($z['tag|SBZ PODAJĄCY']) && !isset($z['tag|III STREFA PODAJĄCY/OTRZYMUJĄCY'])
    && in_array('SBZ PODAJĄCY', $z['tag|ZDOBYCIE SBZ']['aliases'] ?? [], true)
    && in_array('III STREFA PODAJĄCY/OTRZYMUJĄCY', $z['tag|III STREFA']['aliases'] ?? [], true));

$kanonyV5 = array_values(array_unique(array_filter(array_column($zmienneV5, 'canon'))));
$kanonyPo = array_values(array_unique(array_filter(array_column($po, 'canon'))));
check('każdy kanon v5 jest po naprawie', array_diff($kanonyV5, $kanonyPo) === [],
    implode(',', array_diff($kanonyV5, $kanonyPo)));
$zgubione = [];
foreach ($zmienneV5 as $zm) {
    if (NaprawaTemplatu::zywa($zm, $katalogKlubu) && !isset($z[$zm['source']['type'] . '|' . $zm['source']['raw']])
        && !in_array($zm['source']['raw'], ['SBZ PODAJĄCY', 'III STREFA PODAJĄCY/OTRZYMUJĄCY'], true)) {
        $zgubione[] = $zm['source']['raw'];
    }
}
check('każda żywa zmienna v5 zostaje zmienną (poza aliasami silnika)', $zgubione === [], implode(', ', $zgubione));
check('etykiety poprawione na surowe: „Zdobycie Sbz" → „ZDOBYCIE SBZ"',
    ($z['tag|ZDOBYCIE SBZ']['display_label'] ?? '') === 'ZDOBYCIE SBZ');

[$kod, $out] = naprawa('--club', (string) $klub, '--wykaz-martwych');
check('wykaz martwych obejmuje zestaw startowy innego klubu',
    str_contains($out, 'Posiadanie Stal') && str_contains($out, 'K1P'), $out);
check('wykaz martwych NIE obejmuje żywych (STRZAŁ, INNE, ZDOBYCIE SBZ)',
    preg_match('/^\s+v_\d+\s+\S+\s+(STRZAŁ|INNE|ZDOBYCIE SBZ)$/mu', $out) !== 1, $out);

[$kod, $out] = naprawa('--club', (string) $klub, '--takze-reczne', '--zapisz');
check('drugi przebieg: nic do zrobienia', str_contains($out, 'nic do zrobienia')
    && ReportTemplates::currentVersion($klub) === 2, $out);

// ===========================================================================
echo "\n== 3. przelot przez silnik: Przegląd liczy strzały ==\n";

if (!is_file($python)) {
    check('venv Pythona dostępny', false, $python);
} else {
    $dobry = przeglad(ReportTemplates::decodeConfig(ReportTemplates::current($klub)['config']), 'naprawiony');
    check('raport z naprawionym templatem powstał', !isset($dobry['_blad']), json_encode($dobry['_blad'] ?? null));
    check('strzały 1 : 2', ($dobry['strzały'] ?? null) === ['1', '2'], json_encode($dobry['strzały'] ?? null));
    check('xG 0.4 : 0.3', ($dobry['xG'] ?? null) === ['0.4', '0.3'], json_encode($dobry['xG'] ?? null));
    check('gole 1 : 1 (atrybucja po strzale działa)', ($dobry['gole'] ?? null) === ['1', '1'],
        json_encode($dobry['gole'] ?? null));
    check('SBZ PODAJĄCY liczone jako zdobycie SBZ: 1 : 1',
        ($dobry['zdobycie SBZ'] ?? null) === ['1', '1'], json_encode($dobry['zdobycie SBZ'] ?? null));

    $v6cfg = $v5;
    $v6cfg['variables'] = $v6;
    $zly = przeglad($v6cfg, 'v6');
    check('KONTROLA NEGATYWNA: templat v6 daje strzały 0 : 0 (tak jak raport 28)',
        ($zly['strzały'] ?? null) === ['0', '0'], json_encode($zly['strzały'] ?? $zly));
}

// ===========================================================================
echo "\n== 4. dwie żywe zmienne o tej samej nazwie ==\n";

$klub2 = 4;
$dwie = Configurator::config([
    ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'Strzał'], 'canon' => null,
     'display_label' => 'Strzał', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
    ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'], 'canon' => 'shot',
     'display_label' => 'STRZAŁ', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
    ['id' => 'v_003', 'source' => ['type' => 'label', 'raw' => 'Inne'], 'canon' => null,
     'display_label' => 'Inne', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
    ['id' => 'v_004', 'source' => ['type' => 'label', 'raw' => 'INNE'], 'canon' => null,
     'display_label' => 'INNE', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
], Configurator::SEKCJE);
ReportTemplates::saveNewVersion($klub2, $dwie, 1);
katalog($klub2, ['tag' => ['Strzał', 'STRZAŁ'], 'label' => ['Inne', 'INNE']]);

[$kod, $out] = naprawa('--club', (string) $klub2, '--zapisz');
check('obie żywe: brak scalenia, wpis „do decyzji" dla tagu i etykiety',
    substr_count($out, 'do decyzji') === 2 && str_contains($out, 'nic do zrobienia'), $out);
check('obie żywe: templat bez nowej wersji', ReportTemplates::currentVersion($klub2) === 1);

// Żywa bez kanonu + martwa z kanonem: kanon przechodzi na żywą.
$kanon = Configurator::config([
    ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'Strata'], 'canon' => 'loss',
     'display_label' => 'Strata', 'color' => '#FFFFFF', 'sections' => ['bilans', 'mapy'], 'visible' => true],
    ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRATA'], 'canon' => null,
     'display_label' => 'STRATA', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
], Configurator::SEKCJE);
ReportTemplates::saveNewVersion($klub2, $kanon, 1);
katalog($klub2, ['tag' => ['STRATA']]);
[$kod, $out] = naprawa('--club', (string) $klub2, '--zapisz');
$z = poNazwie(ReportTemplates::decodeConfig(ReportTemplates::current($klub2)['config'])['variables']);
check('żywa STRATA zostaje, przejmuje kanon loss i sekcję mapy od martwej „Strata"',
    isset($z['tag|STRATA']) && !isset($z['tag|Strata'])
    && ($z['tag|STRATA']['canon'] ?? null) === 'loss'
    && in_array('mapy', $z['tag|STRATA']['sections'] ?? [], true)
    && in_array('Strata', $z['tag|STRATA']['aliases'] ?? [], true), $out);

// ===========================================================================
echo "\n== 5. --przywroc: cofnięcie bez SQL ==\n";

$v1 = ReportTemplates::decodeConfig(ReportTemplates::version($klub, 1)['config']);
[$kod, $out] = naprawa('--club', (string) $klub, '--przywroc', '1');
$przywrocona = ReportTemplates::current($klub);
$cfgP = ReportTemplates::decodeConfig($przywrocona['config']);
check('--przywroc 1 tworzy v3', $kod === 0 && (int) $przywrocona['version'] === 3, $out);
check('v3 ma zmienne v1 co do bajtu', json_encode($cfgP['variables']) === json_encode($v1['variables']));
check('v3 zapisana przez system (created_by NULL)', $przywrocona['created_by'] === null);
check('v3 nie unieważnia raportów', ReportTemplates::isOutdated($klub, 1) === false);
check('v3 niesie notkę przywrócenia', ReportTemplates::autoNote($cfgP) === 'przywrócenie v1 (napraw_auto_etykiety)');
[$kod, $out] = naprawa('--club', (string) $klub, '--przywroc', '99');
check('nieistniejąca wersja: błąd, bez zapisu', $kod !== 0 && ReportTemplates::currentVersion($klub) === 3, $out);

// ===========================================================================
echo "\n== 6. AutoImport: wariant zapisu TAGU nie staje się aliasem ==\n";

$klub3 = 2;
$martwyStrzal = Configurator::config([
    ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'Strzał'], 'canon' => 'shot',
     'display_label' => 'Strzał', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
    ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'ZDOBYCIE SBZ'], 'canon' => 'entry_sbz',
     'display_label' => 'ZDOBYCIE SBZ', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
    ['id' => 'v_003', 'source' => ['type' => 'label', 'raw' => 'Inne'], 'canon' => null,
     'display_label' => 'Inne', 'color' => '#FFFFFF', 'sections' => ['bilans'], 'visible' => true],
], Configurator::SEKCJE);
ReportTemplates::saveNewVersion($klub3, $martwyStrzal, 1);
$meta = ['teams' => [], 'dictionary' => [
    'tags' => [['tag' => 'STRZAŁ', 'count' => 11], ['tag' => 'SBZ PODAJĄCY', 'count' => 20]],
    'labels' => [['label' => 'INNE', 'count' => 4]],
]];
Db::run("INSERT INTO matches (owner_id, club_id, status) VALUES (1, :c, 'draft')", ['c' => $klub3]);
$m = (int) Db::pdo()->lastInsertId();
Db::run('INSERT INTO imports (match_id, csv_path, checksum_csv, coverage_json) VALUES (:m, :c, :s, :j)',
    ['m' => $m, 'c' => '/dev/null', 's' => 'x', 'j' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
$imp = (int) Db::pdo()->lastInsertId();
AutoImport::autoZmienne($imp, Imports::find($imp));
$z = poNazwie(ReportTemplates::decodeConfig(ReportTemplates::current($klub3)['config'])['variables']);
check('„STRZAŁ" NIE jest aliasem „Strzał" (przemianowałby strzały)',
    !in_array('STRZAŁ', $z['tag|Strzał']['aliases'] ?? [], true));
check('SBZ PODAJĄCY jest aliasem ZDOBYCIE SBZ (nazwa główna dokładnie z aliasy.json)',
    in_array('SBZ PODAJĄCY', $z['tag|ZDOBYCIE SBZ']['aliases'] ?? [], true));
check('etykieta „INNE" jest aliasem „Inne" (etykiet szablon nie przemianowuje)',
    in_array('INNE', $z['label|Inne']['aliases'] ?? [], true));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
