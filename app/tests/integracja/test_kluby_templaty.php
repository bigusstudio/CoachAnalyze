<?php
declare(strict_types=1);

/**
 * Sesja 1 przebudowy pod kluby: templaty raportów, tagi ignorowane, tenant.
 *
 * Sprawdzane twierdzenia:
 *  - templat jest WERSJONOWANY przez dopisywanie: zapis tworzy nowy wiersz,
 *    poprzedni zostaje nietknięty, aktualny to zawsze MAX(version),
 *  - „zignoruj na stałe" jest idempotentne i porównuje PEŁNĄ nazwę,
 *    nie fragment (CELNY ⊂ NIECELNY — pułapka 7 z CLAUDE.md),
 *  - `details` klubu nie znika przy zapisie formularza, który ich nie przesyła,
 *  - inwariant backfillu: `matches.club_id = club_home_id` tam, gdzie strona
 *    „nasza" jest znana.
 *
 * Bez HTTP i bez Redisa — sam model na SQLite.
 *
 * Uruchomienie:  php test_kluby_templaty.php
 */

use CoachAnalyze\Clubs;
use CoachAnalyze\Db;
use CoachAnalyze\IgnoredTags;
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

$baza    = $here . '/kluby.sqlite';
$magazyn = $here . '/kluby_storage';
$envFile = $here . '/.env.kluby';

@unlink($baza);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn, 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn,
    'APP_URL=http://127.0.0.1',
    'ARGON_MEMORY_COST=8192',
    'ARGON_TIME_COST=1',
    '',
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

// ---------------------------------------------------------------- backfill
echo "== inwariant tenanta w danych po migracji 012 ==\n";

$rozjazd = Db::all(
    'SELECT id, club_id, club_home_id FROM matches
      WHERE club_home_id IS NOT NULL AND club_id <> club_home_id'
);
check('club_id = club_home_id wszędzie, gdzie strona „nasza" jest znana',
    $rozjazd === [], 'rozjechanych wierszy: ' . count($rozjazd));

$bezTenanta = (int) Db::one('SELECT COUNT(*) AS c FROM matches WHERE club_id IS NULL')['c'];
check('żaden mecz nie został bez tenanta', $bezTenanta === 0, 'bez club_id: ' . $bezTenanta);

check('mecz bez przypisanej strony też ma tenanta (klub domyślny)',
    (int) Db::one('SELECT club_id FROM matches WHERE club_home_id IS NULL LIMIT 1')['club_id'] === 1);

check('domyślny tenant to klub oznaczony jako własna drużyna',
    Clubs::tenantDefault() === 1, (string) Clubs::tenantDefault());

// ---------------------------------------------------------------- templaty
echo "\n== templat: wersjonowanie przez dopisywanie ==\n";

check('klub bez konfiguratora nie ma templatu', ReportTemplates::current(1) === null);
check('wersja klubu bez templatu to 0', ReportTemplates::currentVersion(1) === 0);

$configV1 = [
    'team_us_rule'      => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled'  => ['bilans', 'mapy'],
    'variables'         => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ NASZA'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
    ],
];

$v1 = ReportTemplates::saveNewVersion(1, $configV1, 1);
check('pierwszy zapis daje wersję 1', $v1 === 1, (string) $v1);

$configV2 = $configV1;
$configV2['sections_enabled'][] = 'tl-sbz';
$v2 = ReportTemplates::saveNewVersion(1, $configV2, 1);
check('drugi zapis daje wersję 2', $v2 === 2, (string) $v2);

check('aktualny templat to najnowsza wersja',
    (int) (ReportTemplates::current(1)['version'] ?? 0) === 2);

$stara = ReportTemplates::version(1, 1);
check('wersja 1 ZOSTAJE nietknięta', $stara !== null);
check('treść wersji 1 się nie zmieniła',
    $stara !== null
    && ReportTemplates::decodeConfig($stara['config'])['sections_enabled'] === ['bilans', 'mapy'],
    'poprzednie wersje mają być tylko dopisywane, nigdy nadpisywane');

$historia = ReportTemplates::history(1);
check('historia od najnowszej', count($historia) === 2 && (int) $historia[0]['version'] === 2);

check('templat innego klubu jest niezależny', ReportTemplates::current(2) === null);
$innyKlub = ReportTemplates::saveNewVersion(2, $configV1, 1);
check('licznik wersji jest per klub, nie globalny', $innyKlub === 1, (string) $innyKlub);

echo "\n== stempel wersji na raporcie ==\n";

check('klub bez templatu: raport nie jest przeterminowany',
    ReportTemplates::isOutdated(3, null) === false,
    'bez templatu nie ma do czego porównywać');
