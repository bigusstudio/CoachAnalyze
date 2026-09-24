<?php
declare(strict_types=1);

/**
 * Metryki na tabeli `events` (sesja 3 pivotu „viewer").
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CZTERY ROZSTRZYGNIĘCIA, KTÓRE ŁATWO NAPISAĆ PRAWIE DOBRZE:
 *
 * 1. WSKAŹNIK Z ZEROWYM MIANOWNIKIEM TO `null`, NIGDY ZERO. „0% wejść w SBZ
 *    zakończonych strzałem" i „nie było wejść w SBZ" to dwa różne zdania.
 * 2. ALIAS TAGU LICZY SIĘ RAZEM. `ZDOBYCIE SBZ` i `SBZ PODAJĄCY` to ta sama
 *    rzecz w dwóch wersjach tagowania klienta.
 * 3. SUMA SEZONU = SUMA KOLEJEK. Ta sama definicja bez filtra meczu, nie
 *    osobna metryka — inaczej zestawienie sezonowe mogłoby się nie zgadzać
 *    z sumą tego, co widać na kolejkach.
 * 4. ZDARZENIA BEZ DRUŻYNY (`none`) LICZĄ SIĘ DLA TENANTA. Pułapka 5: analityk
 *    klubu taguje własne straty i odbiory, nie cudze.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php test_metryki.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Metrics;
use CoachAnalyze\TagCatalog;

$root = dirname(__DIR__, 3);
$here = __DIR__;

$ok = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

$baza    = $here . '/metryki.sqlite';
$envFile = $here . '/.env.metryki';
$magazyn = $here . '/metryki_storage';

@unlink($baza);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn, 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $here . '/metryki.log',
    'APP_URL=http://localhost', 'SESSION_NAME=ca_test', 'REDIS_SOCKET=', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';

register_shutdown_function(static function () use ($baza, $envFile, $magazyn, $here): void {
    @unlink($baza); @unlink($envFile); @unlink($here . '/metryki.log');
    exec('rm -rf ' . escapeshellarg($magazyn));
});

ca_test_db($baza);

/**
 * Zdarzenie w tabeli. Kształt jak z artefaktu silnika — tutaj wprost, bo ten
 * zestaw sprawdza AGREGACJĘ, a nie drogę zdarzenia z eksportu do bazy.
 */
function zdarzenie(int $matchId, string $tag, string $side, array $labels = [],
                   ?float $xg = null, int $minute = 10): void
{
    static $t = 0;
    $t += 1000;
    Db::run(
        'INSERT INTO events (match_id,import_id,tag_name,labels_json,team,team_side,
                             t_ms,half,minute,xg,xg_source,is_goal)
         VALUES (:m,NULL,:tag,:lab,NULL,:side,:t,1,:min,:xg,:src,0)',
        ['m' => $matchId, 'tag' => $tag,
         'lab' => json_encode($labels, JSON_UNESCAPED_UNICODE),
         'side' => $side, 't' => $t, 'min' => $minute,
         'xg' => $xg, 'src' => $xg !== null ? 'analyst' : null]
    );
}

echo "== sterownik bazy ==\n";
check('testy chodzą na sqlite', Db::driver() === 'sqlite', Db::driver());
/*
 * WARUNEK NA ETYKIETACH MA DWIE ŚCIEŻKI — sqlite `json_each`, MariaDB
 * `JSON_CONTAINS`. Sprawdzamy, że ta uruchamiana w testach w ogóle działa,
 * zanim zaczniemy jej ufać w asercjach niżej.
 */
$probka = Db::one("SELECT EXISTS (SELECT 1 FROM json_each('[\"A\",\"B\"]') WHERE value = 'A') AS jest");
check('sqlite ma rozszerzenie JSON1', (int) ($probka['jest'] ?? 0) === 1,
    'bez niego filtr po etykietach nie zadziała w testach');

// ---------------------------------------------------------------- dane
echo "\n== dane syntetyczne ==\n";

