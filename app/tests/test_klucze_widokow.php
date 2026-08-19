<?php
declare(strict_types=1);

/**
 * Kolizje kluczy danych z parametrami renderera.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POWÓD ISTNIENIA — TEN BŁĄD WYSTĄPIŁ DWA RAZY, W DWÓCH RÓŻNYCH SESJACH.
 *
 * `View::render(string $template, array $data)` robi `extract($data, EXTR_SKIP)`.
 * `EXTR_SKIP` NIE NADPISUJE zmiennych już istniejących, a w zasięgu metody
 * istnieją jej własne parametry: `$template` i `$data`. Klucz `'template'`
 * w tablicy danych jest więc po cichu POŁYKANY — widok dostaje nazwę pliku
 * szablonu (napis) zamiast wiersza z bazy.
 *
 * Objaw jest paskudny, bo nie wygląda na kolizję nazw:
 *   `$template !== null`      -> prawda, bo napis nie jest nullem
 *   `$template['version']`    -> „Cannot access offset of type string on string"
 * czyli 500 w miejscu, gdzie kod wygląda na poprawny.
 *
 * Pierwszy raz: `club_dashboard.php` (hub klubu, Sesja 2).
 * Drugi raz: `configurator_import.php` (konfigurator, Sesja 3) — mimo że
 * pierwszy przypadek był świeżo naprawiony i opisany w komentarzu.
 *
 * Skoro pamięć nie wystarczyła dwa razy, pilnuje tego test.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php app/tests/test_klucze_widokow.php
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

// ---------------------------------------------------------------- zakazane klucze
echo "== nazwy zajęte przez renderer ==\n";

/**
 * Nazwy parametrów `View::render()` i `View::page()` odczytane Z KODU, nie
 * przepisane z pamięci. Zmiana sygnatury metody ma automatycznie zmienić
 * zakres tego testu — lista wpisana ręcznie rozjechałaby się przy pierwszym
 * refaktorze i przestała chronić, nie zapalając się na czerwono.
 */
$viewSrc = (string) file_get_contents($root . '/app/src/View.php');

$zajete = [];
foreach (['render', 'page'] as $metoda) {
    if (preg_match('/function\s+' . $metoda . '\s*\(([^)]*)\)/', $viewSrc, $m) === 1) {
        preg_match_all('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', $m[1], $mm);
        foreach ($mm[1] as $param) {
            $zajete[$param] = true;
        }
    }
}

$zajete = array_keys($zajete);
sort($zajete);

check('udało się odczytać parametry renderera z View.php', $zajete !== []);
check('parametr `template` jest wśród zajętych', in_array('template', $zajete, true),
    'znalezione: ' . implode(', ', $zajete));

echo "  (zajęte nazwy: " . implode(', ', $zajete) . ")\n";

/**
 * `extract()` w `render()` MUSI używać EXTR_SKIP.
 *
 * Gdyby ktoś „naprawił" kolizję zmieniając to na EXTR_OVERWRITE, dane
 * z kontrolera nadpisałyby `$template` — czyli nazwę pliku szablonu, w trakcie
 * jego dołączania. Awaria byłaby wtedy gorsza niż problem, który miała
 * rozwiązać, a ten test przestałby mieć sens. Dlatego pilnujemy obu stron.
 */
check('render() używa EXTR_SKIP', str_contains($viewSrc, 'EXTR_SKIP'),
    'zmiana na EXTR_OVERWRITE nadpisałaby nazwę szablonu w trakcie renderu');

// ---------------------------------------------------------------- skan wywołań
echo "\n== żadne wywołanie View::page()/render() nie używa zajętej nazwy ==\n";

/**
 * Pliki, które przekazują dane do widoków. Skanujemy kontroler i wszystko,
 * co woła renderer — nie same widoki, bo kolizja powstaje po stronie
 * WYWOŁUJĄCEGO, przy budowaniu tablicy danych.
 *
 * @return list<string>
 */
function pliki_z_wywolaniami(string $root): array
{
    $out = [];
    $katalogi = [$root . '/app/public', $root . '/app/src', $root . '/app/bin'];
    foreach ($katalogi as $katalog) {
        if (!is_dir($katalog)) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($katalog));
        foreach ($iter as $plik) {
            if ($plik->isFile() && $plik->getExtension() === 'php') {
                $out[] = $plik->getPathname();
            }
        }
    }
    sort($out);
    return $out;
}

