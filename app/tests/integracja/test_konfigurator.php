<?php
declare(strict_types=1);

/**
 * Konfigurator raportu klubu — model (Sesje 3+4 przebudowy).
 *
 * Sprawdzane twierdzenia:
 *  - słownik buduje się z bloku `dictionary`, więc obejmuje TAKŻE tagi, które
 *    silnik rozpoznaje (i których nie ma w `unmapped_tags`),
 *  - podpowiedź niesie POZIOM PEWNOŚCI, a etykieta nie dostaje pojęcia
 *    zarezerwowanego dla tagów,
 *  - twarda zasada: zmienna bez pojęcia kanonicznego wchodzi wyłącznie
 *    do bilansu i na oś czasu — walidacja odrzuca resztę,
 *  - identyfikatory sekcji zgadzają się z silnikiem (podkreślenia, nie myślniki),
 *  - zapis idzie przez `ReportTemplates`, czyli append-only.
 *
 * Uruchomienie:  php test_konfigurator.php
 */

use CoachAnalyze\Configurator;
use CoachAnalyze\HeuristicSuggester;
use CoachAnalyze\Mappings;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\Suggester;

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

$baza    = $here . '/konfigurator.sqlite';
$envFile = $here . '/.env.konfigurator';
$magazyn = $here . '/konfigurator_storage';

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

// ---------------------------------------------------------------- sekcje
echo "== identyfikatory sekcji zgodne z silnikiem ==\n";

/*
 * Specyfikacja przebudowy podawała `tl-sbz` z MYŚLNIKIEM, silnik zna wyłącznie
 * `tl_sbz`. Templat z myślnikami przeszedłby walidację po stronie PHP i wywrócił
 * się dopiero przy renderze, komunikatem „Sekcja nieznana silnikowi".
 */
$zSilnika = [];
$plik = $root . '/engine/coachanalyze/coverage.py';
if (preg_match('/ALL_SECTIONS\s*=\s*\(([^)]*)\)/', (string) file_get_contents($plik), $m) === 1) {
    preg_match_all('/"([a-z_]+)"/', $m[1], $mm);
    $zSilnika = $mm[1];
}

check('udało się odczytać ALL_SECTIONS z silnika', $zSilnika !== []);
check('lista sekcji PHP === lista silnika', Configurator::SEKCJE === $zSilnika,
    'PHP: ' . implode(',', Configurator::SEKCJE) . ' | silnik: ' . implode(',', $zSilnika));
check('żadna sekcja nie ma myślnika',
    !preg_match('/-/', implode('', Configurator::SEKCJE)),
    'silnik zna wyłącznie podkreślenia');
check('sekcje generyczne są podzbiorem wszystkich',
    array_diff(Configurator::SEKCJE_GENERYCZNE, Configurator::SEKCJE) === []);

// ---------------------------------------------------------------- podpowiedzi
echo "\n== Suggester: poziom pewności ==\n";

$s = new HeuristicSuggester();
$znane = Mappings::domyslneTagi();

$literowka = $s->suggest(Suggester::TAG, '1x1 DEF', $znane);
check('bliska nazwa daje podpowiedź', $literowka['canon'] === 'duel', (string) $literowka['canon']);
check('bliska nazwa jest PEWNA albo PRAWDOPODOBNA',
    in_array($literowka['confidence'], [Suggester::PEWNA, Suggester::PRAWDOPODOBNA], true),
    $literowka['confidence']);

$obcy = $s->suggest(Suggester::TAG, 'ZUPEŁNIE INNY BYT', $znane);
check('nazwa bez związku nie dostaje podpowiedzi', $obcy['canon'] === null, (string) $obcy['canon']);
check('brak podpowiedzi ma poziom BRAK', $obcy['confidence'] === Suggester::BRAK);

/*
 * Etykieta mapuje się na KWALIFIKATOR. Gdyby przeszło pojęcie tagu, operator
 * zobaczyłby propozycję, której walidacja i tak nie przyjmie.
 */
$etykieta = $s->suggest(Suggester::ETYKIETA, 'STRZAŁ', $znane);
check('etykieta nie dostaje pojęcia zarezerwowanego dla tagów',
    $etykieta['canon'] === null || in_array($etykieta['canon'], Mappings::KWALIFIKATORY, true),
    (string) $etykieta['canon']);

check('źródło podpowiedzi jest nazwane', $s->name() !== '');

// ---------------------------------------------------------------- słownik
echo "\n== zmienne ze słownika importu ==\n";

