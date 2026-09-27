<?php
declare(strict_types=1);

/**
 * Tożsamość nazw zmiennych, aliasy, naprawa templatu i sezon domyślny (0.16.3).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ODTWARZA ODBIÓR NA POGONI (import meczu 26, templat v5):
 *
 *   - etykieta „INNE" w eksporcie, „Inne" w templacie. Import uznał ją za
 *     znaną (normalizacja), ekran różnic za nową (dosłownie) — operator dodał
 *     drugą zmienną. `INNE` siedzi tu wyłącznie na tagach BEZ DRUŻYNY
 *     (SKUTECZNY/NISKUTECZNY), tak jak w eksporcie klienta: to nie było
 *     przyczyną, ale test ma dowieść, że i taki kształt przechodzi,
 *   - `SBZ PODAJĄCY` założony jako osobna zmienna, choć silnik liczy go
 *     jako `ZDOBYCIE SBZ` (`aliasy.json`),
 *   - „Zdobycie Sbz" jako etykieta, której nikt nie wybierał.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php test_nazwy_zmiennych.php
 */

use CoachAnalyze\AutoImport;
use CoachAnalyze\Configurator;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\NaprawaTemplatu;
use CoachAnalyze\NazwaZmiennej;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\TemplateDiff;

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

$baza    = $here . '/nazwy.sqlite';
$envFile = $here . '/.env.nazwy';
$magazyn = $here . '/nazwy_storage';

@unlink($baza);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn, 0770, true);

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

/** Zmienna templatu w kształcie `Configurator::config`. */
function zm(string $id, string $typ, string $raw, string $etykieta, array $sekcje = ['bilans', 'tl_bilans']): array
{
    return ['id' => $id, 'source' => ['type' => $typ, 'raw' => $raw], 'canon' => null,
            'display_label' => $etykieta, 'color' => '#8899AA', 'sections' => $sekcje,
            'aliases' => [], 'visible' => true];
}

/** Pozycja słownika `meta.dictionary`. */
function poz(string $typ, string $nazwa, int $ile, array $probki = []): array
{
    return [$typ => $nazwa, 'count' => $ile, 'with_pos' => 0, 'with_xg' => 0, 'samples' => $probki];
}

/** @return array<string,array<string,mixed>> zmienne po `typ|raw` */
function poNazwie(array $config): array
{
    $out = [];
    foreach ((array) $config['variables'] as $z) {
        $out[$z['source']['type'] . '|' . $z['source']['raw']] = $z;
    }
    return $out;
}

// ===========================================================================
echo "== NazwaZmiennej::klucz — jedna definicja „ta sama nazwa\" ==\n";

check('„INNE" i „Inne" to ta sama nazwa', NazwaZmiennej::rowne('INNE', 'Inne'));
check('spacje nadmiarowe nie rozróżniają', NazwaZmiennej::rowne('  III   STREFA ', 'iii strefa'));
check('łącznik = spacja (także półpauza)',
    NazwaZmiennej::rowne('Pogoń-Sokół', 'POGOŃ SOKÓŁ') && NazwaZmiennej::rowne('A – B', 'a b'));
check('pułapka 7: CELNY ≠ NIECELNY', !NazwaZmiennej::rowne('CELNY', 'NIECELNY'));
check('fragment nie wystarcza: SBZ PODAJĄCY ≠ SBZ PODAJĄCY/OTRZYMUJĄCY',
    !NazwaZmiennej::rowne('SBZ PODAJĄCY', 'SBZ PODAJĄCY/OTRZYMUJĄCY'));

check('kopia aliasy.json identyczna z plikiem silnika',
    file_get_contents(NazwaZmiennej::plikAliasow())
        === file_get_contents($root . '/engine/coachanalyze/config/aliasy.json'),
    'PHP-FPM nie czyta engine/ (open_basedir) — skopiuj plik silnika do app/src/data/');
check('alias silnika SBZ PODAJĄCY → ZDOBYCIE SBZ',
    (NazwaZmiennej::aliasySilnika()[NazwaZmiennej::klucz('SBZ PODAJĄCY')] ?? '') === 'ZDOBYCIE SBZ');

