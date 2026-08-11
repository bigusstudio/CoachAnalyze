<?php
declare(strict_types=1);

/**
 * Zgodność kodu PHP z DWOMA interpreterami naraz.
 *
 * Na lh.pl warstwa żądań chodzi na PHP-FPM 8.3, a cron na PHP CLI 8.4 —
 * różne binaria, różne konfiguracje, ten sam kod. Do tego `app/src/**` jest
 * ładowane przez OBIE warstwy, więc konstrukcja dostępna tylko w 8.4 działa
 * z crona i wywala się w przeglądarce (albo odwrotnie z deprecacjami).
 *
 * Ten test nie zastąpi uruchomienia na obu wersjach, ale łapie klasę błędów,
 * która inaczej wychodzi dopiero na produkcji i tylko w jednej z dwóch warstw.
 *
 * Uruchomienie:  php app/tests/test_php_wersje.php
 */

$root = dirname(__DIR__, 2);

/** Dolna granica: kod musi działać na FPM. */
const WERSJA_MIN = '8.3';
/** Górna granica: kod musi działać na cronie. */
const WERSJA_MAX = '8.4';

/**
 * Funkcje wprowadzone w 8.4 — na FPM 8.3 dają „Call to undefined function".
 * Nie ma ich w `function_exists` na 8.3, więc użycie bez sprawdzenia to awaria.
 */
const FUNKCJE_84 = [
    'array_find', 'array_find_key', 'array_any', 'array_all',
    'mb_trim', 'mb_ltrim', 'mb_rtrim', 'mb_ucfirst', 'mb_lcfirst',
    'http_get_last_response_headers', 'http_clear_last_response_headers',
    'request_parse_body', 'grapheme_str_split', 'bcdivmod', 'fpow',
];

/**
 * Funkcje z 8.5 — lokalny interpreter potrafi być nowszy niż produkcja
 * (tu: 8.5), więc kod napisany „bo działa u mnie" padnie na obu serwerach.
 */
const FUNKCJE_85 = [
    'array_first', 'array_last', 'get_error_handler', 'get_exception_handler',
    'locale_is_right_to_left', 'curl_multi_get_handles',
];

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

/** @return list<string> */
function wszystkiePliki(string $root): array
{
    $lista = [];
    foreach (['app/public', 'app/src', 'app/bin', 'app/tests'] as $katalog) {
        $sciezka = $root . '/' . $katalog;
        if (!is_dir($sciezka)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sciezka, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $plik) {
            if ($plik->isFile() && $plik->getExtension() === 'php') {
                $lista[] = $plik->getPathname();
            }
        }
    }
    sort($lista);
    return $lista;
}

/**
 * Wywołania wskazanych funkcji — tokenizerem, nie tekstem. Komentarze i napisy
 * wymieniają te nazwy (choćby ten plik), a `grep` zgłaszałby je jako użycie.
 *
 * @param list<string> $szukane
 * @return list<array{funkcja:string, linia:int}>
 */
