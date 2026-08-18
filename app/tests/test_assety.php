<?php
declare(strict_types=1);

/**
 * Cache-busting zasobów statycznych.
 *
 * POWÓD ISTNIENIA: po wdrożeniu przeglądarki podawały stary arkusz i stary
 * skrypt, bo `/assets/*` szło pod stałym adresem i BEZ nagłówka `Cache-Control`.
 * Brak tego nagłówka nie znaczy „nie buforuj" — znaczy buforowanie heurystyczne,
 * czyli zgodę na podanie kopii bez pytania serwera. Objawem był panel
 * wyglądający po deployu jak przed nim, do czasu twardego odświeżenia.
 *
 * Naprawa ma dwie części i obie muszą trzymać się razem:
 *   1. `View::asset()` dokleja `?v=<filemtime>` — adres zmienia się wtedy
 *      i tylko wtedy, gdy plik się zmienił,
 *   2. `.htaccess` buforuje bezterminowo WYŁĄCZNIE adresy z wersją, a adresy
 *      bez wersji każe rewalidować.
 *
 * Sam punkt 2 bez punktu 1 przypiąłby stare pliki na rok. Dlatego ten zestaw
 * pilnuje przede wszystkim tego, że w szablonach NIE MA surowych adresów
 * `/assets/…` — jeden przeoczony `href` znosi całą naprawę.
 *
 * Uruchomienie:  php app/tests/test_assety.php
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

/** Kod PHP bez komentarzy — komentarze MUSZĄ móc cytować to, czego zakazujemy. */
function bezKomentarzy(string $source): string
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

// ---------------------------------------------------------------- szablony
echo "== szablony linkują zasoby wyłącznie przez View::asset() ==\n";

$widoki = glob($root . '/app/src/Views/*.php') ?: [];
check('są szablony do sprawdzenia', count($widoki) > 10, count($widoki) . ' plików');

$surowe = [];       // plik:linia => treść z surowym adresem
$przezAsset = [];   // plik => ile wywołań View::asset()

foreach ($widoki as $plik) {
    $nazwa = basename($plik);
    $kod = bezKomentarzy((string) file_get_contents($plik));

    /*
     * SUROWY ADRES W ATRYBUCIE — to jest ta jedna rzecz, której szukamy.
     *
     * Dopasowujemy `href="/assets/…"` i `src="/assets/…"`, czyli adres wpisany
     * wprost, bez przejścia przez `View::asset()`. Wywołanie helpera wygląda
     * inaczej (`href="<?= … View::asset('/assets/…') … ?>"`), więc nie wpada
     * w to dopasowanie — cudzysłów po `=` jest natychmiast po nim.
     */
    preg_match_all('/\b(?:href|src)\s*=\s*"\/assets\/[^"]*"/i', $kod, $trafienia);
    foreach ($trafienia[0] as $t) {
        $surowe[] = $nazwa . ': ' . $t;
    }

    $przezAsset[$nazwa] = preg_match_all('/View::asset\(\s*\'\/assets\//', $kod);
}

check(
    'żaden szablon nie linkuje /assets/ surowym adresem',
    $surowe === [],
    implode(' | ', $surowe)
);

$sumaAsset = array_sum($przezAsset);
check('szablony wołają View::asset() dla zasobów', $sumaAsset >= 3,
    'znaleziono wywołań: ' . $sumaAsset);

// Trzy znane zasoby — gdyby któryś wypadł z wersjonowania, chcemy to wiedzieć
// z nazwy, a nie z sumy.
$layout  = bezKomentarzy((string) file_get_contents($root . '/app/src/Views/layout.php'));
$xgCalc  = bezKomentarzy((string) file_get_contents($root . '/app/src/Views/xg_calc.php'));

foreach ([
    'arkusz panelu (layout)'      => [$layout, '/assets/app.css'],
    'skrypt chmurek (layout)'     => [$layout, '/assets/powiadomienia.js'],
    'boisko kalkulatora (xg_calc)' => [$xgCalc, '/assets/boisko.svg'],
] as $opis => [$zrodlo, $adres]) {
    check(
        "wersjonowany: {$opis}",
        str_contains($zrodlo, "View::asset('" . $adres . "')"),
        $adres
    );
}

// ---------------------------------------------------------------- helper
echo "\n== View::asset(): wersja z filemtime, obie ścieżki układu ==\n";

/*
 * `View` wciągamy WPROST, bez bootstrapu: ten zestaw jest statyczny i chodzi
 * w CI, gdzie nie ma `.env`. `asset()` nie dotyka konfiguracji ani bazy.
 */
if (!defined('CA_ROOT')) {
    define('CA_ROOT', $root);
}
require_once $root . '/app/src/View.php';

use CoachAnalyze\View;

$adresCss = View::asset('/assets/app.css');
check('adres niesie parametr wersji',
    preg_match('#^/assets/app\.css\?v=[0-9a-z]+$#', $adresCss) === 1, $adresCss);

$mtime = (int) filemtime($root . '/app/public/assets/app.css');
check('wersja pochodzi z filemtime pliku',
    $adresCss === '/assets/app.css?v=' . base_convert((string) $mtime, 10, 36),
    $adresCss . ' vs mtime ' . $mtime);

check('powtórne wywołanie daje ten sam adres (bufor w żądaniu)',
    View::asset('/assets/app.css') === $adresCss);

check('różne pliki dostają własne wersje',
    View::asset('/assets/powiadomienia.js') !== $adresCss
    && str_contains(View::asset('/assets/powiadomienia.js'), '?v='));

/*
 * BRAK PLIKU: adres BEZ parametru, nie wersja wzięta z sufitu.
 *
 * Zmyślona wartość przypięłaby przypadkowy adres na długo. Adres bez wersji
 * trafia natomiast na `must-revalidate` z `.htaccess` — degradacja do
 * zachowania poprawnego, tylko mniej oszczędnego.
 */