check('etykieta proponowana = surowa nazwa, bez zmiany wielkości liter',
    Configurator::etykietaZNazwy('ZDOBYCIE  SBZ') === 'ZDOBYCIE SBZ'
    && Configurator::etykietaZNazwy('1x1 OFF') === '1x1 OFF');

// ===========================================================================
echo "\n== import: „INNE\" przy „Inne\", SBZ PODAJĄCY przy ZDOBYCIE SBZ ==\n";

$konfig = Configurator::config([
    zm('v_001', 'tag', 'ZDOBYCIE SBZ', 'Zdobycie SBZ'),
    zm('v_002', 'tag', 'III STREFA', 'III strefa'),
    zm('v_003', 'tag', 'SKUTECZNY', 'Skuteczny'),
    zm('v_004', 'tag', 'NISKUTECZNY', 'Niskuteczny'),
    zm('v_005', 'label', 'Inne', 'Inne'),
], Configurator::SEKCJE);
check('templat v1 klubu 1', ReportTemplates::saveNewVersion(1, $konfig, 1) === 1);

$bezDruzyny = ['team' => null, 'labels' => ['INNE']];
$meta = [
    'teams' => [],
    'dictionary' => [
        'tags' => [
            poz('tag', 'ZDOBYCIE SBZ', 11), poz('tag', 'SBZ PODAJĄCY', 20),
            poz('tag', 'III STREFA PODAJĄCY/OTRZYMUJĄCY', 12),
            poz('tag', 'SKUTECZNY', 15, [$bezDruzyny]), poz('tag', 'NISKUTECZNY', 2, [$bezDruzyny]),
            poz('tag', 'DRUGI KONTAKT', 33),
        ],
        'labels' => [poz('label', 'INNE', 4, [$bezDruzyny]), poz('label', 'REAKCJA', 1)],
    ],
];

Db::run("INSERT INTO matches (owner_id, club_id, status) VALUES (1, 1, 'draft')");
$meczId = (int) Db::pdo()->lastInsertId();
Db::run('INSERT INTO imports (match_id, csv_path, checksum_csv, coverage_json) VALUES (:m, :c, :s, :j)',
    ['m' => $meczId, 'c' => '/dev/null', 's' => 'x', 'j' => json_encode($meta, JSON_UNESCAPED_UNICODE)]);
$importId = (int) Db::pdo()->lastInsertId();

$przed = TemplateDiff::policz($meta, $konfig, ['tag' => [], 'label' => []]);
$nowePrzed = array_column($przed['nowe'], 'name');
check('ekran różnic: INNE jest ZNANA (ta sama definicja co import)',
    !in_array('INNE', $nowePrzed, true), implode(', ', $nowePrzed));
check('ekran różnic: SBZ PODAJĄCY i III STREFA PODAJĄCY/… znane przez alias silnika',
    !in_array('SBZ PODAJĄCY', $nowePrzed, true)
    && !in_array('III STREFA PODAJĄCY/OTRZYMUJĄCY', $nowePrzed, true), implode(', ', $nowePrzed));
check('ekran różnic: nowe to dokładnie DRUGI KONTAKT i REAKCJA',
    $nowePrzed === ['DRUGI KONTAKT', 'REAKCJA'], implode(', ', $nowePrzed));

[$dodane, $wersja] = AutoImport::autoZmienne($importId, Imports::find($importId));
check('auto: dodane DRUGI KONTAKT i REAKCJA — i nic więcej',
    $dodane === ['DRUGI KONTAKT', 'REAKCJA'], implode(', ', $dodane));
check('auto: wersja 2', $wersja === 2, var_export($wersja, true));

$config = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
$z = poNazwie($config);
check('auto: nowa zmienna ma etykietę = surowa nazwa',
    ($z['tag|DRUGI KONTAKT']['display_label'] ?? '') === 'DRUGI KONTAKT');
check('auto: „INNE" dopisane jako alias zmiennej „Inne", bez drugiej zmiennej',
    !isset($z['label|INNE']) && in_array('INNE', $z['label|Inne']['aliases'] ?? [], true));
