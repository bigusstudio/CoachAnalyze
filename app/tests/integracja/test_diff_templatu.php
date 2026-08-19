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
 * TOR SYNTETYCZNY — `app/tests/integracja/wiazownica.csv`, dziesięć wierszy
 * napisanych na potrzeby testów. Pokrywa przypadki brzegowe (`1x1 DEF` jako
 * wariant `1x1 DEF.`, etykiety obok tagów) i chodzi zawsze.
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
echo "\n== TOR SYNTETYCZNY: wiazownica.csv (przypadki brzegowe) ==\n";

$synt = $here . '/wiazownica.csv';
check('plik syntetyczny istnieje', is_file($synt), $synt);

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

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
if ($pominiete !== []) {
    echo "POMINIĘTE (nie są zielone — po prostu się nie wykonały):\n";
    foreach ($pominiete as $p) {
        echo "  · {$p}\n";
    }
}
exit($fail === 0 ? 0 : 1);