// Mecz 1: 4 wejścia w SBZ (2 aliasem), 2 ze strzałem.
zdarzenie(1, 'ZDOBYCIE SBZ', 'us', ['POZYCYJNIE', 'STRZAŁ']);
zdarzenie(1, 'ZDOBYCIE SBZ', 'us', ['POZYCYJNIE']);
zdarzenie(1, 'SBZ PODAJĄCY', 'us', ['STRZAŁ']);
zdarzenie(1, 'SBZ PODAJĄCY', 'us', ['BRAK STRZAŁU']);
zdarzenie(1, 'STRZAŁ', 'us', ['CELNY'], 0.81);
zdarzenie(1, 'STRZAŁ', 'us', ['NIECELNY'], 0.09);
zdarzenie(1, 'STRZAŁ', 'them', ['CELNY'], 0.55);
// Straty bez drużyny — pułapka 5. Dwie, jedna z reakcją.
zdarzenie(1, 'STRATA', 'none', ['REAKCJA']);
zdarzenie(1, 'STRATA', 'none', []);

// Mecz 2: 2 wejścia w SBZ, żadnego strzału po nich.
zdarzenie(2, 'ZDOBYCIE SBZ', 'us', ['POZYCYJNIE']);
zdarzenie(2, 'ZDOBYCIE SBZ', 'us', ['POZYCYJNIE']);
zdarzenie(2, 'STRZAŁ', 'us', ['CELNY'], 0.30);

check('zdarzenia zapisane', (int) Db::one('SELECT COUNT(*) AS c FROM events')['c'] === 12);

// ---------------------------------------------------------------- liczniki
echo "\n== licznik i suma xG ==\n";

$strzaly = Metrics::compute(
    ['agg' => 'count', 'filter' => ['tags' => ['STRZAŁ'], 'team_side' => ['us']]],
    ['match_id' => 1]
);
check('licznik strzałów tenanta', $strzaly['value'] === 2, var_export($strzaly['value'], true));
check('strzał rywala NIE wchodzi do liczby tenanta', $strzaly['value'] !== 3);

$xg = Metrics::compute(['agg' => 'sum_xg', 'filter' => ['team_side' => ['us']]], ['match_id' => 1]);
check('suma xG tenanta', abs((float) $xg['value'] - 0.90) < 0.001, var_export($xg['value'], true));

// ---------------------------------------------------------------- alias
echo "\n== alias tagu liczy się RAZEM ==\n";

$sbz = Metrics::compute(
    ['agg' => 'count',
     'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us']]],
    ['match_id' => 1]
);
check('cztery wejścia w SBZ pod dwiema nazwami', $sbz['value'] === 4,
    var_export($sbz['value'], true) . ' — alias to ta sama rzecz w innej wersji tagowania');

$tylkoJedna = Metrics::compute(
    ['agg' => 'count', 'filter' => ['tags' => ['ZDOBYCIE SBZ'], 'team_side' => ['us']]],
    ['match_id' => 1]
);
check('bez aliasu liczba jest MNIEJSZA', $tylkoJedna['value'] === 2,
    'to pokazuje, że alias faktycznie dokłada wiersze, a nie liczy ich dwa razy');

// ---------------------------------------------------------------- wskaźniki
echo "\n== wskaźnik: zerowy mianownik daje null, nie zero ==\n";

$sbzStrzal = Metrics::compute([
    'agg' => 'ratio',
    'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'has_label' => ['STRZAŁ'],
                 'team_side' => ['us']],
    'of' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us']],
], ['match_id' => 1]);
check('2 z 4 wejść zakończone strzałem', abs((float) $sbzStrzal['value'] - 0.5) < 0.0001,
    var_export($sbzStrzal['value'], true));
check('wskaźnik niesie licznik i mianownik',
    $sbzStrzal['n'] === 2 && $sbzStrzal['d'] === 4,
    "n={$sbzStrzal['n']} d={$sbzStrzal['d']}");