check('auto: SBZ PODAJĄCY → alias ZDOBYCIE SBZ, bez osobnej zmiennej',
    !isset($z['tag|SBZ PODAJĄCY'])
    && in_array('SBZ PODAJĄCY', $z['tag|ZDOBYCIE SBZ']['aliases'] ?? [], true));
check('auto: III STREFA PODAJĄCY/OTRZYMUJĄCY → alias III STREFA',
    in_array('III STREFA PODAJĄCY/OTRZYMUJĄCY', $z['tag|III STREFA']['aliases'] ?? [], true));
check('auto: notka wersji dosłowna — ekran pokrycia ją znajduje',
    (ReportTemplates::autoForImport(1, $importId)['added'] ?? null) === ['DRUGI KONTAKT', 'REAKCJA']);

$po = TemplateDiff::policz($meta, $config, ['tag' => [], 'label' => []]);
check('po imporcie „Poza templatem klubu" jest puste', $po['nowe'] === [],
    implode(', ', array_column($po['nowe'], 'name')));

[$dodane2, $wersja2] = AutoImport::autoZmienne($importId, Imports::find($importId));
check('drugi przebieg nie tworzy wersji', $dodane2 === [] && $wersja2 === null
    && ReportTemplates::currentVersion(1) === 2);

// ===========================================================================
echo "\n== skrypt naprawczy: templat w kształcie Pogoni ==\n";

$klub = 4;
$v1 = Configurator::config([
    zm('v_001', 'label', 'Inne', 'Inne'),
    zm('v_002', 'tag', 'ZDOBYCIE SBZ', 'Zdobycie Sbz'),
    zm('v_003', 'tag', 'Posiadanie Stal', 'Posiadanie Stal'),
    zm('v_004', 'tag', 'SBZ PODAJĄCY', 'SBZ PODAJĄCY', ['bilans', 'mapy', 'tl_bilans']),
    zm('v_005', 'label', 'INNE', 'Inne'),
], Configurator::SEKCJE);
ReportTemplates::saveNewVersion($klub, $v1, 1);
$v2 = $v1;
$v2['variables'][] = zm('v_006', 'tag', 'STRATA NA PP', 'Strata Na Pp');
ReportTemplates::saveNewVersion($klub, $v2, null, 'auto: import #999');

foreach ([['tag', 'ZDOBYCIE SBZ'], ['tag', 'SBZ PODAJĄCY'], ['tag', 'STRATA NA PP'], ['label', 'INNE']] as [$k, $n]) {
    Db::run('INSERT INTO tag_catalog (club_id, kind, name, seen_matches, seen_events) VALUES (:c, :k, :n, 1, 1)',
        ['c' => $klub, 'k' => $k, 'n' => $n]);
}

function naprawa(string ...$argi): array
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' php '
        . escapeshellarg($root . '/app/repairs/napraw_auto_etykiety.php')
        . ' ' . implode(' ', array_map('escapeshellarg', $argi)) . ' 2>&1', $wyjscie, $kod);
    return [$kod, implode("\n", $wyjscie)];
}

[$kod, $out] = naprawa('--club', (string) $klub);
check('podgląd kończy się zerem', $kod === 0, $out);
check('podgląd niczego nie zapisuje', ReportTemplates::currentVersion($klub) === 2);
check('podgląd wymienia oba scalenia',
    str_contains($out, 'v_004 „SBZ PODAJĄCY"') && str_contains($out, 'v_001 „Inne"'), $out);

[$kod, $out] = naprawa('--club', (string) $klub, '--zapisz');
check('zapis tworzy wersję 3', $kod === 0 && ReportTemplates::currentVersion($klub) === 3, $out);
$cfg = ReportTemplates::decodeConfig(ReportTemplates::current($klub)['config']);
$z = poNazwie($cfg);
check('SBZ PODAJĄCY scalony w ZDOBYCIE SBZ, sekcja mapy przeszła',
    !isset($z['tag|SBZ PODAJĄCY'])
    && in_array('SBZ PODAJĄCY', $z['tag|ZDOBYCIE SBZ']['aliases'] ?? [], true)
    && in_array('mapy', $z['tag|ZDOBYCIE SBZ']['sections'] ?? [], true));
