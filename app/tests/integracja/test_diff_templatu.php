<?php
declare(strict_types=1);

/**
 * Diff słownika importu wobec templatu klubu (Sesja 6 przebudowy).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DWA TORY, ŚWIADOMIE.
 *
 * TOR REALNY — `engine/tests/golden/mecz_slownik2.csv`, eksport klienta
 * z INNYM tagowaniem niż referencyjny. Plik leży poza repozytorium (dane
 * taktyczne, CLAUDE.md §7), więc gdy go nie ma, tor jest GŁOŚNO POMIJANY,
 * a nie po cichu zaliczany. To on jest właściwym sprawdzeniem: syntetyk
 * pisze ten sam człowiek, który pisze kod, i potrafi przypadkiem ominąć
 * dokładnie ten kształt danych, który w praktyce sprawia kłopot.
 *
 * TOR SYNTETYCZNY — dziesięć wierszy WYPISANYCH W TYM PLIKU do pliku tymczasowego.
 * Pokrywa przypadki brzegowe (`1x1 DEF` jako wariant `1x1 DEF.`, etykiety obok
 * tagów) i chodzi zawsze, na każdym klonie. Dane leżały kiedyś obok, jako
 * `wiazownica.csv`, i zestaw padał u wszystkich poza autorem: `.gitignore`
 * wyklucza `*.csv` w całym repozytorium.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php test_diff_templatu.php
 */

use CoachAnalyze\Configurator;
use CoachAnalyze\IgnoredTags;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\TemplateDiff;

$root = dirname(__DIR__, 3);
$here = __DIR__;

$ok = 0;
$fail = 0;
$pominiete = [];

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

function pomin(string $name, string $powod): void
{
    global $pominiete;
    $pominiete[] = $name;
    echo "  POMINIĘTY  {$name} — {$powod}\n";
}

$baza    = $here . '/diff.sqlite';
$envFile = $here . '/.env.diff';
$magazyn = $here . '/diff_storage';

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

/** Słownik z pliku CSV w kształcie `meta.dictionary` — bez uruchamiania silnika. */
function slownikZCsv(string $sciezka): array
{
    $tagi = [];
    $etykiety = [];
    $fh = fopen($sciezka, 'r');
    $naglowki = fgetcsv($fh);
    while (($w = fgetcsv($fh)) !== false) {
        $r = array_combine($naglowki, array_pad($w, count($naglowki), ''));
        $tag = trim((string) ($r['tag_name'] ?? ''));
        if ($tag !== '') {
            $tagi[$tag] = ($tagi[$tag] ?? 0) + 1;
        }
        foreach (explode(',', (string) ($r['labels'] ?? '')) as $et) {
            $et = trim($et);
            if ($et !== '') {
                $etykiety[$et] = ($etykiety[$et] ?? 0) + 1;
            }
        }
    }
    fclose($fh);

    return [
        'tags'   => array_map(fn($n, $c) => ['tag' => $n, 'count' => $c, 'samples' => []],
                              array_keys($tagi), $tagi),
        'labels' => array_map(fn($n, $c) => ['label' => $n, 'count' => $c, 'samples' => []],
                              array_keys($etykiety), $etykiety),
    ];
}

/** Templat odwzorowujący słownik domyślny — punkt wyjścia dla diffu. */
function templatBazowy(): array
{
    $zmienne = [];
    $i = 0;
    foreach (['STRZAŁ' => 'shot', 'ZDOBYCIE SBZ' => 'entry_sbz', 'III STREFA' => 'entry_third',
              'STRATA' => 'loss', 'ODBIÓR' => 'recovery', '1x1 DEF.' => 'duel'] as $raw => $canon) {
        $zmienne[] = [
            'id' => sprintf('v_%03d', ++$i),
            'source' => ['type' => 'tag', 'raw' => $raw],
            'canon' => $canon, 'display_label' => ucfirst(mb_strtolower($raw)),
            'color' => '#8899AA', 'sections' => ['bilans', 'tl_bilans'], 'visible' => true,
        ];
    }
    return Configurator::config($zmienne, Configurator::SEKCJE);
}