$brakMianownika = Metrics::compute([
    'agg' => 'ratio',
    'filter' => ['tags' => ['NIE MA TAKIEGO TAGU'], 'has_label' => ['WYGRANY']],
    'of' => ['tags' => ['NIE MA TAKIEGO TAGU']],
], ['match_id' => 1]);
check('zerowy mianownik daje NULL', $brakMianownika['value'] === null,
    var_export($brakMianownika['value'], true) . ' — zero znaczyłoby „próbowali i nie wyszło"');
check('przy NULL licznik i mianownik są widoczne',
    $brakMianownika['n'] === 0 && $brakMianownika['d'] === 0);

$zeroWynik = Metrics::compute([
    'agg' => 'ratio',
    'filter' => ['tags' => ['ZDOBYCIE SBZ'], 'has_label' => ['NIE MA TAKIEJ ETYKIETY'],
                 'team_side' => ['us']],
    'of' => ['tags' => ['ZDOBYCIE SBZ'], 'team_side' => ['us']],
], ['match_id' => 1]);
check('mianownik NIEzerowy przy zerowym liczniku daje 0.0, nie NULL',
    $zeroWynik['value'] === 0.0,
    'to JEST wynik: wejścia były, żadne nie spełniło warunku');

// ---------------------------------------------------------------- etykiety
echo "\n== filtr po etykiecie rozróżnia CAŁE nazwy ==\n";

zdarzenie(1, 'PRESSING TEST', 'us', ['PRESSING WYSOKI']);
$dokladne = Metrics::compute(
    ['agg' => 'count', 'filter' => ['tags' => ['PRESSING TEST'], 'has_label' => ['PRESSING']]],
    ['match_id' => 1]
);
check('etykieta PRESSING nie łapie PRESSING WYSOKI', $dokladne['value'] === null || $dokladne['value'] === 0,
    var_export($dokladne['value'], true) . ' — to ta sama pułapka, co CELNY wewnątrz NIECELNY');

$negacja = Metrics::compute(
    ['agg' => 'count',
     'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'no_label' => ['STRZAŁ'],
                  'team_side' => ['us']]],
    ['match_id' => 1]
);
check('filtr „bez etykiety" działa', $negacja['value'] === 2, var_export($negacja['value'], true));

// ---------------------------------------------------------------- none
echo "\n== zdarzenia bez drużyny liczą się dla tenanta ==\n";

$reakcja = Metrics::compute([
    'agg' => 'ratio',
    'filter' => ['tags' => ['STRATA'], 'has_label' => ['REAKCJA'], 'team_side' => ['us', 'none']],
    'of' => ['tags' => ['STRATA'], 'team_side' => ['us', 'none']],
], ['match_id' => 1]);
check('straty bez drużyny weszły do wskaźnika', $reakcja['d'] === 2,
    "d={$reakcja['d']} — pominięcie `none` zabrałoby większość danych (pułapka 5)");
check('reakcja na stratę: 1 z 2', abs((float) $reakcja['value'] - 0.5) < 0.0001);

$bezNone = Metrics::compute([
    'agg' => 'ratio',
    'filter' => ['tags' => ['STRATA'], 'has_label' => ['REAKCJA'], 'team_side' => ['us']],
    'of' => ['tags' => ['STRATA'], 'team_side' => ['us']],
], ['match_id' => 1]);
check('bez `none` ta sama metryka nie ma danych', $bezNone['value'] === null,
    'dowód, że reguła z STAN_PIVOTU faktycznie coś zmienia');

// ---------------------------------------------------------------- SUMA
echo "\n== SUMA sezonu = suma kolejek ==\n";

$def = ['agg' => 'count',
        'filter' => ['tags' => ['ZDOBYCIE SBZ', 'SBZ PODAJĄCY'], 'team_side' => ['us']]];

$m1 = Metrics::compute($def, ['match_id' => 1])['value'];
$m2 = Metrics::compute($def, ['match_id' => 2])['value'];
$suma = Metrics::compute($def, ['match_ids' => [1, 2]])['value'];

