<?php
declare(strict_types=1);

/**
 * Kalkulator xG: boisko na pełną szerokość, siatka i znaczniki strzałów.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POWÓD ISTNIENIA — JEDNA RZECZ, KTÓREJ NIE WOLNO ZEPSUĆ.
 *
 * Boisko jest przyciskiem formularza (`input type="image"`), a przeglądarka
 * wysyła współrzędne kliknięcia w układzie PUDEŁKA UKŁADU tego elementu.
 * Rozciągnięcie boiska przez `width: 100%` zmieniłoby to pudełko — te same
 * 105×68 metrów opisywałaby wtedy inna liczba pikseli przy każdej szerokości
 * okna, a `XgCalc::pxToMeters()` dzieli przez STAŁĄ `PX_NA_METR = 5`.
 * Skutek byłby cichy: ten sam klik dawałby inną wartość xG na laptopie niż
 * na monitorze i nic by o tym nie powiedziało.
 *
 * Dlatego boisko skaluje `transform`, który zachowuje układ współrzędnych
 * (sprawdzone w przeglądarce: klik w wizualny środek boiska rozciągniętego
 * do 900 px zwraca 263×169, czyli środek przestrzeni 525×340).
 *
 * Test jest STATYCZNY, bo przeglądarki w CI nie ma. Pilnuje tego, co da się
 * sprawdzić bez niej: że atrybuty przestrzeni współrzędnych stoją na miejscu,
 * że nikt nie dołożył `width: 100%` na samym boisku i że przeliczenie metrów
 * na procent kontenera jest odwracalne.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php app/tests/test_xg_boisko.php
 */

$root = dirname(__DIR__, 2);