// ===========================================================================
echo "== TOR REALNY: eksport klienta ze słownikiem #2 ==\n";

$realny = $root . '/engine/tests/golden/mecz_slownik2.csv';

if (!is_file($realny)) {
    pomin('diff na realnym eksporcie',
        'brak engine/tests/golden/mecz_slownik2.csv — dane klienta poza repozytorium');
} else {
    $meta = ['dictionary' => slownikZCsv($realny)];
    $config = templatBazowy();
    $diff = TemplateDiff::policz($meta, $config, ['tag' => [], 'label' => []]);

    check('realny eksport ma pozycje w słowniku',
        $diff['znane'] !== [] || $diff['nowe'] !== []);
    check('realny eksport przynosi tagi NIEZNANE templatowi',
        $diff['nowe'] !== [],
        'to jest cały powód istnienia ekranu diffu — słownik #2 ma się różnić');

    $sumaTagow = 0;
    foreach (TemplateDiff::pozycjeSlownika($meta) as $poz) {
        if ($poz['type'] === 'tag') {
            $sumaTagow += (int) $poz['count'];
        }
    }
    check('żadna pozycja nie gubi się między znane/nowe/ignorowane',
        count($diff['znane']) + count($diff['nowe']) + count($diff['ignorowane'])
            === count(TemplateDiff::pozycjeSlownika($meta)));

    echo "       (realny: znane=" . count($diff['znane'])
        . ", nowe=" . count($diff['nowe'])
        . ", zdarzeń z tagami=" . $sumaTagow . ")\n";
}

// ===========================================================================
echo "\n== TOR SYNTETYCZNY: dziesięć wierszy z tego pliku (przypadki brzegowe) ==\n";

/*
 * Dane syntetyczne powstają TUTAJ, nie leżą obok w pliku — powód przy
 * `ca_test_csv()` w seed.php (krótko: `.gitignore` wyklucza `*.csv` w całym
 * repozytorium, więc plik obok istniałby tylko u autora).
 */
$synt = ca_test_csv([
    ['tag_name', 'begin', 'end', 'team', 'labels', 'comment', 'pos_x_meters', 'pos_y_meters'],
    ['STRZAŁ',              '10',  '20',  'KLUB A', 'POZYCYJNIE, CELNY',   'X 0,5',   '88', '31'],
    ['AKCJA DEFENSYWNA',    '30',  '40',  'KLUB A', 'UDANA',               '',        '50', '30'],
    ['SBZ PODAJĄCY',        '45',  '55',  'KLUB A', 'STRZAŁ',              'xG 0,22', '85', '33'],
    // `1x1 DEF` bez kropki — wariant nazwy, który ma NIE zlać się z `1x1 DEF.`
    ['1x1 DEF',             '60',  '70',  'KLUB A', 'WYGRANY',             '',        '40', '20'],
    ['PRESSING WYSOKI',     '75',  '85',  'KLUB A', 'SKUTECZNY',           '',        '60', '25'],
    ['WYJŚCIE Z PRESSINGU', '90',  '99',  'KLUB A', 'UDANA',               '',        '35', '22'],
    ['DOŚRODKOWANIE',       '100', '110', 'KLUB A', 'CELNE',               '',        '80', '10'],
    ['STAŁY FRAGMENT',      '120', '130', 'KLUB A', 'ROŻNY',               '',        '88', '1'],
    ['STRZAŁ',              '140', '150', 'DRUZYNA SPOZA BAZY', 'POZYCYJNIE, NIECELNY', '', '20', '30'],
    ['STRATA',              '160', '170', 'DRUZYNA SPOZA BAZY', 'NASZA POŁOWA',         '', '30', '25'],
], 'ca_diff_');

check('dane syntetyczne zapisane', is_file($synt) && filesize($synt) > 0, $synt);

$meta = ['dictionary' => slownikZCsv($synt)];
$config = templatBazowy();
$diff = TemplateDiff::policz($meta, $config, ['tag' => [], 'label' => []]);

