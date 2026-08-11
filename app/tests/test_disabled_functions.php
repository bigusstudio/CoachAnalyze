<?php
declare(strict_types=1);

/**
 * Skaner: warstwa obsługująca żądania nie wywołuje funkcji wyłączonych na PHP-FPM.
 *
 * PHP-FPM na lh.pl ma `disable_functions` obejmujące uruchamianie procesów.
 * W CLI te same funkcje działają — dlatego błąd nie wychodzi ani lokalnie, ani
 * w testach jednostkowych, tylko dopiero w przeglądarce, na produkcji.
 * Objaw z logu: „Call to undefined function CoachAnalyze\proc_open()".
 *
 * ZAKRES: app/public/index.php oraz app/src/** — czyli wszystko, co może zostać
 * wykonane w odpowiedzi na żądanie HTTP.
 * POZA ZAKRESEM: app/bin/** — to warstwa CLI, uruchamiana z crona, i tam
 * uruchamianie procesów jest właśnie tym, co ma się dziać.
 *
 * ANALIZA TOKENIZEREM, nie wyszukiwaniem tekstu: komentarze w tym projekcie
 * wymieniają te funkcje z nazwy (bo wyjaśniają, dlaczego ich nie używamy),
 * a `grep` zgłaszałby je jako naruszenia.
 *
 * Uruchomienie:  php app/tests/test_disabled_functions.php
 */

$root = dirname(__DIR__, 2);

/**
 * Pełna lista z produkcji (zweryfikowana na serwerze). Trzymamy ją tutaj, a nie
 * w konfiguracji, bo test ma pilnować kodu także wtedy, gdy `.env` nie istnieje.
 */
const WYLACZONE = [
    'exec', 'system', 'passthru', 'shell_exec', 'proc_close', 'proc_open', 'dl', 'popen',
    'show_source', 'posix_kill', 'posix_mkfifo', 'posix_getpwuid', 'posix_setpgid',
    'posix_setsid', 'posix_setuid', 'posix_setgid', 'posix_seteuid', 'posix_setegid',
    'posix_uname', 'opcache_reset', 'opcache_invalidate', 'opcache_compile_file',
    'opcache_get_configuration', 'opcache_get_status',
];

/**
 * Funkcje spoza listy hostingu, ale o tym samym skutku — też nie mają czego
 * szukać w warstwie żądań.
 */
const DODATKOWE = ['proc_get_status', 'proc_terminate', 'proc_nice', 'pcntl_exec', 'pcntl_fork'];

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

/**
 * Wywołania funkcji w pliku, znalezione przez tokenizer.
 *
 * Interesuje nas WYWOŁANIE: nazwa funkcji, po której (pomijając białe znaki)
 * stoi nawias otwierający, i która nie jest poprzedzona `->`, `::`, `function`
 * ani `new`. Dzięki temu metoda o nazwie `exec()` na obiekcie nie jest mylona
 * z funkcją wbudowaną, a `$this->system` nie jest wywołaniem `system()`.
 *
 * @param list<string> $szukane
 * @return list<array{funkcja:string, linia:int}>
 */
