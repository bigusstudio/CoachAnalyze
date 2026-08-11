<?php
declare(strict_types=1);

/**
 * Testy układu katalogów.
 *
 * POWÓD ISTNIENIA: układ w repozytorium NIE jest układem produkcyjnym.
 * `deploy.sh` przenosi zawartość `app/public/` do katalogu domeny, a `app/`
 * ląduje w jego podkatalogu. Przez to `app/public/index.php` jest jedynym
 * plikiem, który w produkcji leży PIĘTRO WYŻEJ względem kodu aplikacji.
 *
 *   repozytorium                    produkcja
 *   app/public/index.php    ->      {domena}/index.php
 *   app/src/bootstrap.php   ->      {domena}/app/src/bootstrap.php
 *
 * `dirname(__DIR__)` wskazywał wtedy `~/public_html` — katalog spoza
 * `open_basedir`. Błąd wrócił dwa razy, więc jest tu przypięty testem.
 *
 * Uruchomienie:  php app/tests/test_layout.php
 */

$root = dirname(__DIR__, 2);
$indexPath = $root . '/app/public/index.php';

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
 * Kod PHP bez komentarzy.
 *
 * Wszystkie sprawdzenia treści plików przepuszczamy przez tę funkcję. Komentarze
 * MUSZĄ móc wymieniać z nazwy konstrukcje, których zakazujemy — inaczej za rok
 * nikt nie będzie wiedział, czego dotyczy test i dlaczego powstał.
 */
function codeOnly(string $source): string
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

/**
 * Buduje drzewo katalogów z prawdziwym `index.php` i atrapą bootstrapu,
 * po czym uruchamia index. Atrapa wypisuje CA_ROOT i kończy proces, więc
 * sprawdzamy samo ustalanie ścieżek — bez bazy, sesji i routingu.
 */
function runLayout(string $indexPath, string $indexRelative, ?string $bootstrapStub = null): array
{
    $tmp = sys_get_temp_dir() . '/ca_layout_' . bin2hex(random_bytes(6));
    $indexTarget = $tmp . '/' . $indexRelative;

    @mkdir(dirname($indexTarget), 0770, true);
    @mkdir($tmp . '/app/src', 0770, true);

    copy($indexPath, $indexTarget);

    if ($bootstrapStub === null) {
        file_put_contents($tmp . '/app/src/bootstrap.php', "<?php echo 'CA_ROOT=' . CA_ROOT; exit;\n");
    } else {
        // Wariant z prawdziwym Config — sprawdzamy zachowanie przy braku .env.
        copy(dirname($indexPath, 2) . '/src/Config.php', $tmp . '/app/src/Config.php');
        file_put_contents(
            $tmp . '/app/src/bootstrap.php',
            "<?php ini_set('display_errors','1'); require __DIR__ . '/Config.php';\n" . $bootstrapStub
        );
    }

    // Wyłącznie stdout — czyli to, co dostałaby przeglądarka. Log błędów idzie
    // na stderr i nie ma prawa mieszać się z odpowiedzią.
    $output = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($indexTarget) . ' 2>/dev/null'
    );

    // macOS: sys_get_temp_dir() zwraca /var/..., a __DIR__ rozwija się do
    // /private/var/... Porównujemy ścieżki po rozwinięciu, nie napisy.
    $realTmp = (string) realpath($tmp);

    $rm = static function (string $dir) use (&$rm): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $rm($path) : @unlink($path);
        }
        @rmdir($dir);
    };
    $rm($tmp);

    return ['tmp' => $realTmp, 'output' => trim($output)];
}

echo "== treść app/public/index.php ==\n";

$source = (string) file_get_contents($indexPath);

/**
 * Sprawdzamy KOD, nie komentarze. Wyjaśnienie pułapki musi móc wymienić
 * `dirname(__DIR__)` z nazwy — inaczej za rok nikt nie będzie wiedział,
 * czego dotyczy ten test i dlaczego istnieje.
 */
$kod = codeOnly($source);

// Sedno sprawy: skok „o jedno piętro w górę" jest poprawny w repozytorium
// i błędny w produkcji. Właśnie dlatego nie wolno go tu użyć.
check(
    'brak dirname(__DIR__) w kodzie — układ produkcyjny jest inny niż w repozytorium',
    !str_contains($kod, 'dirname(__DIR__)'),
    'znaleziono dirname(__DIR__); ścieżki licz od CA_ROOT'
);