$nieistniejacy = View::asset('/assets/nie-ma-takiego-pliku.css');
check('brak pliku = adres bez wersji, nie wersja zmyślona',
    $nieistniejacy === '/assets/nie-ma-takiego-pliku.css', $nieistniejacy);

/*
 * UKŁAD PRODUKCYJNY. Zawartość `app/public/` ląduje w produkcji PIĘTRO WYŻEJ
 * (deploy.sh, przejście 2 rsync), więc ten sam adres publiczny leży pod inną
 * ścieżką na dysku. Sprawdzenie tylko układu repozytorium przechodziłoby
 * w CI i cicho przestawało działać na produkcji — ta klasa błędu wracała
 * w tym repozytorium dwa razy (patrz test_layout.php).
 */
$udawanaProdukcja = sys_get_temp_dir() . '/ca_assety_' . bin2hex(random_bytes(6));
@mkdir($udawanaProdukcja . '/assets', 0770, true);
file_put_contents($udawanaProdukcja . '/assets/app.css', '/* x */');
touch($udawanaProdukcja . '/assets/app.css', 1_700_000_000);

$wynikProd = trim((string) shell_exec(
    escapeshellarg(PHP_BINARY) . ' -r '
    . escapeshellarg(
        'define("CA_ROOT", ' . var_export($udawanaProdukcja, true) . ');'
        . 'require ' . var_export($root . '/app/src/View.php', true) . ';'
        . 'echo CoachAnalyze\\View::asset("/assets/app.css");'
    ) . ' 2>/dev/null'
));
check('układ produkcyjny ({domena}/assets/…) też dostaje wersję',
    $wynikProd === '/assets/app.css?v=' . base_convert('1700000000', 10, 36),
    $wynikProd);

@unlink($udawanaProdukcja . '/assets/app.css');
@rmdir($udawanaProdukcja . '/assets');
@rmdir($udawanaProdukcja);

// ---------------------------------------------------------------- .htaccess
echo "\n== .htaccess: buforowanie tylko dla adresów z wersją ==\n";

$htaccess = (string) file_get_contents($root . '/app/public/.htaccess');
$reguly = [];
// Podział wierszy jawnie, nie przez `\R` — bez modyfikatora `u` łapie bajt 0x85,
// czyli drugi bajt polskiego `ą` (ta sama pułapka co w test_layout.php).
foreach (preg_split('/\r\n|\n|\r/', $htaccess) ?: [] as $linia) {
    $linia = trim($linia);
    if ($linia !== '' && !str_starts_with($linia, '#')) {
        $reguly[] = $linia;
    }
}
$czynne = implode("\n", $reguly);

check('znacznik wersjonowania ustawiany z QUERY_STRING',
    preg_match('/RewriteCond\s+%\{QUERY_STRING\}\s+\(\^\|&\)v=/', $czynne) === 1);
check('znacznik dotyczy wyłącznie /assets/',
    preg_match('/RewriteRule\s+\^assets\/\s+-\s+\[E=CA_ZASOB_WERSJONOWANY:1\]/', $czynne) === 1);

check('adres Z wersją buforowany bezterminowo',
    preg_match('/Header set Cache-Control "public, max-age=31536000, immutable" env=CA_ZASOB_WERSJONOWANY/', $czynne) === 1);
check('adres BEZ wersji zawsze rewalidowany',
    preg_match('/Header set Cache-Control "[^"]*must-revalidate" env=!CA_ZASOB_WERSJONOWANY/', $czynne) === 1);

/*
 * ZAWĘŻENIE DO PLIKÓW STATYCZNYCH JEST OBOWIĄZKOWE.
 *
 * `Header set` wygrywa z nagłówkiem wysłanym przez PHP (faza fixup). Reguła
 * `Cache-Control` bez `FilesMatch` nadpisałaby `no-store`, który
 * `app/src/bootstrap.php` wysyła dla stron panelu — i strony za sesją
 * zaczęłyby lądować na dysku, razem z treścią widoczną po wylogowaniu
 * przyciskiem „wstecz".
 */
$blokCache = '';
if (preg_match('/<FilesMatch\s+"[^"]*css[^"]*">(.*?)<\/FilesMatch>/s', $czynne, $m) === 1) {
    $blokCache = $m[0];
}
check('reguły Cache-Control stoją wewnątrz <FilesMatch> dla plików statycznych',
    $blokCache !== '' && str_contains($blokCache, 'Cache-Control'),
    'bez tego nadpisałyby no-store stron panelu');
check('FilesMatch nie obejmuje .php',
    $blokCache !== '' && !preg_match('/<FilesMatch\s+"[^"]*\bphp\b/', $blokCache));

// Zabezpieczenie przed cofnięciem naprawy: bezwarunkowa, długa reguła bez
// znacznika przypięłaby stare pliki także pod adresem bez wersji.
$bezwarunkowe = preg_match_all(
    '/Header\s+(always\s+)?set\s+Cache-Control\s+"[^"]*max-age=(?!0\b)\d+[^"]*"(?!\s+env=)/i',
    $czynne
);
check('brak bezwarunkowej, długiej reguły Cache-Control',
    $bezwarunkowe === 0,
    'długi max-age bez env= przypnie także adresy bez wersji');

// ---------------------------------------------------------------- panel
echo "\n== strony panelu nadal bez buforowania ==\n";

$bootstrap = bezKomentarzy((string) file_get_contents($root . '/app/src/bootstrap.php'));
check('bootstrap nadal wysyła no-store dla stron panelu',
    str_contains($bootstrap, 'no-store'),
    'strony za sesją nie mają leżeć na dysku');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