function znajdzWywolania(string $kod, array $szukane): array
{
    $tokeny = token_get_all($kod);
    $znalezione = [];
    $liczba = count($tokeny);

    for ($i = 0; $i < $liczba; $i++) {
        $token = $tokeny[$i];

        if (!is_array($token) || $token[0] !== T_STRING) {
            continue;
        }

        $nazwa = strtolower($token[1]);
        if (!in_array($nazwa, $szukane, true)) {
            continue;
        }

        // Poprzedni znaczący token — odsiewa metody i deklaracje.
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
        if (is_array($poprzedni) && defined('T_NULLSAFE_OBJECT_OPERATOR') && $poprzedni[0] === T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        // Następny znaczący token musi być nawiasem otwierającym.
        $nastepny = null;
        for ($j = $i + 1; $j < $liczba; $j++) {
            if (is_array($tokeny[$j]) && in_array($tokeny[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $nastepny = $tokeny[$j];
            break;
        }

        if ($nastepny === '(') {
            $znalezione[] = ['funkcja' => $nazwa, 'linia' => (int) $token[2]];
        }
    }

    return $znalezione;
}

/** @return list<string> */
function plikiWarstwyZadan(string $root): array
{
    $lista = [$root . '/app/public/index.php'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/app/src', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $plik) {
        if ($plik->isFile() && $plik->getExtension() === 'php') {
            $lista[] = $plik->getPathname();
        }
    }

    sort($lista);
    return $lista;
}

$szukane = array_merge(WYLACZONE, DODATKOWE);
$pliki = plikiWarstwyZadan($root);

echo "== zakres skanowania ==\n";
check('są pliki do przeskanowania', count($pliki) > 5, count($pliki) . ' plików');
check('index.php objęty skanem',
    in_array($root . '/app/public/index.php', $pliki, true));
check('app/bin/ POZA skanem — tam procesy są dozwolone',
    !array_filter($pliki, static fn($p) => str_contains($p, '/app/bin/')));

echo "\n== wywołania funkcji wyłączonych na FPM ==\n";

$naruszenia = [];
foreach ($pliki as $plik) {
    $kod = (string) file_get_contents($plik);
    foreach (znajdzWywolania($kod, $szukane) as $trafienie) {
        $naruszenia[] = sprintf(
            '%s:%d — %s()',
            str_replace($root . '/', '', $plik),
            $trafienie['linia'],
            $trafienie['funkcja']
        );
    }
}

check(
    'warstwa żądań nie wywołuje żadnej z wyłączonych funkcji',
    $naruszenia === [],
    implode(' | ', $naruszenia)
);

echo "\n== skaner faktycznie wykrywa ==\n";

// Bez tego sprawdzenia zielony wynik nie znaczyłby nic: skaner mógłby po prostu
// nie działać. Podajemy mu kod z wywołaniem i oczekujemy trafienia.
$probka = '<?php $x = proc_open("ls", [], $p); shell_exec("id");';
check('wykrywa proc_open i shell_exec w kodzie',
    count(znajdzWywolania($probka, $szukane)) === 2);

$komentarz = '<?php /* proc_open() jest wyłączone */ // shell_exec() też
$ok = true;';
check('NIE zgłasza wystąpień w komentarzach',
    znajdzWywolania($komentarz, $szukane) === []);

$napis = '<?php $opis = "proc_open jest wyłączone"; $inny = \'shell_exec\';';
check('NIE zgłasza wystąpień w napisach', znajdzWywolania($napis, $szukane) === []);

$metoda = '<?php $obiekt->exec(); Klasa::system(); function popen() {}';
check('NIE myli metod i deklaracji z funkcjami wbudowanymi',
    znajdzWywolania($metoda, $szukane) === []);

echo "\n== warstwa CLI wciąż uruchamia procesy ==\n";

// Odwrotna strona kontraktu: gdyby `run_job.php` przestał uruchamiać Pythona,
// kolejka byłaby cicha i nikt by się nie dowiedział.
$worker = (string) file_get_contents($root . '/app/bin/run_job.php');
check('run_job.php istnieje i woła silnik',
    str_contains($worker, 'EngineRunner::inspect') && str_contains($worker, 'EngineRunner::build'));

/*
 * Granica jest FIZYCZNA, nie umowna: kod uruchamiający procesy leży poza
 * drzewem autoloadera (app/bin/), więc warstwa żądań nie ma jak go zawołać.
 * Sprawdzenie `PHP_SAPI` w czasie wykonania było słabsze — wystarczyło jedno
 * nowe wywołanie z kontrolera, żeby błąd wrócił.
 */
check('EngineRunner leży POZA app/src/',
    is_file($root . '/app/bin/EngineRunner.php') && !is_file($root . '/app/src/EngineRunner.php'));
// Tu i niżej sprawdzamy WYWOŁANIA, nie wystąpienia tekstu: oba pliki wyjaśniają
// w komentarzach, dlaczego `proc_open` jest problemem, więc `str_contains`
// pokazywałby zieleń w EngineRunnerze i czerwień w Engine niezależnie od kodu.
check('EngineRunner faktycznie uruchamia proces',
    znajdzWywolania((string) file_get_contents($root . '/app/bin/EngineRunner.php'), ['proc_open']) !== []);
check('CLI wciąga EngineRunner jawnie, bo autoloader go nie widzi',
    str_contains($worker, "require __DIR__ . '/EngineRunner.php'"));

$engine = (string) file_get_contents($root . '/app/src/Engine.php');
check('Engine w warstwie żądań czyta tylko artefakt',
    znajdzWywolania($engine, $szukane) === [] && str_contains($engine, 'cachePath'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