check('stała CA_ROOT jest definiowana', str_contains($source, "define('CA_ROOT'"));

// Każde wciągnięcie pliku musi iść przez CA_ROOT — inaczej wraca ta sama klasa błędu.
preg_match_all('/^\s*(require|include)(_once)?\s+([^;]+);/m', $source, $matches, PREG_SET_ORDER);
$zleWciagniecia = [];
foreach ($matches as $match) {
    if (!str_contains($match[3], 'CA_ROOT')) {
        $zleWciagniecia[] = trim($match[3]);
    }
}
check(
    'każde require/include liczone od CA_ROOT',
    $zleWciagniecia === [],
    implode(' | ', $zleWciagniecia)
);
check('są w ogóle jakieś require do sprawdzenia', $matches !== []);

echo "\n== uruchomienie w obu układach ==\n";

// Produkcja: index.php w katalogu domeny, kod aplikacji w podkatalogu app/.
$prod = runLayout($indexPath, 'index.php');
check(
    'układ produkcyjny: bootstrap odnaleziony',
    str_contains($prod['output'], 'CA_ROOT='),
    $prod['output']
);
check(
    'układ produkcyjny: CA_ROOT to katalog domeny (czyli __DIR__)',
    str_contains($prod['output'], 'CA_ROOT=' . $prod['tmp']),
    $prod['output']
);

// Repozytorium: index.php w app/public/, kod obok w app/src/.
$repo = runLayout($indexPath, 'app/public/index.php');
check(
    'układ repozytorium: bootstrap odnaleziony',
    str_contains($repo['output'], 'CA_ROOT='),
    $repo['output']
);
check(
    'układ repozytorium: CA_ROOT to korzeń repozytorium',
    str_contains($repo['output'], 'CA_ROOT=' . $repo['tmp']),
    $repo['output']
);

echo "\n== brak kodu aplikacji ==\n";

$osierocony = sys_get_temp_dir() . '/ca_layout_' . bin2hex(random_bytes(6));
@mkdir($osierocony, 0770, true);
copy($indexPath, $osierocony . '/index.php');
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($osierocony . '/index.php');
$body = trim((string) shell_exec($cmd . ' 2>/dev/null'));          // to, co widzi przeglądarka
$log  = trim((string) shell_exec($cmd . ' 2>&1 1>/dev/null'));     // to, co idzie do logu
@unlink($osierocony . '/index.php');
@rmdir($osierocony);

check(
    'brak app/ kończy się czytelnym komunikatem, nie błędem otwarcia pliku',
    str_contains($body, 'nieprawidłowo wdrożona'),
    $body
);
check(
    'odpowiedź dla przeglądarki nie zdradza ścieżek serwera',
    !str_contains($body, 'ca_layout') && !str_contains($body, '/tmp'),
    $body
);
check(
    'powód trafia do logu, gdzie jest przydatny',
    str_contains($log, 'bootstrap.php'),
    $log
);

echo "\n== pozostałe pliki wejściowe ==\n";

// `app/bin/*` i `app/src/*` zostają WEWNĄTRZ app/ w obu układach, więc skok
// o jedno piętro jest tam poprawny. Pilnujemy tylko, żeby faktycznie tam leżały.
foreach (['app/bin/run_job.php', 'app/bin/create_user.php'] as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        continue;
    }
    $body = (string) file_get_contents($path);
    check(
        "{$relative}: bootstrap wciągany z wnętrza app/",
        str_contains($body, "dirname(__DIR__) . '/src/bootstrap.php'"),
        'ten plik nie zmienia położenia przy wdrożeniu i ma zostać jak jest'
    );
}

echo "\n== open_basedir: nie pytamy o istnienie, tylko próbujemy ==\n";

/**
 * Na lh.pl `is_file()` na ścieżce spoza `open_basedir` zwraca false także wtedy,
 * gdy zasób jest w pełni osiągalny: sprawdzenie pliku podlega ograniczeniu,
 * a otwarcie zasobu już nie. Zweryfikowane przez FPM — `$r->connect()` na gnieździe
 * Redis przechodzi, a `is_file()` na tej samej ścieżce mówi „nie ma".
 *
 * Dlatego w warstwie wejścia nie ma wstępnych sprawdzeń istnienia. Próbujemy
 * wykonać operację i obsługujemy niepowodzenie.
 */
