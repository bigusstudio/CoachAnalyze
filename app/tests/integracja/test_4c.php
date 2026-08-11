<?php
declare(strict_types=1);
/** Weryfikacja Etapu 4c: sezony, biblioteka meczów, stronicowanie. */

use CoachAnalyze\Db;
use CoachAnalyze\Matches;
use CoachAnalyze\Seasons;
use CoachAnalyze\Stats;
use CoachAnalyze\View;

$root = dirname(__DIR__, 3);
ob_start();
require $root . '/app/src/bootstrap.php';
require __DIR__ . '/seed.php';

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $fail++; echo "  BŁĄD $name" . ($detail ? " — $detail" : '') . "\n"; }
}

$db = '/tmp/ca_4c_' . getmypid() . '.sqlite';
@unlink($db);
ca_test_db($db);

// ---------------------------------------------------- granice sezonu
echo "== sezon polski: lipiec–czerwiec ==\n";
$przypadki = [
    ['2026-08-09', '2026/2027'],   // sierpień — początek sezonu
    ['2026-07-01', '2026/2027'],   // pierwszy dzień
    ['2027-06-30', '2026/2027'],   // ostatni dzień
    ['2027-05-15', '2026/2027'],   // runda wiosenna, INNY rok kalendarzowy
    ['2027-07-01', '2027/2028'],   // pierwszy dzień następnego
    ['2026-06-30', '2025/2026'],   // czerwiec należy do poprzedniego
    ['2026-01-10', '2025/2026'],   // styczeń należy do poprzedniego
];
foreach ($przypadki as [$data, $oczekiwana]) {
    $b = Seasons::boundsFor($data);
    check("$data → $oczekiwana", $b['label'] === $oczekiwana, $b['label']);
}
$b = Seasons::boundsFor('2026-08-09');
check('zakres dat sezonu', $b['date_from'] === '2026-07-01' && $b['date_to'] === '2027-06-30',
    $b['date_from'] . '..' . $b['date_to']);

// ---------------------------------------------------- wykrywanie
echo "\n== wykrywanie sezonu z daty meczu ==\n";
Db::run('DELETE FROM seasons');
$id1 = Seasons::detect('2026-08-09', 1);
check('sezon utworzony przy pierwszym meczu', $id1 !== null);
check('etykieta z reguły lipiec–czerwiec', Seasons::find((int) $id1)['label'] === '2026/2027');

$id2 = Seasons::detect('2027-05-15', 1);
check('mecz z rundy wiosennej trafia do TEGO SAMEGO sezonu', $id2 === $id1,
    "pierwszy=$id1 drugi=$id2");

$id3 = Seasons::detect('2027-09-01', 1);
check('mecz z nowego sezonu tworzy nowy wpis', $id3 !== $id1);
check('są dokładnie dwa sezony', (int) Db::one('SELECT COUNT(*) c FROM seasons')['c'] === 2);
check('brak daty nie tworzy sezonu', Seasons::detect(null, 1) === null);

// ---------------------------------------------------- bieżący sezon
echo "\n== bieżący sezon ==\n";
Seasons::markCurrent((int) $id1, 1);
Seasons::markCurrent((int) $id3, 1);
check('bieżący sezon jest dokładnie jeden',
    (int) Db::one('SELECT COUNT(*) c FROM seasons WHERE is_current = 1')['c'] === 1);
check('bieżący to ten ustawiony ostatnio', (int) Seasons::current()['id'] === (int) $id3);

// ---------------------------------------------------- usuwanie
echo "\n== usuwanie sezonu ==\n";
Db::run('UPDATE matches SET season_id = :s WHERE id = 1', ['s' => $id1]);
$r = Seasons::delete((int) $id1, 1);
check('sezon z meczami nie da się usunąć', $r['ok'] === false && $r['error'] === 'season.err.in_use');
$r2 = Seasons::delete((int) $id3, 1);
check('pusty sezon usunięty', $r2['ok'] === true);

// ---------------------------------------------------- LIMIT jako parametr
echo "\n== LIMIT jako parametr zapytania ==\n";
$src = (string) file_get_contents($root . '/app/src/Stats.php');
check('brak sklejania LIMIT z liczbą', !preg_match("/LIMIT\s*'\s*\.\s*\\\$/", $src) && !str_contains($src, "LIMIT ' . \$limit"));
check('LIMIT przez parametr :limit', str_contains($src, 'LIMIT :limit'));
check('recentMatches nadal działa', count(Stats::recentMatches(3)) === 3);
check('limit przycinany do zakresu', count(Stats::recentMatches(999)) <= 50);
check('limit 0 nie wywraca zapytania', count(Stats::recentMatches(0)) === 1);

