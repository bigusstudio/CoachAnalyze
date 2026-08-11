<?php
declare(strict_types=1);

/**
 * Kreator mapowań tagów: porównanie z profilem, podpowiedzi, wersjonowanie.
 *
 * Uruchomienie:  php test_mapowania.php
 */

use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\Mappings;
use CoachAnalyze\Stats;
use CoachAnalyze\View;

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

$baza    = $here . '/mapowania.sqlite';
$envFile = $here . '/.env.mapowania';
@unlink($baza);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $here, 'APP_URL=https://app.example.test',
    'SESSION_NAME=ca_test', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza, false);

$teraz = Stats::now();
Db::run("INSERT INTO clubs (id, owner_id, club_key, name, color_primary, is_own_team, created_at)
         VALUES (1, 1, 'HUT7K2QX', 'Klub A', '#E8722C', 1, :t)", ['t' => $teraz]);
Db::run("INSERT INTO matches (id, owner_id, club_home_id, played_at, status, created_at)
         VALUES (1, 1, 1, '2026-08-11', 'draft', :t)", ['t' => $teraz]);

// ================================================================ ZAMKNIĘTA LISTA
echo "== lista pojec jest zamknieta i zgodna z dokumentem ==\n";

/*
 * Czytamy WYLACZNIE akapit z lista pojec, a nie cala sekcje: dalej w dokumencie
 * sa backticki wokol nazw kolumn i pol JSON, ktore pojeciami nie sa.
 * Zbyt szeroki odczyt dawal falszywy alarm o „pojeciach spoza kodu".
 */
$doc = (string) file_get_contents($root . '/docs/MODEL_KANONICZNY.md');
$sekcja = (string) strstr($doc, '## Pojęcia bazowe');
$akapit = '';
foreach (explode("\n", $sekcja) as $linia) {
    if (str_starts_with($linia, '## Pojęcia')) {
        continue;
    }
    if (trim($linia) === '' && $akapit !== '') {
        break;                      // koniec akapitu z lista
    }
    if (str_starts_with(trim($linia), '`')) {
        $akapit .= $linia . "\n";
    }
}
preg_match_all('/`([a-z_]+)`/', $akapit, $m);
$zDokumentu = array_values(array_unique($m[1] ?? []));

check('kod ma 12 pojec', count(Mappings::POJECIA) === 12, (string) count(Mappings::POJECIA));
check('kazde pojecie z kodu jest w dokumencie',
    array_diff(Mappings::POJECIA, $zDokumentu) === [],
    implode(', ', array_diff(Mappings::POJECIA, $zDokumentu)));
check('dokument nie ma pojec spoza kodu',
    array_diff($zDokumentu, Mappings::POJECIA) === [],
    implode(', ', array_diff($zDokumentu, Mappings::POJECIA)));

// ================================================================ PODPOWIEDZI
echo "\n== podpowiedzi: sugestia, nie automat ==\n";

$znane = Mappings::domyslneTagi();

$s1 = Mappings::suggest('1x1 DEF', $znane);
check('„1x1 DEF" podpowiada duel po podobienstwie do „1x1 DEF."',
    $s1['concept'] === 'duel', (string) $s1['concept']);
check('podpowiedz podaje POWOD', $s1['reason'] !== null && str_contains((string) $s1['reason'], '1x1 DEF.'));

$s2 = Mappings::suggest('SBZ PODAJĄCY', $znane);
check('„SBZ PODAJĄCY" podpowiada entry_sbz po czlonie nazwy',
    $s2['concept'] === 'entry_sbz', (string) $s2['concept']);

$s3 = Mappings::suggest('AKCJA DEFENSYWNA', $znane);
check('„AKCJA DEFENSYWNA" NIE dostaje podpowiedzi',
    $s3['concept'] === null,
    'zgadywanie pojecia zmienia liczby w raporcie');

$s4 = Mappings::suggest('ZUPEŁNIE OBCY TAG', $znane);
check('obcy tag bez podpowiedzi', $s4['concept'] === null, (string) $s4['concept']);

check('podobienstwo identycznych to 1.0', Mappings::similarity('STRZAŁ', 'STRZAŁ') === 1.0);
check('polskie znaki licza sie jak jeden znak',
    Mappings::similarity('STRZAL', 'STRZAŁ') > 0.8,
    (string) Mappings::similarity('STRZAL', 'STRZAŁ'));
check('rozne nazwy maja niskie podobienstwo',
    Mappings::similarity('STRZAŁ', 'ODBIÓR') < 0.5);

// ================================================================ PORÓWNANIE
echo "\n== porownanie eksportu z profilem klubu ==\n";

$meta = [
    'unmapped_tags'   => ['AKCJA DEFENSYWNA', '1x1 DEF', 'SBZ PODAJĄCY'],
    'unmapped_labels' => ['PRESSING WYSOKI'],
];

$nieznane = Mappings::unknown($meta, 1);
check('bez profilu wszystkie tagi sa nowe', count($nieznane['tags']) === 3, (string) count($nieznane['tags']));
check('etykiety tez', count($nieznane['labels']) === 1);
check('brak liczby wystapien to null, NIE zero',
    $nieznane['tags'][0]['count'] === null,
    'zero znaczyloby „nie wystapil ani razu"');

// Ksztalt wzbogacony — gdy silnik zacznie podawac liczby.
$metaBogate = [
    'unmapped_tags' => [
        ['tag' => 'AKCJA DEFENSYWNA', 'count' => 7, 'sample_labels' => ['UDANA', 'NIEUDANA']],
    ],
    'unmapped_labels' => [['label' => 'PRESSING WYSOKI', 'count' => 3]],
];
$bogate = Mappings::unknown($metaBogate, 1);
check('ksztalt wzbogacony: liczba wystapien czytana', $bogate['tags'][0]['count'] === 7);
check('ksztalt wzbogacony: etykiety towarzyszace czytane',
    $bogate['tags'][0]['labels'] === ['UDANA', 'NIEUDANA']);
check('ksztalt wzbogacony: liczba dla etykiet', $bogate['labels'][0]['count'] === 3);

// ================================================================ ZAPIS WERSJI
echo "\n== zapis tworzy NOWA wersje, poprzednie zostaja ==\n";

$v1 = Mappings::saveVersion(1, [
    'AKCJA DEFENSYWNA' => Mappings::NIE_ANALIZUJ,
    '1x1 DEF'          => 'duel',
    'SBZ PODAJĄCY'     => 'entry_sbz',
], ['PRESSING WYSOKI' => 'after_press'], 1, 'pierwsze mapowanie klubu');

check('profil zapisany', $v1 > 0);
check('wersja to 1', Mappings::rules(1)['version'] === 1);

$v2 = Mappings::saveVersion(1, ['NOWY TAG' => 'shot'], [], 1, 'klub dolozyl tag');
check('kolejny zapis daje wersje 2', Mappings::rules(1)['version'] === 2);
check('POPRZEDNIA wersja nadal istnieje',
    Db::one('SELECT COUNT(*) AS ile FROM mapping_profiles WHERE club_id = 1')['ile'] == 2,
    'nadpisanie skasowaloby odpowiedz na pytanie o starsze liczby');

check('nowa wersja zachowuje wczesniejsze reguly',
    isset(Mappings::decidedTags(1)['1x1 DEF']),
    'kreator dokłada decyzje, nie zastepuje profilu');
check('i dokłada nowa', Mappings::decidedTags(1)['NOWY TAG'] === 'shot');

echo "\n== tagi oznaczone jako nieanalizowane nie wracaja ==\n";
$poZapisie = Mappings::unknown($meta, 1);
check('zdecydowane tagi znikaja z listy nowych', $poZapisie['tags'] === [],
    'kreator pytalby w kolko o to samo');
check('etykiety tez', $poZapisie['labels'] === []);

check('ale sa widoczne w ustawieniach klubu',
    Mappings::ignoredTags(1) === ['AKCJA DEFENSYWNA'],
    implode(', ', Mappings::ignoredTags(1)));
check('przypisane tagi tez widoczne',
    array_key_exists('1x1 DEF', Mappings::assignedTags(1)));

echo "\n== decyzje da sie cofnac ==\n";
Mappings::saveVersion(1, ['AKCJA DEFENSYWNA' => 'recovery'], [], 1, 'zmiana decyzji');
check('tag przestal byc pominiety', Mappings::ignoredTags(1) === []);
check('i ma przypisane pojecie', Mappings::assignedTags(1)['AKCJA DEFENSYWNA'] === 'recovery');

echo "\n== ksztalt reguly zgodny z kontraktem silnika ==\n";
$dlaSilnika = Mappings::forEngine(1);
check('profil ma wersje i reguly',
    isset($dlaSilnika['version'], $dlaSilnika['rules']));

$regulaTagu = null;
$regulaEtykiety = null;
foreach ($dlaSilnika['rules'] as $r) {
    if (($r['match']['tag'] ?? '') === '1x1 DEF')            { $regulaTagu = $r; }
    if (($r['match']['label'] ?? '') === 'PRESSING WYSOKI')  { $regulaEtykiety = $r; }
}
check('regula tagu ma ksztalt {match:{tag},concept}',
    $regulaTagu !== null && $regulaTagu['concept'] === 'duel');
check('regula etykiety ma ksztalt {match:{label},qualifier}',
    $regulaEtykiety !== null && $regulaEtykiety['qualifier'] === 'after_press');

// „nie analizuj" = concept null. Silnik zna wtedy tag i przestaje go zglaszac.
Mappings::saveVersion(1, ['CO INNEGO' => Mappings::NIE_ANALIZUJ], [], 1);
$regulaPominieta = null;
foreach (Mappings::forEngine(1)['rules'] as $r) {
    if (($r['match']['tag'] ?? '') === 'CO INNEGO') { $regulaPominieta = $r; }
}
check('nieanalizowany tag zapisany jako concept null',
    $regulaPominieta !== null && $regulaPominieta['concept'] === null,
    'inaczej silnik dalej zglaszalby tag jako nierozpoznany');

// ================================================================ ZAMKNIĘTA LISTA — EGZEKWOWANIE
echo "\n== pojecia spoza listy sa odrzucane ==\n";
$blad = null;
try {
    Mappings::saveVersion(1, ['X' => 'wymyslone_pojecie'], [], 1);
} catch (\Throwable $e) {
    $blad = $e->getMessage();
}
check('nieznane pojecie przerywa zapis', $blad !== null);
check('komunikat nazywa problem', $blad !== null && str_contains($blad, 'Nieznane pojęcie'));

$blad2 = null;
try {
    Mappings::saveVersion(1, [], ['Y' => 'wymyslony_kwalifikator'], 1);
} catch (\Throwable $e) {
    $blad2 = $e->getMessage();
}
check('nieznany kwalifikator tez odrzucony', $blad2 !== null);

// ================================================================ HISTORIA
echo "\n== historia: kto i kiedy zmienil mapowanie ==\n";
$historia = Mappings::history(1);
check('historia niepusta', count($historia) >= 3);
check('najnowsza wersja pierwsza', (int) $historia[0]['version'] > (int) $historia[1]['version']);
check('widac autora', $historia[0]['autor'] !== null, 'bez autora nie wiadomo, kto zmienil liczby');
check('widac date', !empty($historia[0]['created_at']));
check('widac powod zmiany',
    in_array('pierwsze mapowanie klubu', array_column($historia, 'note'), true));
check('widac liczbe regul', (int) $historia[0]['liczba_regul'] > 0);

// ================================================================ ZGŁOSZENIA
echo "\n== zgloszenie brakujacego pojecia ==\n";
Mappings::requestConcept(1, null, 'DZIWNY TAG', 'Nic z listy nie pasuje.', 1);
$zgloszenia = Mappings::requestsFor(1);
check('zgloszenie zapisane', count($zgloszenia) === 1);
check('niesie nazwe tagu', $zgloszenia[0]['tag'] === 'DZIWNY TAG');
check('niesie uzasadnienie', str_contains((string) $zgloszenia[0]['rationale'], 'Nic z listy'));
check('ma stan „do przejrzenia"', $zgloszenia[0]['status'] === 'new');
check('widac w liscie otwartych', count(Mappings::openRequests()) === 1);

// ================================================================ ŚCIEŻKA IMPORTU
echo "\n== zatrzymanie na kreatorze tylko gdy jest po co ==\n";

Db::run("INSERT INTO imports (id, match_id, csv_path, checksum_csv, coverage_json, created_at)
         VALUES (10, 1, '/x/a.csv', 'abc', :cov, :t)", [
    'cov' => json_encode(['coverage' => ['teams' => ['Klub A']],
                          'unmapped_tags' => ['CALKIEM NOWY'], 'unmapped_labels' => []]),
    't'   => $teraz,
]);
$import = Imports::find(10);
check('nowy tag zatrzymuje na kreatorze', Imports::needsMapping($import) === true);

Mappings::saveVersion(1, ['CALKIEM NOWY' => 'shot'], [], 1);
check('po decyzji NIE zatrzymuje', Imports::needsMapping(Imports::find(10)) === false,
    'ekran pytajacy o nic uczy klikac „dalej" bez czytania');

Db::run("INSERT INTO imports (id, match_id, csv_path, checksum_csv, created_at)
         VALUES (11, 1, '/x/b.csv', 'def', :t)", ['t' => $teraz]);
check('bez pokrycia nie zatrzymuje', Imports::needsMapping(Imports::find(11)) === false);

// ================================================================ TEKSTY
echo "\n== teksty interfejsu ==\n";
$brak = [];
foreach (array_merge(
    ['mapping.title', 'mapping.why', 'mapping.skip', 'mapping.report_missing',
     'mapping.suggested', 'mapping.versioning', 'mapping.submit', 'mapping.saved',
     'mapping.err.no_club', 'mapping.err.unknown_concept',
     'mapping.club.title', 'mapping.club.ignored', 'mapping.club.history',
     'coverage.excluded', 'coverage.excluded.none', 'coverage.excluded.unrecognised',
     'coverage.excluded.ignored', 'coverage.excluded.count_unknown',
     'mail.match.on_date', 'mail.match.unknown'],
    array_map(static fn($p) => 'concept.' . $p, Mappings::POJECIA)
) as $klucz) {
    if (View::t($klucz) === $klucz) {
        $brak[] = $klucz;
    }
}
check('wszystkie klucze maja polskie teksty', $brak === [], implode(', ', $brak));

@unlink($baza);
@unlink($envFile);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