$noweNazwy = array_column(array_filter($diff['nowe'], fn($p) => $p['type'] === 'tag'), 'name');
$znaneNazwy = array_column(array_filter($diff['znane'], fn($p) => $p['type'] === 'tag'), 'name');

check('STRZAŁ znany templatowi — mapuje się cicho', in_array('STRZAŁ', $znaneNazwy, true));
check('STRATA znana templatowi', in_array('STRATA', $znaneNazwy, true));
check('PRESSING WYSOKI jest nowy', in_array('PRESSING WYSOKI', $noweNazwy, true));
check('SBZ PODAJĄCY jest nowy', in_array('SBZ PODAJĄCY', $noweNazwy, true));

/*
 * PUŁAPKA 7 na poziomie diffu: `1x1 DEF` i `1x1 DEF.` to DWIE RÓŻNE pozycje.
 * Dopasowanie przez zawieranie uznałoby je za tę samą i tag z eksportu
 * przepadłby po cichu, mapując się na regułę, która go nie dotyczy.
 */
check('1x1 DEF (bez kropki) jest NOWY mimo 1x1 DEF. w templacie',
    in_array('1x1 DEF', $noweNazwy, true),
    'dopasowanie musi być przez równość pełnej nazwy, nie zawieranie');
check('1x1 DEF. z templatu NIE jest zaliczony jako obecny w eksporcie',
    !in_array('1x1 DEF.', $noweNazwy, true) && !in_array('1x1 DEF.', $znaneNazwy, true),
    'eksport go nie zawiera, więc nie ma prawa pojawić się w żadnym z koszyków');

// ---------------------------------------------------------------- ignorowane
echo "\n== zignorowane na stałe nie wracają jako nowe ==\n";

$zIgnorem = TemplateDiff::policz($meta, $config, [
    'tag'   => ['PRESSING WYSOKI' => true],
    'label' => [],
]);
$noweZ = array_column($zIgnorem['nowe'], 'name');

check('zignorowany tag wypada z nowych', !in_array('PRESSING WYSOKI', $noweZ, true));
check('…ale JEST wyliczony jako zignorowany',
    in_array('PRESSING WYSOKI', array_column($zIgnorem['ignorowane'], 'name'), true),
    'zero cichego wyrzucania danych — musi być widoczny w raporcie pokrycia');

// ---------------------------------------------------------------- decyzje
echo "\n== decyzje: jedna wersja templatu na cały import ==\n";

$klucz = fn(string $t, string $n) => TemplateDiff::klucz($t, $n);

$decyzje = [
    $klucz('tag', 'PRESSING WYSOKI')     => TemplateDiff::DODAJ,
    $klucz('tag', 'SBZ PODAJĄCY')        => TemplateDiff::DODAJ,
    $klucz('tag', 'DOŚRODKOWANIE')       => TemplateDiff::POMIN,
    $klucz('tag', 'STAŁY FRAGMENT')      => TemplateDiff::NA_STALE,
];
$pola = [
    $klucz('tag', 'PRESSING WYSOKI') => ['canon' => 'press', 'display_label' => 'Pressing wysoki',
                                         'color' => '#112233', 'sections' => ['bilans', 'tl_bilans']],
    $klucz('tag', 'SBZ PODAJĄCY')    => ['canon' => 'entry_sbz', 'display_label' => '',
                                         'color' => null, 'sections' => ['bilans', 'mapy']],
];

check('są decyzje dopisujące', TemplateDiff::czyDopisuje($decyzje));

$nowyConfig = TemplateDiff::nowyConfig($config, $diff['nowe'], $decyzje, $pola);
$nazwyWConfigu = array_column(array_column($nowyConfig['variables'], 'source'), 'raw');

check('dopisano PRESSING WYSOKI', in_array('PRESSING WYSOKI', $nazwyWConfigu, true));
check('dopisano SBZ PODAJĄCY', in_array('SBZ PODAJĄCY', $nazwyWConfigu, true));
check('NIE dopisano pominiętego DOŚRODKOWANIE',
    !in_array('DOŚRODKOWANIE', $nazwyWConfigu, true));