$meta = [
    'events' => 6,
    // Blok `dictionary` z silnika: PEŁNY słownik, także tagi rozpoznane.
    'dictionary' => [
        'tags' => [
            ['tag' => 'STRZAŁ', 'count' => 3, 'samples' => [
                ['b' => 1.0, 'team' => 'NASZA', 'labels' => ['CELNY']],
            ]],
            ['tag' => 'TAG WŁASNY', 'count' => 2, 'samples' => []],
        ],
        'labels' => [
            ['label' => 'CELNY', 'count' => 1, 'samples' => []],
        ],
    ],
    // Tag rozpoznany przez silnik NIE JEST tutaj — i o to chodzi.
    'unmapped_tags' => [['tag' => 'TAG WŁASNY', 'count' => 2]],
];

$zmienne = Configurator::zmienneZeSlownika($meta, $s, 1, [], ['#E8722C']);

check('powstały zmienne dla wszystkich pozycji słownika', count($zmienne) === 3, (string) count($zmienne));

$poNazwie = [];
foreach ($zmienne as $z) {
    $poNazwie[$z['source']['raw']] = $z;
}

check('tag ROZPOZNANY przez silnik też jest zmienną',
    isset($poNazwie['STRZAŁ']),
    'to jest cały powód bloku `dictionary` — bez niego ta pozycja nie istnieje');
check('rozpoznany tag dostał podpowiedź pojęcia',
    ($poNazwie['STRZAŁ']['canon'] ?? null) === 'shot');
check('licznik wystąpień przepisany z importu', ($poNazwie['STRZAŁ']['count'] ?? 0) === 3);
check('próbka przepisana z importu', count($poNazwie['STRZAŁ']['samples'] ?? []) === 1);
check('etykieta ma typ label', ($poNazwie['CELNY']['source']['type'] ?? '') === Suggester::ETYKIETA);

check('identyfikatory są unikalne',
    count(array_unique(array_column($zmienne, 'id'))) === count($zmienne));
check('domyślne sekcje to wyłącznie generyczne',
    $poNazwie['TAG WŁASNY']['sections'] === Configurator::SEKCJE_GENERYCZNE,
    'propozycja nie ma przesądzać o kształcie raportu');
check('etykieta wyświetlana proponowana z nazwy',
    ($poNazwie['STRZAŁ']['display_label'] ?? '') === 'Strzał',
    (string) ($poNazwie['STRZAŁ']['display_label'] ?? ''));
check('barwa z barw klubu przy braku palety',
    preg_match('/^#[0-9A-F]{6}$/', (string) $poNazwie['STRZAŁ']['color']) === 1);

$zPaleta = Configurator::zmienneZeSlownika(
    $meta, $s, 1, ['tags' => ['STRZAŁ' => '#123456']], ['#E8722C']
);
check('paleta z pliku projektu wygrywa z barwami klubu',
    $zPaleta[0]['color'] === '#123456', $zPaleta[0]['color']);

// ---------------------------------------------------------------- walidacja
echo "\n== twarda walidacja configu ==\n";

$poprawna = [[
    'id' => 'v_001',
    'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
    'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
    'sections' => ['bilans', 'mapy'], 'visible' => true,
]];

check('poprawny config przechodzi',
    Configurator::bledyConfigu(Configurator::config($poprawna, ['bilans', 'mapy'])) === []);

check('config bez zmiennych odrzucony',
    in_array('conf.err.no_variables', Configurator::bledyConfigu(Configurator::config([], ['bilans'])), true));

check('config bez sekcji odrzucony',
    in_array('conf.err.no_sections', Configurator::bledyConfigu(Configurator::config($poprawna, [])), true));

/*
 * SEDNO SESJI 4: zmienna bez pojęcia kanonicznego wchodzi wyłącznie do bilansu
 * i na oś czasu. Mapy i xG wymagają semantyki.
 */
$bezCanon = $poprawna;
$bezCanon[0]['canon'] = null;
$bezCanon[0]['sections'] = ['bilans', 'mapy'];
check('zmienna BEZ pojęcia nie wejdzie do map',
    in_array('conf.err.canon_required',
        Configurator::bledyConfigu(Configurator::config($bezCanon, ['bilans', 'mapy'])), true),
    'to jest twarda zasada, nie podpowiedź w interfejsie');

$bezCanonOk = $bezCanon;
$bezCanonOk[0]['sections'] = Configurator::SEKCJE_GENERYCZNE;
check('zmienna BEZ pojęcia wchodzi do bilansu i osi czasu',
    Configurator::bledyConfigu(Configurator::config($bezCanonOk, Configurator::SEKCJE_GENERYCZNE)) === []);