// ---------------------------------------------------- biblioteka
echo "\n== biblioteka meczów ==\n";
Db::run('DELETE FROM matches');
$sezonA = Seasons::detect('2026-08-01', 1);
$sezonB = Seasons::detect('2025-08-01', 1);
for ($i = 1; $i <= 25; $i++) {
    Db::run('INSERT INTO matches (owner_id, season_id, club_home_id, club_away_id, played_at, status)
             VALUES (1, ?, ?, ?, ?, ?)',
        [$i <= 15 ? $sezonA : $sezonB, $i % 2 === 0 ? 1 : 2, $i % 2 === 0 ? 2 : 1,
         sprintf('2026-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1),
         ['done', 'failed', 'queued', 'draft', 'running'][$i % 5]]);
}

$w = Matches::search([]);
check('domyślnie 20 na stronę', count($w['rows']) === 20, (string) count($w['rows']));
check('łącznie 25 meczów', $w['total'] === 25, (string) $w['total']);
check('dwie strony', $w['pages'] === 2, (string) $w['pages']);

$w2 = Matches::search(['page' => 2]);
check('druga strona ma resztę', count($w2['rows']) === 5, (string) count($w2['rows']));
check('strona poza zakresem przycięta do ostatniej', Matches::search(['page' => 99])['page'] === 2);
check('strona ujemna przycięta do pierwszej', Matches::search(['page' => -3])['page'] === 1);

$wSezon = Matches::search(['season' => $sezonA]);
check('filtr po sezonie', $wSezon['total'] === 15, (string) $wSezon['total']);

$wKlub = Matches::search(['club' => 1]);
check('filtr po klubie łapie obie strony', $wKlub['total'] === 25, (string) $wKlub['total']);

$wOba = Matches::search(['club' => 2, 'season' => $sezonB]);
check('filtry łączą się (AND)', $wOba['total'] === 10, (string) $wOba['total']);

// ---------------------------------------------------- sortowanie
echo "\n== sortowanie ==\n";
$daty = array_column(Matches::search(['sort' => 'data_desc'])['rows'], 'played_at');
$posortowane = $daty; rsort($posortowane);
check('data malejąco', $daty === $posortowane);

$datyAsc = array_column(Matches::search(['sort' => 'data_asc'])['rows'], 'played_at');
$posortowaneAsc = $datyAsc; sort($posortowaneAsc);
check('data rosnąco', $datyAsc === $posortowaneAsc);

check('status: najpierw wymagające uwagi',
    Matches::search(['sort' => 'status_asc'])['rows'][0]['status'] === 'failed');
check('status: najpierw gotowe',
    Matches::search(['sort' => 'status_desc'])['rows'][0]['status'] === 'done');

// KLUCZOWE: wartość sortowania z żądania nie może trafić do zapytania.
check('nieznane sortowanie wraca do domyślnego', Matches::normalizeSort('data_desc; DROP TABLE matches') === 'data_desc');
check('puste sortowanie wraca do domyślnego', Matches::normalizeSort(null) === 'data_desc');
$wZlySort = Matches::search(['sort' => "'; DELETE FROM matches; --"]);
check('próba wstrzyknięcia w ORDER BY nic nie psuje', $wZlySort['total'] === 25,
    'meczów po próbie: ' . (int) Db::one('SELECT COUNT(*) c FROM matches')['c']);

// ---------------------------------------------------- widok
echo "\n== widok ==\n";
$html = View::render('matches_list', [
    'wynik' => $w, 'clubs' => \CoachAnalyze\Clubs::all(), 'seasons' => Seasons::all(),
    'filtr' => ['klub' => null, 'sezon' => null, 'sort' => 'data_desc', 'strona' => 1], 'notice' => null,
]);
check('kolumny Nasza drużyna / Rywal', str_contains($html, 'Nasza drużyna') && str_contains($html, 'Rywal'));
check('brak Gospodarz/Gość', !str_contains($html, 'Gospodarz') && !str_contains($html, 'Gość'));
check('stronicowanie widoczne', str_contains($html, 'Następna'));

$pusty = View::render('matches_list', [
    'wynik' => ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 20],
    'clubs' => [], 'seasons' => [],
    'filtr' => ['klub' => null, 'sezon' => null, 'sort' => 'data_desc', 'strona' => 1], 'notice' => null,
]);
check('pusty stan opisowy', str_contains($pusty, 'wgraj pierwszy eksport'));

$pustyFiltr = View::render('matches_list', [
    'wynik' => ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 20],
    'clubs' => [], 'seasons' => [],
    'filtr' => ['klub' => 7, 'sezon' => null, 'sort' => 'data_desc', 'strona' => 1], 'notice' => null,
]);
check('pusty wynik filtra ma inny komunikat', str_contains($pustyFiltr, 'nie pasuje do wybranego filtra'));

$sezony = View::render('seasons_list', [
    'seasons' => Seasons::all(), 'propozycja' => Seasons::boundsFor('2026-08-11'),
    'notice' => null, 'error' => null,
]);
check('formularz podpowiada sezon z dzisiejszej daty', str_contains($sezony, '2026/2027'));
check('akcje sezonu idą POST-em z tokenem', substr_count($sezony, 'name="csrf"') >= 1
    && !str_contains($sezony, '<a href="/sezony/1/usun"'));

@unlink($db);
$out = ob_get_clean(); echo $out;
echo "\n=== OK: $ok, BŁĘDÓW: $fail ===\n";
exit($fail === 0 ? 0 : 1);