// Zostaje ŻYWA (nazwa w katalogu), nie najstarsza — regresja v6 Pogoni.
check('martwe „Inne" (v_001) scalone w żywe „INNE" (v_005)',
    !isset($z['label|Inne']) && ($z['label|INNE']['id'] ?? '') === 'v_005'
    && in_array('Inne', $z['label|INNE']['aliases'] ?? [], true));
check('etykieta zmiennej z wersji AUTO poprawiona na surową',
    ($z['tag|STRATA NA PP']['display_label'] ?? '') === 'STRATA NA PP');
check('etykieta zmiennej z wersji RĘCZNEJ nietknięta bez --takze-reczne',
    ($z['tag|ZDOBYCIE SBZ']['display_label'] ?? '') === 'Zdobycie Sbz');
check('wersja naprawy nie unieważnia raportów', ReportTemplates::isOutdated($klub, 1) === false);
check('martwa zmienna bez --usun-martwe zostaje', isset($z['tag|Posiadanie Stal']));

[$kod, $out] = naprawa('--club', (string) $klub, '--takze-reczne', '--zapisz');
$z = poNazwie(ReportTemplates::decodeConfig(ReportTemplates::current($klub)['config']));
check('--takze-reczne: „Zdobycie Sbz" → „ZDOBYCIE SBZ"',
    ($z['tag|ZDOBYCIE SBZ']['display_label'] ?? '') === 'ZDOBYCIE SBZ', $out);

[$kod, $out] = naprawa('--club', (string) $klub, '--wykaz-martwych');
check('wykaz martwych: Posiadanie Stal, i tylko ona',
    str_contains($out, 'Posiadanie Stal') && str_contains($out, 'martwe (nazwa nie wystąpiła w żadnym imporcie): 1'),
    $out);

$wersjaPrzed = ReportTemplates::currentVersion($klub);
[$kod, $out] = naprawa('--club', (string) $klub, '--usun-martwe', '--zapisz');
$z = poNazwie(ReportTemplates::decodeConfig(ReportTemplates::current($klub)['config']));
check('--usun-martwe usuwa Posiadanie Stal', !isset($z['tag|Posiadanie Stal'])
    && ReportTemplates::currentVersion($klub) === $wersjaPrzed + 1, $out);

$wersjaPrzed = ReportTemplates::currentVersion($klub);
[$kod, $out] = naprawa('--club', (string) $klub, '--takze-reczne', '--usun-martwe', '--zapisz');
check('powtórzenie jest bezpieczne: nic do zrobienia, bez nowej wersji',
    str_contains($out, 'nic do zrobienia') && ReportTemplates::currentVersion($klub) === $wersjaPrzed, $out);

Db::run('DELETE FROM tag_catalog');
[$kod, $out] = naprawa('--club', (string) $klub, '--wykaz-martwych');
check('pusty katalog tagów: brak podstaw, a nie „wszystko martwe"',
    str_contains($out, 'katalog tagów klubu jest pusty'), $out);

// ===========================================================================
echo "\n== sezon domyślny nowego meczu z importu ==\n";

$sezon = function (): ?int {
    $id = Imports::create(1, '/dev/null', null, 'x', 1);
    $m = Db::one('SELECT season_id FROM matches WHERE id = (SELECT match_id FROM imports WHERE id = :i)', ['i' => $id]);
    return $m['season_id'] !== null ? (int) $m['season_id'] : null;
};

$biezacy = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];
check('mecz dostaje sezon bieżący', $sezon() === $biezacy);

Db::run('UPDATE seasons SET is_current = 0');
Db::run("INSERT INTO seasons (owner_id, label, date_from, date_to, is_current) VALUES (1, '2025/2026', '2025-07-01', '2026-06-30', 0)");
check('bez sezonu bieżącego — najnowszy (po dacie, nie po id)', $sezon() === $biezacy);

Db::run('DELETE FROM seasons');
check('bez sezonów — NULL, jak dotąd', $sezon() === null);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