check('raport sprzed ery templatów jest nieaktualny, gdy templat już jest',
    ReportTemplates::isOutdated(1, null) === true);
check('raport z wersji starszej jest nieaktualny', ReportTemplates::isOutdated(1, 1) === true);
check('raport z wersji bieżącej jest aktualny', ReportTemplates::isOutdated(1, 2) === false);

echo "\n== zapis configu ==\n";

$zapisany = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
check('schema_version dokładany automatycznie',
    ($zapisany['schema_version'] ?? null) === ReportTemplates::SCHEMA_VERSION);
check('polskie znaki zapisane czytelnie, nie jako \\uXXXX',
    str_contains((string) ReportTemplates::current(1)['config'], 'STRZAŁ'));

$odrzucony = false;
try {
    ReportTemplates::saveNewVersion(1, ['sections_enabled' => []], 1);
} catch (\InvalidArgumentException $e) {
    $odrzucony = true;
}
check('templat bez listy zmiennych jest odrzucany', $odrzucony,
    'nie ma czego renderować, a pusty wiersz w bazie wyglądałby jak poprawny');

// ---------------------------------------------------------------- ignorowane
echo "\n== tagi ignorowane na stałe ==\n";

check('nowa pozycja zapisana', IgnoredTags::add(1, IgnoredTags::TAG, 'ŚMIECIOWY TAG', 1) === true);
check('powtórzenie tej samej pozycji nie jest błędem',
    IgnoredTags::add(1, IgnoredTags::TAG, 'ŚMIECIOWY TAG', 1) === false,
    'import nie ma prawa wywalić się na drugim kliknięciu „zignoruj"');

check('pozycja jest rozpoznawana', IgnoredTags::has(1, IgnoredTags::TAG, 'ŚMIECIOWY TAG'));

/*
 * Pułapka 7 z CLAUDE.md: dopasowanie po zawieraniu łapie CELNY wewnątrz
 * NIECELNY. Tu ta sama zasada — porównujemy pełną nazwę.
 */
IgnoredTags::add(1, IgnoredTags::LABEL, 'CELNY', 1);
check('NIECELNY nie jest zignorowany przez wpis CELNY',
    IgnoredTags::has(1, IgnoredTags::LABEL, 'NIECELNY') === false);

check('tag i etykieta o tej samej nazwie to dwie różne pozycje',
    IgnoredTags::has(1, IgnoredTags::TAG, 'CELNY') === false
    && IgnoredTags::has(1, IgnoredTags::LABEL, 'CELNY') === true);

$lookup = IgnoredTags::lookup(1);
check('lookup rozdziela tagi od etykiet',
    isset($lookup['tag']['ŚMIECIOWY TAG']) && isset($lookup['label']['CELNY'])
    && !isset($lookup['tag']['CELNY']));

check('pozycje innego klubu nie przeciekają', IgnoredTags::lookup(2) === ['tag' => [], 'label' => []]);

$doUsuniecia = IgnoredTags::all(1)[0]['id'];
IgnoredTags::forget(1, (int) $doUsuniecia, 1);
check('decyzję da się cofnąć', count(IgnoredTags::all(1)) === 1);

// ---------------------------------------------------------------- klub
echo "\n== pola opisowe klubu ==\n";

Clubs::setDetails(1, ['miasto' => 'Kraków', 'liga' => 'IV liga', 'pusto' => ''], 1);
$klub = Clubs::find(1);
$details = Clubs::decodeDetails($klub['details'] ?? null);
check('details zapisane', ($details['miasto'] ?? null) === 'Kraków');
check('puste pole formularza nie jest zapisywane jako pusty napis',
    !array_key_exists('pusto', $details));
check('updated_at ustawione przy zapisie', !empty($klub['updated_at']));

// Formularz edycji klubu NIE przesyła `details` — i nie ma prawa ich skasować.
Clubs::update(1, 1, [
    'name' => 'Klub A', 'short_name' => '', 'color_primary' => '#E8722C',
    'color_secondary' => '', 'is_own_team' => 1, 'aliases' => [],
]);
$poEdycji = Clubs::decodeDetails(Clubs::find(1)['details'] ?? null);
check('zapis nazwy i barw NIE kasuje pól opisowych',
    ($poEdycji['miasto'] ?? null) === 'Kraków',
    'pole, którego nie ma w formularzu, nie może zniknąć przy zapisie tego formularza');

check('klub odnajdywany po kluczu publicznym',
    (int) (Clubs::findByKey('HUT7K2QX')['id'] ?? 0) === 1);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