$configSource = codeOnly((string) file_get_contents($root . '/app/src/Config.php'));
$redisSource  = codeOnly((string) file_get_contents($root . '/app/src/RedisClient.php'));

check(
    'RedisClient łączy się bez wstępnego sprawdzania istnienia gniazda',
    !preg_match('/(is_file|file_exists|is_readable)\s*\(/', $redisSource),
    'na tym hostingu takie sprawdzenie zwraca false mimo działającego gniazda'
);
check(
    'RedisClient obsługuje nieudane połączenie (fail closed po connect)',
    str_contains($redisSource, 'stream_socket_client') && str_contains($redisSource, 'RuntimeException')
);
check(
    'Config nie sprawdza istnienia .env, tylko próbuje odczytać',
    !preg_match('/(is_file|file_exists|is_readable)\s*\(/', $configSource),
    'sprawdzenie istnienia kłamie dla ścieżek spoza open_basedir'
);
check(
    'Config nie przeszukuje drzewa w górę w poszukiwaniu .env',
    !preg_match('/for\s*\(.*\)\s*\{[^}]*dirname\s*\(\s*\$/s', $configSource),
    'każdy krok powyżej open_basedir to ostrzeżenie PHP na stronie'
);

echo "\n== ostrzeżenia PHP nie trafiają do przeglądarki ==\n";

/**
 * Uruchamiamy prawdziwy `index.php` w układzie produkcyjnym BEZ pliku `.env`
 * i ze ścieżką konfiguracji wskazującą katalog, którego nie ma. Odpowiedź nie
 * może zawierać ani jednego ostrzeżenia PHP — powód należy do logu.
 */
// Bez znacznika otwierającego — atrapa jest doklejana do gotowego nagłówka.
$brakEnv = runLayout($indexPath, 'index.php', "CoachAnalyze\\Config::load();\necho 'BODY_OK';\nexit;\n");

check(
    'odpowiedź nie zawiera ostrzeżeń PHP',
    !preg_match('/\b(Warning|Notice|Deprecated|Fatal error)\b/', $brakEnv['output']),
    $brakEnv['output']
);
check(
    'odpowiedź nie zawiera ścieżek serwera',
    !str_contains($brakEnv['output'], '/app/src/'),
    $brakEnv['output']
);

echo "\n== bootstrap: kolejność wyciszania błędów ==\n";

$bootstrapSource = codeOnly((string) file_get_contents($root . '/app/src/bootstrap.php'));
$pozycjaWyciszenia = strpos($bootstrapSource, "ini_set('display_errors', '0')");
$pozycjaLoad = strpos($bootstrapSource, 'Config::load()');

check(
    'display_errors wyłączane PRZED wczytaniem konfiguracji',
    $pozycjaWyciszenia !== false && $pozycjaLoad !== false && $pozycjaWyciszenia < $pozycjaLoad,
    'ostrzeżenia z wczytywania .env trafiłyby do odpowiedzi'
);

echo "\n== deploy.sh: wykluczenia przy rsync --delete ==\n";

/**
 * Drugi rsync ma źródło `app/public/`, a cel w katalogu domeny, gdzie leżą już
 * `app/`, `.env` i `storage/`. Bez `--exclude` `--delete` uznaje je za nadmiarowe
 * i kasuje — razem z całym kodem aplikacji wgranym przejście wcześniej.
 *
 * Naprawione w 01b1dc7, cofnięte przy niepowiązanej zmianie w 73eecb7.
 * Dlatego pilnuje tego test, a nie pamięć.
 */
$deployPath = $root . '/deploy/deploy.sh';
check('deploy.sh istnieje', is_file($deployPath));

