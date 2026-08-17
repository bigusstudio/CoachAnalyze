<?php
declare(strict_types=1);

/**
 * Kalkulator xG (M3), warstwa PHP: odczyt siatki silnika, lista strzałów.
 *
 * PHP niczego nie liczy — test sprawdza właśnie to: wartości pochodzą
 * z artefaktu `app/src/data/xg_grid.json` i zgadzają się z nim co do liczby.
 *
 * Uruchomienie:  php test_xg.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Stats;
use CoachAnalyze\View;
use CoachAnalyze\XgCalc;

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

$baza    = $here . '/xg.sqlite';
$envFile = $here . '/.env.xg';
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

// ---------------------------------------------------------------- artefakt siatki
echo "== artefakt siatki: obecny, kompletny, zgodny wymiarami ==\n";

check('artefakt siatki istnieje w repo', is_file(XgCalc::gridPath()), XgCalc::gridPath());
$siatka = XgCalc::grid();
check('siatka daje się wczytać', $siatka !== null);
check('siatka ma trzy modele i stałą karnego',
    $siatka !== null && isset($siatka['models']['open_foot'], $siatka['models']['open_head'],
        $siatka['models']['free_kick']) && (float) $siatka['penalty'] > 0.5);
check('wymiary boiska 105 × 68',
    $siatka !== null && count($siatka['models']['open_foot']) === 68
    && count($siatka['models']['open_foot'][0]) === 105);

// ---------------------------------------------------------------- odczyt
echo "\n== odczyt: PHP nie liczy, tylko czyta komórki ==\n";

$blisko = XgCalc::lookup(99.0, 34.0, 'foot', 'open');
$daleko = XgCalc::lookup(75.0, 34.0, 'foot', 'open');
check('wartość blisko bramki wyższa niż z dystansu',
    $blisko !== null && $daleko !== null && $blisko['xg'] > $daleko['xg']);
check('odczyt równy komórce siatki (bez własnej arytmetyki)',
    $blisko !== null && $blisko['xg'] === (float) $siatka['models']['open_foot'][34][99]);
check('główka niżej niż noga z tej samej pozycji',
    XgCalc::lookup(97.0, 34.0, 'head', 'open')['xg'] < XgCalc::lookup(97.0, 34.0, 'foot', 'open')['xg']);
check('karny to stała, pozycja bez znaczenia',
    XgCalc::lookup(94.0, 34.0, 'foot', 'penalty')['xg']
    === XgCalc::lookup(30.0, 10.0, 'foot', 'penalty')['xg']);

[$mx, $my] = XgCalc::pxToMeters(495, 170);
check('piksele obrazka przeliczone na metry (5 px = 1 m)', $mx === 99.0 && $my === 34.0,
    "{$mx} × {$my}");

check('ocena jakości opisuje wartość',
    View::t(XgCalc::quality(0.45)) !== XgCalc::quality(0.45)
    && XgCalc::quality(0.45) === 'xg.quality.top'
    && XgCalc::quality(0.01) === 'xg.quality.low');

// ---------------------------------------------------------------- lista strzałów
echo "\n== lista: dodawanie, edycja, suma, własność ==\n";

$id1 = XgCalc::add(1, 99.0, 34.0, 'foot', 'open');
$id2 = XgCalc::add(1, 94.0, 34.0, 'foot', 'penalty');
check('strzały dodane', $id1 !== null && $id2 !== null && $id2 > $id1);
check('poza boiskiem odrzucone', XgCalc::add(1, 300.0, 34.0, 'foot', 'open') === null);

$strzaly = XgCalc::shots(1);
check('lista ma dwa strzały', count($strzaly) === 2);
check('karny na liście ze stałą wartością',
    (float) $strzaly[0]['xg'] === (float) $siatka['penalty']);

$suma = XgCalc::sum(1);
check('suma listy zgadza się z pozycjami',
    abs($suma - ((float) $strzaly[0]['xg'] + (float) $strzaly[1]['xg'])) < 0.001,
    (string) $suma);

check('edycja przelicza wartość z siatki',
    XgCalc::update($id1, 1, 75.0, 34.0, 'foot', 'open') === true
    && (float) XgCalc::find($id1, 1)['xg'] === $daleko['xg']);

check('cudzy strzał niewidoczny i nieedytowalny',
    XgCalc::find($id1, 2) === null && XgCalc::update($id1, 2, 50.0, 30.0, 'foot', 'open') === false
    && XgCalc::delete($id1, 2) === false);

check('usunięcie własnego działa', XgCalc::delete($id1, 1) === true
    && count(XgCalc::shots(1)) === 1);

// ---------------------------------------------------------------- teksty
echo "\n== teksty interfejsu ==\n";
$brak = [];
foreach ([
    'nav.xg', 'xg.title', 'xg.intro', 'xg.pitch.title', 'xg.pitch.hint',
    'xg.body.foot', 'xg.body.head', 'xg.sit.open', 'xg.sit.free_kick', 'xg.sit.penalty',
    'xg.result', 'xg.quality.top', 'xg.quality.good', 'xg.quality.avg', 'xg.quality.low',
    'xg.list.title', 'xg.list.empty', 'xg.sum', 'xg.edit', 'xg.edit.submit',
    'xg.updated', 'xg.delete', 'xg.deleted', 'xg.err.no_point', 'xg.err.grid', 'xg.err.no_grid',
] as $klucz) {
    if (View::t($klucz) === $klucz) {
        $brak[] = $klucz;
    }
}
check('wszystkie klucze mają polskie teksty', $brak === [], implode(', ', $brak));

@unlink($baza);
@unlink($envFile);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