check('NIE dopisano zignorowanego na stałe STAŁY FRAGMENT',
    !in_array('STAŁY FRAGMENT', $nazwyWConfigu, true));
check('zmienne z poprzedniego templatu zostają',
    in_array('STRZAŁ', $nazwyWConfigu, true) && in_array('1x1 DEF.', $nazwyWConfigu, true));

$idki = array_column($nowyConfig['variables'], 'id');
check('identyfikatory pozostają unikalne', count($idki) === count(array_unique($idki)),
    implode(',', $idki));
check('nowe identyfikatory ciągną numerację, nie zaczynają od nowa',
    in_array('v_007', $idki, true) && in_array('v_008', $idki, true),
    implode(',', $idki));

$dodany = null;
foreach ($nowyConfig['variables'] as $z) {
    if ($z['source']['raw'] === 'PRESSING WYSOKI') {
        $dodany = $z;
    }
}
check('dopisana zmienna niesie pojęcie z formularza', ($dodany['canon'] ?? null) === 'press');
check('dopisana zmienna niesie etykietę z formularza',
    ($dodany['display_label'] ?? '') === 'Pressing wysoki');
check('dopisana zmienna niesie barwę z formularza', ($dodany['color'] ?? '') === '#112233');

$bezEtykiety = null;
foreach ($nowyConfig['variables'] as $z) {
    if ($z['source']['raw'] === 'SBZ PODAJĄCY') {
        $bezEtykiety = $z;
    }
}
check('pusta etykieta zastąpiona propozycją z nazwy',
    ($bezEtykiety['display_label'] ?? '') !== '',
    'templat bez etykiety nie przeszedłby walidacji');

check('nowy config przechodzi twardą walidację',
    Configurator::bledyConfigu($nowyConfig) === [],
    implode(', ', Configurator::bledyConfigu($nowyConfig)));

// -------------------------------------------- Sesja 1 pivotu: canon opcjonalny
echo "\n== pojęcie kanoniczne opcjonalne: sekcje operatora zostają ==\n";

/*
 * Do sesji 1 `TemplateDiff::nowyConfig()` ODCINAŁO sekcje do generycznych, gdy
 * zmienna nie miała pojęcia kanonicznego. Ekran diffu pozwala dziś zaznaczyć
 * każdą sekcję, więc odcinanie po cichu wyrzucałoby wybór operatora między
 * kliknięciem a zapisem — a tego nie widać nigdzie poza gotowym raportem.
 * Zasada wycofana: docs/STAN_PIVOTU.md §2.3.
 */
$bezPojecia = TemplateDiff::nowyConfig(
    $config,
    $diff['nowe'],
    [$klucz('tag', 'DOŚRODKOWANIE') => TemplateDiff::DODAJ],
    [$klucz('tag', 'DOŚRODKOWANIE') => [
        'canon' => '',                       // operator nie wybrał pojęcia
        'display_label' => 'Dośrodkowanie',
        'color' => '#445566',
        'sections' => ['bilans', 'mapy'],    // ...ale wskazał mapy
    ]]
);

$dosrodkowanie = null;
foreach ($bezPojecia['variables'] as $z) {
    if ($z['source']['raw'] === 'DOŚRODKOWANIE') {
        $dosrodkowanie = $z;
    }
}

check('zmienna bez pojęcia została dopisana', $dosrodkowanie !== null);
// `??` reaguje TAKŻE na null, więc `$x['canon'] ?? 'brak'` nigdy nie zwróci
// null — sprawdzamy obecność klucza osobno od jego wartości.
check('nie ma pojęcia kanonicznego',
    array_key_exists('canon', $dosrodkowanie) && $dosrodkowanie['canon'] === null,
    json_encode($dosrodkowanie['canon'] ?? 'brak klucza'));
check('sekcja map z formularza PRZEŻYŁA zapis',
    in_array('mapy', (array) ($dosrodkowanie['sections'] ?? []), true),
    json_encode($dosrodkowanie['sections'] ?? null));
check('config ze zmienną bez pojęcia w mapach przechodzi walidację',
    Configurator::bledyConfigu($bezPojecia) === [],
    implode(', ', Configurator::bledyConfigu($bezPojecia)));