$ok = 0;
$fail = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $ok, $fail;
    if ($condition) {
        $ok++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$widok = (string) file_get_contents($root . '/app/src/Views/xg_calc.php');

/*
 * Kopia widoku z WYCIĘTYMI wstawkami PHP — do dopasowań obejmujących cały
 * znacznik. Bez tego `>` z zamknięcia `?>` wygląda dla wyrażenia jak koniec
 * znacznika i atrybuty stojące dalej są niewidoczne. Ta sama pułapka, którą
 * opisuje `test_forms.php`.
 */
$widokBezPhp = (string) preg_replace('/<\?(php|=).*?\?>/s', 'WSTAWKA', $widok);
$css   = (string) file_get_contents($root . '/app/public/assets/app.css');
$svg   = (string) file_get_contents($root . '/app/public/assets/boisko.svg');

/** Sekcja arkusza od nagłówka do końca pliku. */
$sekcja = strstr($css, '/* --- kalkulator xG');

echo "== przestrzeń współrzędnych jest nietknięta ==\n";

check('boisko ma jawne atrybuty width/height',
    preg_match('/<input[^>]*type="image"[^>]*width="525"[^>]*height="340"/s', $widokBezPhp) === 1,
    'to one wyznaczają przestrzeń, w której przeglądarka zgłasza kliknięcie');

require_once $root . '/app/src/XgCalc.php';
$skala = (new ReflectionClass(CoachAnalyze\XgCalc::class))->getConstant('PX_NA_METR');
// Porównanie luźne: PHP dzieli 525/5 do liczby całkowitej, a 340/5 też —
// ścisłe `===` z literałem zmiennoprzecinkowym byłoby fałszywie czerwone.
check('skala px→m zgadza się z rozmiarem boiska',
    $skala === 5 && 525 / $skala == 105 && 340 / $skala == 68,
    "PX_NA_METR={$skala}; 525/{$skala}=" . (525 / $skala) . ', 340/' . $skala . '=' . (340 / $skala));

/*
 * `width: 100%` NA SAMYM BOISKU jest zakazane — to jest ta jedna linia,
 * która po cichu przesuwa każdą przyszłą wartość xG.
 */
check('boisko nie jest skalowane szerokością',
    $sekcja !== false && preg_match('/\.xg-boisko__pole\s*\{[^}]*width\s*:/s', $sekcja) !== 1,
    'szerokość zmienia pudełko układu, a z nim układ współrzędnych');

check('boisko skalowane przez transform',
    $sekcja !== false && preg_match('/\.xg-boisko__pole\s*\{[^}]*transform\s*:\s*scale/s', $sekcja) === 1);

check('skala liczona z szerokości kontenera',
    $sekcja !== false && str_contains($sekcja, 'atan2(100cqi, 525px)'),
    'iloraz dwóch długości — calc() tego nie umie, tan(atan2()) tak');

check('brak obsługi atan2 daje skalę 1:1, nie błędną',
    $sekcja !== false
    && preg_match('/\.xg-boisko__pole\s*\{[^}]*transform\s*:\s*scale\(1\)/s', $sekcja) === 1
    && str_contains($sekcja, '@supports'),
    'węższe boisko jest poprawne, przeskalowane błędnie — nie jest');

echo "\n== proporcje i responsywność ==\n";

check('kontener trzyma proporcje boiska',
    $sekcja !== false && preg_match('/\.xg-boisko\s*\{[^}]*aspect-ratio\s*:\s*525\s*\/\s*340/s', $sekcja) === 1);
check('proporcje kontenera to proporcje boiska w metrach',
    abs((525 / 340) - (105 / 68)) < 0.0001);
check('kontener zadaje odniesienie dla jednostek cqi',
    $sekcja !== false && preg_match('/\.xg-boisko\s*\{[^}]*container-type\s*:\s*inline-size/s', $sekcja) === 1);

echo "\n== współrzędne przeżywają zmianę rozmiaru ==\n";

/*
 * Znaczniki pozycjonujemy w PROCENTACH boiska. Przy zachowanych proporcjach
 * procent jest niezależny od szerokości — sprawdzamy, że przeliczenie
 * metry → procent → metry wraca do punktu wyjścia dla całego zakresu,
 * łącznie z rogami i punktem karnym.
 */
$punkty = [[0.0, 0.0], [52.5, 34.0], [105.0, 68.0], [94.0, 34.0], [11.0, 5.5], [88.5, 62.7]];
$maxBlad = 0.0;
foreach ($punkty as [$xm, $ym]) {
    $lewo = ($xm / 105) * 100;
    $gora = ($ym / 68) * 100;
    // Widok zaokrągla do trzech miejsc — odtwarzamy dokładnie to zaokrąglenie.
    $lewo = (float) number_format($lewo, 3, '.', '');
    $gora = (float) number_format($gora, 3, '.', '');
    $maxBlad = max($maxBlad, abs(($lewo / 100) * 105 - $xm), abs(($gora / 100) * 68 - $ym));
}
check('metry → procent → metry wraca do punktu wyjścia', $maxBlad < 0.002,
    'największy błąd: ' . $maxBlad . ' m');

check('znacznik pozycjonowany w procentach, nie w pikselach',
    preg_match('/style="left:\s*WSTAWKA%;/', $widokBezPhp) === 1
    && preg_match('/style="left:\s*WSTAWKA(px|em|rem)/', $widokBezPhp) !== 1);

check('znacznik przycięty do boiska',
    str_contains($widok, 'min(100.0') && str_contains($widok, 'max(0.0'),
    'strzał spoza zakresu nie ma wyjechać poza kontener');

echo "\n== siatka pomocnicza ==\n";

check('siatka jest w CSS, nie w obrazku',
    $sekcja !== false && str_contains($sekcja, 'repeating-linear-gradient'));
check('siatka leży POD liniami boiska',
    !str_contains($svg, '<rect x="0" y="0" width="525" height="340" fill='),
    'nieprzezroczyste tło w SVG zasłoniłoby siatkę w całości');
check('siatka co 1/10 długości', $sekcja !== false && str_contains($sekcja, '1px 10%'));
check('siatka co 1/8 szerokości', $sekcja !== false && str_contains($sekcja, '1px 12.5%'));
check('siatka bierze barwę ze zmiennej motywu',
    $sekcja !== false && str_contains($sekcja, 'var(--murawa-siatka)'));
check('siatka jest subtelna, nie kontrastowa',
    $sekcja !== false && preg_match('/murawa-siatka\)\s*(\d+)%/', $sekcja, $m) === 1 && (int) $m[1] <= 25,
    'ma prowadzić oko, nie konkurować z liniami pól');

/*
 * BEZ PRZYCIĄGANIA DO SIATKI. Klik zostaje swobodny — gdyby gdziekolwiek
 * pojawiło się zaokrąglanie pozycji do kratki, wartości xG przestałyby
 * odpowiadać temu, co operator wskazał.
 */
require_once $root . '/app/src/Config.php';
$kontroler = (string) file_get_contents($root . '/app/public/index.php');
preg_match('/function addXgShot.*?\n\}/s', $kontroler, $mm);
check('kliknięcie nie jest przyciągane do siatki',
    isset($mm[0]) && !preg_match('/\bround\s*\(|\bfloor\s*\(|\bceil\s*\(/', $mm[0]),
    'siatka tylko prowadzi oko');

echo "\n== znaczniki strzałów ==\n";

check('każdy strzał z listy dostaje znacznik',
    preg_match('/foreach \(\$shots as \$s\).*?xg-znacznik/s', $widok) === 1);
check('znacznik i wiersz mają wspólny identyfikator',
    substr_count($widok, 'data-strzal="<?= (int) $s[\'id\'] ?>"') >= 2,
    'to on paruje kropkę z wierszem listy');
check('noga i główka różnią się odmianą',
    str_contains($widok, "xg-znacznik--<?= View::e((string) \$s['body_part']) ?>")
    && $sekcja !== false && str_contains($sekcja, '.xg-znacznik--head'));

/*
 * Kształt ● / ○ / ◆ koduje w raporcie WYNIK strzału (CLAUDE.md §3, pułapka 6).
 * Użycie pustego środka do rozróżnienia nogi i główki czytałoby się jako
 * „niecelny" — dlatego główka ma dodatkowy obrys, a nie brak wypełnienia.
 */
check('główka nie podszywa się pod znacznik „niecelny"',
    $sekcja !== false
    && preg_match('/\.xg-znacznik--head\s*\{[^}]*outline/s', $sekcja) === 1
    && preg_match('/\.xg-znacznik--head\s*\{[^}]*background\s*:\s*(none|transparent)/s', $sekcja) !== 1);

echo "\n== podświetlenie w obie strony, bez skryptu ==\n";

check('reguły parujące powstają w widoku',
    str_contains($widok, '.xg:has(') && str_contains($widok, '<style>'));
check('podświetlenie działa z kropki na wiersz',
    preg_match('/\.xg:has\(\[data-strzal[^)]*\]:hover\)\s*\.xg-wiersz/s', $widok) === 1);
check('i z wiersza na kropkę',
    preg_match('/\.xg:has\(\[data-strzal[^)]*\]:hover\)\s*\.xg-znacznik/s', $widok) === 1);
check('klawiatura też podświetla', str_contains($widok, ':focus-within'));

// Odstępstwo od „zero skryptów" obejmuje chmurki i wskaźnik pracy (CLAUDE.md §9).
// Kalkulator do niego NIE należy i nie ma prawa dołożyć skryptu.
check('kalkulator nie dokłada skryptu', !preg_match('/<script/i', $widok),
    'podświetlenie na hover da się zrobić bez JavaScriptu, więc jest bez niego');

// Barwy zostają w arkuszu — wygenerowany blok ma same selektory i zmienne.
preg_match('/<style>(.*?)<\/style>/s', $widok, $blok);
check('wygenerowany blok nie zawiera żadnej barwy wprost',
    isset($blok[1]) && preg_match('/#[0-9A-Fa-f]{3,8}\b/', $blok[1]) !== 1);

echo "\n== motyw ==\n";

foreach (['--murawa', '--murawa-siatka', '--strzal', '--strzal-obrys'] as $zmienna) {
    check("zmienna {$zmienna} zdefiniowana w obu motywach",
        substr_count($css, $zmienna . ':') >= 3,
        'jasny, prefers-color-scheme i data-theme="dark"');
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