$zlyCanon = $poprawna;
$zlyCanon[0]['canon'] = 'nie_ma_takiego_pojecia';
check('pojęcie spoza słownika odrzucone',
    in_array('conf.err.unknown_canon',
        Configurator::bledyConfigu(Configurator::config($zlyCanon, ['bilans', 'mapy'])), true));

// Etykieta z pojęciem tagu — mieszanie pojęć z kwalifikatorami rozjeżdża bilans.
$etykietaZPojeciem = $poprawna;
$etykietaZPojeciem[0]['source'] = ['type' => 'label', 'raw' => 'CELNY'];
check('etykieta z pojęciem zarezerwowanym dla tagu odrzucona',
    in_array('conf.err.unknown_canon',
        Configurator::bledyConfigu(Configurator::config($etykietaZPojeciem, ['bilans', 'mapy'])), true));

$duplikat = [$poprawna[0], $poprawna[0]];
$bledyDup = Configurator::bledyConfigu(Configurator::config($duplikat, ['bilans', 'mapy']));
check('powtórzony identyfikator odrzucony', in_array('conf.err.duplicate_id', $bledyDup, true));
check('powtórzone źródło odrzucone', in_array('conf.err.duplicate_source', $bledyDup, true));

$zlaBarwa = $poprawna;
$zlaBarwa[0]['color'] = 'czerwony';
check('barwa spoza formatu odrzucona',
    in_array('conf.err.color',
        Configurator::bledyConfigu(Configurator::config($zlaBarwa, ['bilans', 'mapy'])), true));

$bezEtykiety = $poprawna;
$bezEtykiety[0]['display_label'] = '   ';
check('pusta etykieta odrzucona',
    in_array('conf.err.label',
        Configurator::bledyConfigu(Configurator::config($bezEtykiety, ['bilans', 'mapy'])), true));

$sekcjaWylaczona = $poprawna;
check('sekcja zmiennej spoza sekcji włączonych odrzucona',
    in_array('conf.err.section_disabled',
        Configurator::bledyConfigu(Configurator::config($sekcjaWylaczona, ['bilans'])), true),
    'deklaracja bez skutku jest myląca, a nie nieszkodliwa');

check('nieznana sekcja odrzucona',
    in_array('conf.err.unknown_section',
        Configurator::bledyConfigu(Configurator::config($poprawna, ['bilans', 'tl-sbz'])), true),
    'myślnik zamiast podkreślenia to dokładnie ta pułapka');

// ---------------------------------------------------------------- kształt configu
echo "\n== kształt zapisywanego configu ==\n";

$config = Configurator::config($poprawna, ['bilans', 'mapy']);

check('schema_version zgodny z ReportTemplates',
    $config['schema_version'] === ReportTemplates::SCHEMA_VERSION);
check('team_us_rule niesie markery z korektą MASZA',
    in_array('MASZA', $config['team_us_rule']['markers'], true),
    'literówka klienta jest cechą stałą produktu');

$zmiennaConfig = $config['variables'][0];
check('config NIE niesie pól roboczych konfiguratora',
    !isset($zmiennaConfig['count'], $zmiennaConfig['samples'], $zmiennaConfig['confidence']),
    'liczby z jednego importu nie opisują templatu klubu na stałe');
check('config niesie wyłącznie pola kontraktu',
    array_keys($zmiennaConfig) === ['id', 'source', 'canon', 'display_label', 'color', 'sections', 'visible'],
    implode(',', array_keys($zmiennaConfig)));

$pods = Configurator::podsumowanie($config);
check('podsumowanie liczy zmienne i kanoniczne',
    $pods['variables'] === 1 && $pods['canon'] === 1 && $pods['custom'] === 0);

// ---------------------------------------------------------------- zapis
echo "\n== zapis templatu przez ReportTemplates (append-only) ==\n";

$v1 = ReportTemplates::saveNewVersion(1, $config, 1);
check('pierwszy zapis daje wersję 1', $v1 === 1, (string) $v1);

$config2 = Configurator::config($poprawna, ['bilans']);
$config2['variables'][0]['sections'] = ['bilans'];
$v2 = ReportTemplates::saveNewVersion(1, $config2, 1);
check('drugi zapis daje wersję 2', $v2 === 2, (string) $v2);
check('wersja 1 nietknięta',
    ReportTemplates::decodeConfig(ReportTemplates::version(1, 1)['config'])['sections_enabled']
        === ['bilans', 'mapy']);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