// ---------------------------------------------------------------- brak dopisań
echo "\n== brak dopisań = brak nowej wersji ==\n";

$samePominiecia = [
    $klucz('tag', 'PRESSING WYSOKI') => TemplateDiff::POMIN,
    $klucz('tag', 'STAŁY FRAGMENT')  => TemplateDiff::NA_STALE,
];
check('same pominięcia nie dopisują niczego',
    TemplateDiff::czyDopisuje($samePominiecia) === false,
    'pusta wersja różniłaby się od poprzedniej tylko numerem, a numer każe '
    . 'Sesji 7 przeliczyć wszystkie raporty klubu');

// ---------------------------------------------------------------- zapis
echo "\n== zapis przez ReportTemplates (append-only) ==\n";

$v1 = ReportTemplates::saveNewVersion(1, $config, 1);
$v2 = ReportTemplates::saveNewVersion(1, $nowyConfig, 1);

check('import z nowymi tagami daje DOKŁADNIE jedną nową wersję', $v2 === $v1 + 1,
    "v1={$v1} v2={$v2}");
check('poprzednia wersja nietknięta',
    count(ReportTemplates::decodeConfig(ReportTemplates::version(1, $v1)['config'])['variables']) === 6);

// ---------------------------------------------------------------- ignorowane w bazie
echo "\n== zignoruj na stałe trafia do club_ignored_tags ==\n";

check('wpis zapisany', IgnoredTags::add(1, IgnoredTags::TAG, 'STAŁY FRAGMENT', 1) === true);
check('powtórzenie nie jest błędem',
    IgnoredTags::add(1, IgnoredTags::TAG, 'STAŁY FRAGMENT', 1) === false);
check('lookup widzi wpis', !empty(IgnoredTags::lookup(1)['tag']['STAŁY FRAGMENT']));

$poIgnorze = TemplateDiff::policz($meta, $nowyConfig, IgnoredTags::lookup(1));
check('po zapisie tag nie wraca jako nowy',
    !in_array('STAŁY FRAGMENT', array_column($poIgnorze['nowe'], 'name'), true));

// ---------------------------------------------------------------- mapa kluczy
echo "\n== klucze formularza nie przyjmują pozycji spoza importu ==\n";

$mapa = TemplateDiff::mapaKluczy($diff['nowe']);
check('mapa pokrywa wszystkie nowe pozycje', count($mapa) === count($diff['nowe']));
check('skrót obcej pozycji nie ma odwzorowania',
    !isset($mapa[TemplateDiff::kluczHtml('tag', 'TAG SPOZA IMPORTU')]),
    'decyzja może dotyczyć wyłącznie pozycji faktycznie obecnej w eksporcie');
check('tag i etykieta o tej samej nazwie mają różne klucze',
    TemplateDiff::kluczHtml('tag', 'CELNY') !== TemplateDiff::kluczHtml('label', 'CELNY'));

// ---------------------------------------------------------------- kontynuacja zmiennej
echo "\n== kontynuacja zmiennej dopisuje alias, nie nową zmienną ==\n";

/*
 * SEDNO: klub zmienia nazwę taga między sezonami (`SBZ PODAJĄCY` -> `ZDOBYCIE SBZ`).
 * Dodanie tego jako NOWEJ zmiennej dałoby raport z dwiema seriami zamiast jednej,
 * a obie liczby wyglądałyby sensownie — więc nikt by tego nie zauważył.
 */
$bazowy = Configurator::config(
    [[
        'id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'ZDOBYCIE SBZ'],
        'canon' => null, 'display_label' => 'Wejście w SBZ', 'color' => '#E8722C',
        'sections' => ['bilans', 'tl_sbz'], 'visible' => true,
    ]],
    ['bilans', 'tl_sbz']
);
$pozycjeAlias = [['type' => 'tag', 'name' => 'SBZ PODAJĄCY', 'count' => 12, 'samples' => []]];
$kluczAlias = TemplateDiff::klucz('tag', 'SBZ PODAJĄCY');