$pliki = pliki_z_wywolaniami($root);
check('są pliki do przeskanowania', count($pliki) > 20, count($pliki) . ' plików');

/**
 * Klucze tablicy przekazane do `View::page(...)` / `View::render(...)`.
 *
 * Wycinamy treść wywołania od nawiasu otwierającego do domykającego, licząc
 * zagnieżdżenia — wywołania mają w środku inne wywołania (`View::t(...)`),
 * więc dopasowanie do pierwszego `)` ucięłoby połowę argumentów i test
 * przepuszczałby klucze stojące dalej.
 *
 * @return list<array{plik:string, linia:int, klucz:string}>
 */
function klucze_wywolan(string $sciezka): array
{
    $tresc = (string) file_get_contents($sciezka);
    $out = [];

    if (preg_match_all('/View::(?:page|render)\s*\(/', $tresc, $m, PREG_OFFSET_CAPTURE) === 0) {
        return $out;
    }

    foreach ($m[0] as [$dopasowanie, $offset]) {
        $i = $offset + strlen($dopasowanie) - 1;   // pozycja `(`
        $glebokosc = 0;
        $koniec = null;
        for ($j = $i, $n = strlen($tresc); $j < $n; $j++) {
            if ($tresc[$j] === '(') {
                $glebokosc++;
            } elseif ($tresc[$j] === ')') {
                $glebokosc--;
                if ($glebokosc === 0) {
                    $koniec = $j;
                    break;
                }
            }
        }
        if ($koniec === null) {
            continue;
        }

        $argumenty = substr($tresc, $i, $koniec - $i + 1);
        $linia = substr_count(substr($tresc, 0, $offset), "\n") + 1;

        // Klucze tablicy: 'nazwa' =>
        preg_match_all("/'([a-zA-Z_][a-zA-Z0-9_]*)'\s*=>/", $argumenty, $klucze);
        foreach ($klucze[1] as $klucz) {
            $out[] = ['plik' => $sciezka, 'linia' => $linia, 'klucz' => $klucz];
        }
    }

    return $out;
}

$kolizje = [];
$zbadane = 0;

foreach ($pliki as $plik) {
    foreach (klucze_wywolan($plik) as $poz) {
        $zbadane++;
        if (in_array($poz['klucz'], $zajete, true)) {
            $kolizje[] = str_replace($root . '/', '', $poz['plik'])
                . ':' . $poz['linia'] . " klucz '" . $poz['klucz'] . "'";
        }
    }
}

check('skaner widzi klucze w wywołaniach', $zbadane > 50, $zbadane . ' kluczy');

check(
    'żaden klucz danych nie koliduje z parametrem renderera',
    $kolizje === [],
    implode(' | ', $kolizje)
        . ' — EXTR_SKIP połknie taki klucz, a widok dostanie nazwę szablonu zamiast danych'
);

// ---------------------------------------------------------------- samokontrola
echo "\n== skaner faktycznie łapie kolizję ==\n";

/*
 * Test, który nigdy nie zapala się na czerwono, jest ozdobą. Sprawdzamy na
 * pliku tymczasowym, że skaner wykrywa dokładnie ten wzorzec, dla którego
 * powstał — łącznie z zagnieżdżonym `View::t()`, o który rozbijał się
 * naiwny wariant wyrażenia.
 */
$tmp = sys_get_temp_dir() . '/ca_kolizja_' . bin2hex(random_bytes(6)) . '.php';
file_put_contents($tmp, <<<'PHP'
<?php
View::page('jakis_widok', [
    'title'    => View::t('cos.tam', 1, 2),
    'template' => Templates::current(7),
    'notice'   => null,
]);
PHP);

$znalezione = array_column(klucze_wywolan($tmp), 'klucz');
@unlink($tmp);

check('skaner wyciąga wszystkie klucze mimo zagnieżdżonego wywołania',
    $znalezione === ['title', 'template', 'notice'],
    implode(', ', $znalezione));
// `array_values`, bo `array_intersect` ZACHOWUJE klucze pierwszej tablicy —
// wynik to `[1 => 'template']`, a nie `['template']`.
check('skaner wskazuje `template` jako kolizję',
    array_values(array_intersect($znalezione, $zajete)) === ['template'],
    'bez tego test byłby ozdobą');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