check('suma zakresu równa sumie kolejek', $suma === $m1 + $m2,
    "{$m1} + {$m2} != {$suma}");
check('SUMA to ta sama definicja bez filtra meczu', $suma === 6, var_export($suma, true));

$sredniaNaMecz = Metrics::compute(
    ['agg' => 'avg_per_match', 'filter' => $def['filter']],
    ['match_ids' => [1, 2]]
);
check('średnia na mecz dzieli przez liczbę MECZÓW ZE ZDARZENIAMI',
    abs((float) $sredniaNaMecz['value'] - 3.0) < 0.001,
    var_export($sredniaNaMecz['value'], true));

echo "\n== zakres bez zdarzeń ==\n";
$pusty = Metrics::compute($def, ['match_id' => 9999]);
check('mecz bez ani jednego zdarzenia daje NULL, nie zero', $pusty['value'] === null,
    'mecz sprzed migracji 014 nie ma metryki równej zero — on jej nie ma');

// ---------------------------------------------------------------- katalog
echo "\n== katalog tagów rozstrzyga o pokryciu ==\n";

$meta = [
    'dictionary' => [
        'tags' => [
            ['tag' => 'STRZAŁ', 'count' => 3],
            ['tag' => 'ZDOBYCIE SBZ', 'count' => 4],
        ],
        'labels' => [['label' => 'CELNY', 'count' => 2]],
    ],
    'palette' => ['tags' => ['STRZAŁ' => '#E8590C']],
];

check('katalog zapełniony z meta.json', TagCatalog::upsertFromMeta(1, 1, $meta) === 3);
$katalog = TagCatalog::forClub(1);
check('tagi i etykiety rozdzielone', count($katalog) === 3);
check('barwa z palety przepisana',
    ($katalog[array_search('STRZAŁ', array_column($katalog, 'name'), true)]['color'] ?? '') === '#E8590C');

// Drugi import tego samego tagu ma PODBIĆ liczniki, nie założyć drugi wiersz.
TagCatalog::upsertFromMeta(1, 2, $meta);
$poDrugim = TagCatalog::forClub(1);
check('drugi import nie dubluje pozycji', count($poDrugim) === 3);
$strzalKat = $poDrugim[array_search('STRZAŁ', array_column($poDrugim, 'name'), true)];
check('licznik meczów podbity', (int) $strzalKat['seen_matches'] === 2);
check('licznik zdarzeń narasta', (int) $strzalKat['seen_events'] === 6);

$wynik = Metrics::computeAll(['club_id' => 1, 'match_id' => 1]);
$poId = array_column($wynik['metrics'], null, 'id');
$brakujace = array_column($wynik['coverage'], 'missing_tags', 'metric');

check('metryka na tagu Z katalogu ma wartość', $poId['sbz']['value'] !== null);
/*
 * `1x1 DEF.` nie jest w katalogu tego klubu — metryka ma dostać NULL i wpis
 * w pokryciu. To jest cały powód istnienia migracji 015: bez katalogu ta sama
 * sytuacja dawała „0% wygranych pojedynków", czyli zdanie nieprawdziwe.
 */
check('metryka na tagu SPOZA katalogu ma NULL', $poId['duele_def_wygrane']['value'] === null);
check('brak trafia do catalog_coverage', isset($brakujace['duele_def_wygrane']),
    implode(', ', array_keys($brakujace)));
check('pokrycie wymienia BRAKUJĄCE tagi',
    in_array('1x1 DEF.', $brakujace['duele_def_wygrane'] ?? [], true),
    json_encode($brakujace['duele_def_wygrane'] ?? null, JSON_UNESCAPED_UNICODE));
check('metryka z aliasem JEST pokryta jedną nazwą',
    !isset($brakujace['sbz']),
    'klub używa `ZDOBYCIE SBZ` i to wystarcza — wymaganie obu aliasów byłoby błędem');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