if (is_file($deployPath)) {
    $deploy = (string) file_get_contents($deployPath);

    // Sklejamy polecenia rozbite na kilka linii odwrotnym ukośnikiem.
    $sklejony = (string) preg_replace('/\\\\\s*\R\s*/', ' ', $deploy);

    // Liczymy WYWOŁANIA, czyli linie zaczynające się od nazwy polecenia.
    // Samo `str_contains('rsync')` łapało też komentarze wspominające rsync —
    // a te potrafią wpaść w środek listy po sklejeniu linii z odwrotnym ukośnikiem.
    $wywolania = [];
    foreach (preg_split('/\R/', $sklejony) ?: [] as $linia) {
        $linia = trim($linia);
        if (str_starts_with($linia, 'rsync ')) {
            $wywolania[] = $linia;
        }
    }

    check('są dokładnie dwa wywołania rsync', count($wywolania) === 2,
        'znaleziono ' . count($wywolania));

    foreach ($wywolania as $i => $linia) {
        $numer = $i + 1;

        if (!str_contains($linia, '--delete')) {
            check("rsync #{$numer}: bez --delete, wykluczenia zbędne", true);
            continue;
        }

        foreach (['.env', 'storage/'] as $wykluczenie) {
            check(
                "rsync #{$numer}: wyklucza {$wykluczenie}",
                str_contains($linia, "--exclude='{$wykluczenie}'"),
                $linia
            );
        }

        // `app/` chronimy tylko w przejściu, którego celem jest katalog domeny.
        // W pierwszym przejściu to katalog docelowy, więc wykluczenie byłoby błędem.
        if (str_contains($linia, 'app/public/')) {
            check(
                "rsync #{$numer}: wyklucza app/ — inaczej kasuje kod aplikacji",
                str_contains($linia, "--exclude='app/'"),
                $linia
            );
        }
    }

    echo "\n== deploy.sh: żadnych dowiązań poza open_basedir ==\n";

    /**
     * `open_basedir` sprawdza ścieżkę po rozwinięciu dowiązania, więc symlink
     * do `~/CoachAnalyze/shared/` jest dla FPM niewidoczny — także przy zapisie.
     * `.env` ma być kopiowany, a `storage/` ma być prawdziwym katalogiem.
     */
    $liniePolecen = [];
    foreach (preg_split('/\R/', $sklejony) ?: [] as $linia) {
        $linia = trim($linia);
        if ($linia !== '' && !str_starts_with($linia, '#')) {
            $liniePolecen[] = $linia;
        }
    }
    $polecenia = implode("\n", $liniePolecen);

    check(
        '.env nie jest dowiązywany (ln -s), tylko kopiowany',
        !preg_match('/ln\s+-s\w*\s+\S*\.env/', $polecenia) && preg_match('/cp\s+\S*\s*\S*\.env/', $polecenia) === 1,
        'dowiązanie do shared/.env jest dla FPM nieczytelne'
    );
    check(
        'storage/ nie jest dowiązywane, tylko tworzone jako katalog',
        !preg_match('/ln\s+-s\w*\s+\S*storage/', $polecenia) && str_contains($polecenia, 'mkdir -p "$WEB/storage'),
        'przez dowiązanie FPM nie zapisze uploadu'
    );

    echo "\n== deploy.sh: kontrola po wdrożeniu ==\n";

    check('sprawdza, że .env NIE jest dowiązaniem', str_contains($polecenia, '-L "$WEB/.env"'));

    /**
     * Kontrola musi dotyczyć ŚCIEŻKI Z KONFIGURACJI, nie katalogu, który skrypt
     * sam przed chwilą utworzył.
     *
     * Poprzednia wersja sprawdzała `$WEB/storage` i przechodziła zawsze, podczas
     * gdy aplikacja czytała STORAGE_PATH z `.env` i szła do `shared/storage` —
     * poza open_basedir. Upload padał, a wdrożenie meldowało „OK".
     */
    check('czyta STORAGE_PATH z .env, a nie zakłada katalogu',
        str_contains($polecenia, "grep -E '^STORAGE_PATH='"),
        'kontrola sprawdzała katalog zamiast wartości z konfiguracji');
    check('sprawdza, że STORAGE_PATH leży wewnątrz katalogu domeny',
        str_contains($polecenia, 'WEB_REAL') && str_contains($polecenia, 'STORAGE_REAL'));
    check('zapisywalność przez PRÓBĘ ZAPISU, nie test dostępu',
        str_contains($polecenia, '.probe') && !str_contains($polecenia, '-w "$WEB/storage"'),
        'testy dostępu kłamią na ścieżkach spoza open_basedir');
    check('sprawdza zapisywalność LOG_PATH', str_contains($polecenia, 'LOG_PATH'));
    check(
        'nieudana kontrola przerywa wdrożenie kodem błędu',
        preg_match('/exit\s+1/', $polecenia) === 1,
        'ostrzeżenie, którego nikt nie czyta, nie jest kontrolą'
    );
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