function wywolania(string $kod, array $szukane): array
{
    $tokeny = token_get_all($kod);
    $wynik = [];
    $liczba = count($tokeny);

    for ($i = 0; $i < $liczba; $i++) {
        if (!is_array($tokeny[$i]) || $tokeny[$i][0] !== T_STRING) {
            continue;
        }
        if (!in_array(strtolower($tokeny[$i][1]), $szukane, true)) {
            continue;
        }

        $poprzedni = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokeny[$j]) && in_array($tokeny[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $poprzedni = $tokeny[$j];
            break;
        }
        if (is_array($poprzedni) && in_array($poprzedni[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
            continue;
        }

        $nastepny = null;
        for ($j = $i + 1; $j < $liczba; $j++) {
            if (is_array($tokeny[$j]) && in_array($tokeny[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $nastepny = $tokeny[$j];
            break;
        }

        if ($nastepny === '(') {
            $wynik[] = ['funkcja' => strtolower($tokeny[$i][1]), 'linia' => (int) $tokeny[$i][2]];
        }
    }

    return $wynik;
}

/**
 * Parametry z niejawną dopuszczalnością null: `function f(string $x = null)`.
 *
 * Działa na obu wersjach, ale na 8.4 emituje E_DEPRECATED — a ostrzeżenia
 * z crona lądują w logu i zagłuszają rzeczywiste błędy. W 9.0 to będzie błąd.
 * Poprawna postać: `?string $x = null`.
 *
 * @return list<array{param:string, linia:int}>
 */
function niejawneNullable(string $kod): array
{
    $tokeny = array_values(array_filter(
        token_get_all($kod),
        static fn($t) => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
    ));
    $wynik = [];
    $liczba = count($tokeny);

    for ($i = 0; $i < $liczba; $i++) {
        if (!is_array($tokeny[$i]) || $tokeny[$i][0] !== T_VARIABLE) {
            continue;
        }

        // Wzorzec: TYP $zmienna = null  — bez `?` i bez `|null` przed typem.
        if (!isset($tokeny[$i + 1], $tokeny[$i + 2]) || $tokeny[$i + 1] !== '=') {
            continue;
        }
        if (!is_array($tokeny[$i + 2]) || strtolower((string) $tokeny[$i + 2][1]) !== 'null') {
            continue;
        }

        // Typ stoi bezpośrednio przed zmienną.
        $typ = $tokeny[$i - 1] ?? null;
        if (!is_array($typ) || !in_array($typ[0], [T_STRING, T_ARRAY, T_CALLABLE], true)) {
            continue;
        }

        // `?typ` i `typ|null` są poprawne — szukamy tylko gołego typu.
        $przedTypem = $tokeny[$i - 2] ?? null;
        if ($przedTypem === '?' || $przedTypem === '|') {
            continue;
        }
        // Poprzedza go przecinek albo nawias otwierający listy parametrów.
        if ($przedTypem !== ',' && $przedTypem !== '(') {
            continue;
        }

        $wynik[] = ['param' => (string) $tokeny[$i][1], 'linia' => (int) $typ[2]];
    }

    return $wynik;
}

$pliki = wszystkiePliki($root);

echo "== zakres ==\n";
echo "  lokalny interpreter: " . PHP_VERSION . "\n";
echo "  cel: FPM " . WERSJA_MIN . " (przeglądarka) + CLI " . WERSJA_MAX . " (cron)\n";
check('są pliki do sprawdzenia', count($pliki) > 10, count($pliki) . ' plików');

echo "\n== składnia ==\n";

// `php -l` sprawdza wersją lokalną — nie udowadnia zgodności z 8.3, ale plik
// z błędem składni nie ma po co iść dalej.
$zleZlozone = [];
foreach ($pliki as $plik) {
    $wyjscie = [];
    $kod = 0;
    exec('php -l ' . escapeshellarg($plik) . ' 2>&1', $wyjscie, $kod);
    if ($kod !== 0) {
        $zleZlozone[] = str_replace($root . '/', '', $plik) . ': ' . implode(' ', $wyjscie);
    }
}
check('wszystkie pliki parsują się', $zleZlozone === [], implode(' | ', $zleZlozone));

echo "\n== funkcje niedostępne na PHP-FPM 8.3 ==\n";

$z84 = [];
$z85 = [];
foreach ($pliki as $plik) {
    $kod = (string) file_get_contents($plik);
    $nazwa = str_replace($root . '/', '', $plik);
    foreach (wywolania($kod, FUNKCJE_84) as $t) {
        $z84[] = "{$nazwa}:{$t['linia']} — {$t['funkcja']}()";
    }
    foreach (wywolania($kod, FUNKCJE_85) as $t) {
        $z85[] = "{$nazwa}:{$t['linia']} — {$t['funkcja']}()";
    }
}

check('kod nie używa funkcji z PHP 8.4', $z84 === [], implode(' | ', $z84));
check('kod nie używa funkcji z PHP 8.5', $z85 === [], implode(' | ', $z85));

echo "\n== deprecacje PHP 8.4 (cron zalewa nimi log) ==\n";

$nullable = [];
foreach ($pliki as $plik) {
    $kod = (string) file_get_contents($plik);
    $nazwa = str_replace($root . '/', '', $plik);
    foreach (niejawneNullable($kod) as $t) {
        $nullable[] = "{$nazwa}:{$t['linia']} — {$t['param']}";
    }
}
check('brak parametrów z niejawnym null (użyj ?typ)', $nullable === [], implode(' | ', $nullable));

echo "\n== detektory faktycznie działają ==\n";

check('wykrywa array_find z 8.4',
    wywolania('<?php $x = array_find($a, $f);', FUNKCJE_84) !== []);
check('NIE zgłasza nazwy 8.4 w komentarzu',
    wywolania('<?php // array_find() jest z 8.4' . "\n" . '$x = 1;', FUNKCJE_84) === []);
check('wykrywa niejawne nullable',
    niejawneNullable('<?php function f(string $x = null) {}') !== []);
check('NIE zgłasza jawnego ?typ',
    niejawneNullable('<?php function f(?string $x = null) {}') === []);
check('NIE zgłasza typu unijnego z null',
    niejawneNullable('<?php function f(string|null $x = null) {}') === []);
check('NIE zgłasza domyślnego null bez typu',
    niejawneNullable('<?php function f($x = null) {}') === []);

echo "\n== deklaracja wersji w konfiguracji ==\n";

$composer = $root . '/composer.json';
if (is_file($composer)) {
    $dane = json_decode((string) file_get_contents($composer), true);
    $wymog = (string) ($dane['require']['php'] ?? '');
    check('composer.json deklaruje minimum ' . WERSJA_MIN,
        str_contains($wymog, WERSJA_MIN), $wymog !== '' ? $wymog : 'brak wpisu require.php');
} else {
    echo "  --   composer.json nie istnieje (projekt bez zależności) — pomijam\n";
}

$cron = $root . '/deploy/crontab.example';
check('wzorzec crona podaje interpreter jawną ścieżką',
    is_file($cron) && preg_match('#/usr/local/php8\d/bin/php#', (string) file_get_contents($cron)) === 1,
    'bez pełnej ścieżki cron weźmie domyślne `php`, które bywa inną wersją');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