check('kontynuacja liczy się jako zmiana templatu',
    TemplateDiff::czyDopisuje([$kluczAlias => TemplateDiff::KONTYNUACJA]),
    'zmienia definicję raportu, więc musi dostać własną wersję');

$zAliasem = TemplateDiff::nowyConfig(
    $bazowy, $pozycjeAlias,
    [$kluczAlias => TemplateDiff::KONTYNUACJA],
    [$kluczAlias => ['alias_of' => 'ZDOBYCIE SBZ']]
);
check('nie powstała nowa zmienna', count($zAliasem['variables']) === 1,
    'to ta sama zmienna pod inną nazwą, a nie druga zmienna');
check('alias dopisany do istniejącej zmiennej',
    ($zAliasem['variables'][0]['aliases'] ?? []) === ['SBZ PODAJĄCY']);
check('nazwa główna zmiennej nietknięta',
    $zAliasem['variables'][0]['source']['raw'] === 'ZDOBYCIE SBZ');

$wskazanieDonikad = TemplateDiff::nowyConfig(
    $bazowy, $pozycjeAlias,
    [$kluczAlias => TemplateDiff::KONTYNUACJA],
    [$kluczAlias => ['alias_of' => 'ZMIENNA KTÓREJ NIE MA']]
);
check('wskazanie na nieistniejącą zmienną jest pomijane',
    ($wskazanieDonikad['variables'][0]['aliases'] ?? []) === [],
    'alias prowadzący donikąd byłby gorszy niż jego brak');

$dwaRazy = TemplateDiff::nowyConfig(
    $zAliasem, $pozycjeAlias,
    [$kluczAlias => TemplateDiff::KONTYNUACJA],
    [$kluczAlias => ['alias_of' => 'ZDOBYCIE SBZ']]
);
check('ten sam alias nie dubluje się przy powtórnej decyzji',
    ($dwaRazy['variables'][0]['aliases'] ?? []) === ['SBZ PODAJĄCY']);

$cele = TemplateDiff::celeKontynuacji($bazowy);
check('lista celów niesie etykietę operatora, nie surową nazwę',
    $cele === [['raw' => 'ZDOBYCIE SBZ', 'label' => 'Wejście w SBZ']]);
check('bez templatu nie ma czego kontynuować', TemplateDiff::celeKontynuacji(null) === []);

// ---------------------------------------------------------------- układ przeżywa rewizję
echo "\n== układ raportu przeżywa dopisanie zmiennej ==\n";

$zUkladem = $bazowy;
$zUkladem['sections'] = [
    ['id' => 's1', 'size' => '1/2', 'widgets' => ['donuty'], 'title' => 'Moje udziały'],
    ['id' => 's2', 'size' => '1', 'widgets' => ['bilans'], 'title' => ''],
];
$zUkladem['thresholds'] = ['pressing' => 55];

$poRewizji = TemplateDiff::nowyConfig(
    $zUkladem,
    [['type' => 'tag', 'name' => 'NOWY TAG', 'count' => 3, 'samples' => []]],
    [TemplateDiff::klucz('tag', 'NOWY TAG') => TemplateDiff::DODAJ],
    [TemplateDiff::klucz('tag', 'NOWY TAG') => ['sections' => ['bilans']]]
);
check('kolejność i szerokość kafli zostają',
    ($poRewizji['sections'][0]['widgets'][0] ?? '') === 'donuty'
    && ($poRewizji['sections'][0]['size'] ?? '') === '1/2'
    && ($poRewizji['sections'][0]['title'] ?? '') === 'Moje udziały',
    'rewizja mapowań dopisuje ZMIENNE, a nie przestawia raport');
check('progi klubu zostają', ($poRewizji['thresholds']['pressing'] ?? null) === 55);
check('nowa zmienna faktycznie doszła', count($poRewizji['variables']) === 2);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
if ($pominiete !== []) {
    echo "POMINIĘTE (nie są zielone — po prostu się nie wykonały):\n";
    foreach ($pominiete as $p) {
        echo "  · {$p}\n";
    }
}
exit($fail === 0 ? 0 : 1);
