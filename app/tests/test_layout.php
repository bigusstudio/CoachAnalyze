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

echo "\n== .htaccess: wymuszenie HTTPS jest WŁĄCZONE ==\n";

/**
 * POWÓD ISTNIENIA TEGO TESTU: regułę przekierowania niesie plik
 * `app/public/.htaccess`, a przejście 2 rsync w `deploy.sh` NADPISUJE go wersją
 * z repozytorium. Reguła włączona ręcznie na serwerze żyje więc dokładnie do
 * najbliższego wdrożenia.
 *
 * Skutek jej zniknięcia jest cichy i całkowity: panel wraca na HTTP, ciasteczko
 * sesji ma flagę `Secure` (z APP_URL) i przestaje wracać, token CSRF nie ma się
 * gdzie zapisać, a logowanie odbija każdą próbę. Tak wyglądała awaria zgłoszona
 * z produkcji (Firefox/Windows) i po niej powstał ten test.
 *
 * Sprawdzamy TREŚĆ PLIKU, nie odpowiedź serwera: test ma być statyczny i chodzić
 * w CI, gdzie nie ma ani Apache, ani produkcji. Za sprawdzenie odpowiedzi na
 * żywo odpowiada kontrola po wdrożeniu w `deploy.sh`.
 */
$htaccessPath = $root . '/app/public/.htaccess';
check('app/public/.htaccess istnieje', is_file($htaccessPath));

if (is_file($htaccessPath)) {
    // Wiersze bez komentarzy — komentarze w tym pliku CYTUJĄ reguły, żeby
    // wyjaśnić kolejność, więc szukanie po całej treści dałoby wynik fałszywie
    // zielony (dokładnie tak, jak przy zakomentowanej regule wcześniej).
    //
    // PODZIAŁ WIERSZY JAWNIE, NIE PRZEZ `\R`.
    // `\R` bez modyfikatora `u` dopasowuje bajt 0x85 (NEL), a to DRUGI BAJT
    // polskiego `ą` w UTF-8 (0xC4 0x85). `preg_split('/\R/')` rozcinał więc
    // komentarze w środku słowa — „wewnątrz" dawało wiersz „trz niego…", który
    // nie zaczyna się od `#` i trafiał do reguł czynnych jako śmieć. Wyszło to
    // na jaw dopiero wtedy, gdy fragment komentarza wniósł słowo `SAMEORIGIN`
    // do zbioru „reguł czynnych".
    $reguly = [];
    foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($htaccessPath)) ?: [] as $linia) {
        $linia = trim($linia);
        if ($linia !== '' && !str_starts_with($linia, '#')) {
            $reguly[] = $linia;
        }
    }
    $czynne = implode("\n", $reguly);

    check('warunek "RewriteCond %{HTTPS} !=on" NIE jest zakomentowany',
        preg_match('/^RewriteCond\s+%\{HTTPS\}\s*!=\s*on/m', $czynne) === 1,
        'bez tego warunku przekierowanie nie działa albo zapętla się');

    check('przekierowanie na https NIE jest zakomentowane',
        preg_match('/^RewriteRule\s+\^\s+https:\/\/%\{HTTP_HOST\}%\{REQUEST_URI\}/m', $czynne) === 1,
        'reguła w repozytorium musi być identyczna z tą działającą na serwerze');

    check('przekierowanie jest trwałe (R=301) i kończy przetwarzanie (L)',
        preg_match('/^RewriteRule\s+\^\s+https:.*\[[^\]]*R=301[^\]]*\]/m', $czynne) === 1
        && preg_match('/^RewriteRule\s+\^\s+https:.*\[[^\]]*L[^\]]*\]/m', $czynne) === 1);

    /*
     * KOLEJNOŚĆ: blokady [F,L] muszą stać PRZED przekierowaniem.
     *
     * `deploy.sh` sprawdza ochronę katalogów żądaniem po HTTP i oczekuje 403.
     * Gdyby przekierowanie łapało pierwsze, dostawałby 301 i kontrola ochrony —
     * jedyna, która pilnuje poufności plików klienta — przestałaby cokolwiek
     * znaczyć, meldując przy tym „OK".
     */
    $pozycjaBlokady = null;
    $pozycjaHttps = null;
    foreach ($reguly as $i => $linia) {
        if ($pozycjaBlokady === null && preg_match('/^RewriteRule.*\[F,L\]/', $linia) === 1) {
            $pozycjaBlokady = $i;
        }
        if ($pozycjaHttps === null && preg_match('/^RewriteCond\s+%\{HTTPS\}/', $linia) === 1) {
            $pozycjaHttps = $i;
        }
    }
    check('blokady [F,L] stoją PRZED przekierowaniem na HTTPS',
        $pozycjaBlokady !== null && $pozycjaHttps !== null && $pozycjaBlokady < $pozycjaHttps,
        'inaczej kontrola ochrony w deploy.sh dostaje 301 zamiast 403');

    echo "\n== .htaccess: jedno źródło nagłówków bezpieczeństwa ==\n";

    /*
     * `Header always set` w Apache zastępuje nagłówek wysłany przez PHP, więc
     * dublowanie tych ustawień w `bootstrap.php` dawało dwa źródła prawdy —
     * a wygrywało to niewidoczne z kodu. Kod deklarował `DENY`, produkcja
     * odpowiadała `SAMEORIGIN`. Rozjazd wyszedł dopiero przy `curl -I`.
     */
    check('X-Frame-Options ustawiony na DENY',
        preg_match('/^Header\s+always\s+set\s+X-Frame-Options\s+"DENY"/m', $czynne) === 1,
        'panel nie ma własnych ramek, SAMEORIGIN dawał tylko słabszą ochronę');

    check('Referrer-Policy ustawiony na no-referrer',
        preg_match('/^Header\s+always\s+set\s+Referrer-Policy\s+"no-referrer"/m', $czynne) === 1);

    /*
     * HSTS — sprawdzany na REGUŁACH CZYNNYCH, nie w całej treści pliku.
     *
     * Komentarz nad regułą wyjaśnia, czemu nie ma tam `includeSubDomains`
     * ani `preload`, więc szukanie po całym pliku dałoby wynik zielony także
     * dla reguły zakomentowanej — dokładnie ten błąd, przez który powstała
     * kontrola przekierowania HTTPS wyżej.
     */
    check('HSTS ustawiony z max-age=86400',
        preg_match('/^Header\s+always\s+set\s+Strict-Transport-Security\s+"max-age=86400"\s*$/m', $czynne) === 1,
        'nagłówka nie ma albo ma inną wartość niż uzgodniona doba');

    /*
     * BRAK `includeSubDomains` I `preload` JEST DECYZJĄ, NIE PRZEOCZENIEM.
     *
     * Certyfikat to wildcard `*.coachanalyze.pl`, a pod tą domeną żyją inne
     * subdomeny poza zasięgiem tego pliku. `includeSubDomains` narzuciłby im
     * HTTPS z przeglądarki odwiedzającego, a `preload` trafia do przeglądarek
     * na miesiące i nie cofa się wdrożeniem. Obie dyrektywy są łatwe do
     * dopisania „dla porządku" i kosztowne do odkręcenia — dlatego pilnuje
     * ich test, a nie pamięć.
     */
    check('HSTS bez includeSubDomains i bez preload',
        preg_match('/Strict-Transport-Security\s+"[^"]*(includeSubDomains|preload)/i', $czynne) !== 1,
        'wildcard obejmuje subdomeny, nad którymi ten plik nie ma władzy');

    $bootstrapKod = codeOnly((string) file_get_contents($root . '/app/src/bootstrap.php'));
    check('bootstrap.php NIE ustawia już X-Frame-Options',
        !str_contains($bootstrapKod, 'X-Frame-Options'),
        'ustawienie z PHP jest zastępowane przez Apache — mylące, nie działające');
    check('bootstrap.php NIE ustawia już Referrer-Policy dla całego panelu',
        !str_contains($bootstrapKod, 'Referrer-Policy'),
        'źródłem prawdy jest .htaccess');
}

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
    // Koniec wiersza wypisany jawnie, nie przez `\R` — powód wyżej, przy
    // czytaniu `.htaccess`: `\R` łapie bajt 0x85, czyli drugi bajt `ą`.
    $sklejony = (string) preg_replace('/\\\\\s*(?:\r\n|\n|\r)\s*/', ' ', $deploy);

    // Liczymy WYWOŁANIA, czyli linie zaczynające się od nazwy polecenia.
    // Samo `str_contains('rsync')` łapało też komentarze wspominające rsync —
    // a te potrafią wpaść w środek listy po sklejeniu linii z odwrotnym ukośnikiem.
    $wywolania = [];
    // Koniec wiersza jawnie, nie przez `\R` — patrz uwaga przy czytaniu `.htaccess`.
    foreach (preg_split('/\r\n|\n|\r/', $sklejony) ?: [] as $linia) {
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
    // Koniec wiersza jawnie, nie przez `\R` — patrz uwaga przy czytaniu `.htaccess`.
    foreach (preg_split('/\r\n|\n|\r/', $sklejony) ?: [] as $linia) {
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
    // Sprawdzamy ZACHOWANIE, nie konkretny zapis: odczyt `.env` został wyniesiony
    // do funkcji `wartosc_env`, wspólnej z odczytem DB_NAME przy zrzucie bazy.
    // Asercja przywiązana do jednej linijki `grep` zapalała się na czerwono przy
    // refaktorze, który niczego nie popsuł.
    check('czyta STORAGE_PATH z .env, a nie zakłada katalogu',
        preg_match('/STORAGE_PATH=\$\(wartosc_env STORAGE_PATH/', $polecenia) === 1
        || str_contains($polecenia, "grep -E '^STORAGE_PATH='"),
        'kontrola sprawdzała katalog zamiast wartości z konfiguracji');

    // Odczyt `.env` w powłoce musi zdejmować cudzysłowy tak samo jak parser PHP
    // (app/src/Config.php). Rozjazd ujawnia się wyłącznie na produkcji: hasło
    // w cudzysłowach przechodzi w aplikacji, a `mysqldump` się nie loguje.
    check('odczyt .env zdejmuje cudzysłowy jak parser PHP',
        str_contains($polecenia, 'wartosc_env')
        && preg_match('/wartosc="\$\{wartosc%\\\\"\}"/', $polecenia) === 1,
        'cytowane wartości trafiałyby do poleceń razem z cudzysłowami');
    check('sprawdza, że STORAGE_PATH leży wewnątrz katalogu domeny',
        str_contains($polecenia, 'WEB_REAL') && str_contains($polecenia, 'STORAGE_REAL'));
    check('zapisywalność przez PRÓBĘ ZAPISU, nie test dostępu',
        str_contains($polecenia, '.probe') && !str_contains($polecenia, '-w "$WEB/storage"'),
        'testy dostępu kłamią na ścieżkach spoza open_basedir');
    check('sprawdza zapisywalność LOG_PATH', str_contains($polecenia, 'LOG_PATH'));

    /*
     * Reguła HTTPS mieszka w pliku, który rsync nadpisuje, więc samo „jest
     * w repozytorium" nie wystarcza — wdrożenie musi sprawdzić, że po
     * synchronizacji faktycznie działa. Test statyczny pilnuje treści pliku,
     * ta kontrola pilnuje odpowiedzi serwera; jedno bez drugiego zostawia lukę.
     */
    check('kontroluje przekierowanie HTTP -> HTTPS po wdrożeniu',
        str_contains($polecenia, 'redirect_url') && str_contains($polecenia, '"301"'),
        'bez tego wdrożenie może po cichu wyłączyć HTTPS i zameldować „Gotowe"');

    // Po usunięciu nagłówków z bootstrap.php nie ma już zapasowego ustawienia
    // po stronie aplikacji: literówka w .htaccess zdejmuje ochronę bez śladu.
    check('kontroluje nagłówki bezpieczeństwa po wdrożeniu',
        str_contains($polecenia, 'X-Frame-Options=DENY'),
        'jedyne źródło nagłówków musi być sprawdzane na żywo');

    // HSTS ma być sprawdzany NA ŻYWO razem z resztą: reguła w repozytorium
    // niczego nie gwarantuje, jeśli hosting nie ma `mod_headers`.
    check('kontroluje HSTS po wdrożeniu',
        str_contains($polecenia, 'Strict-Transport-Security=max-age=86400'),
        'obecność reguły w pliku to nie to samo co nagłówek w odpowiedzi');

    /*
     * Buforowanie zasobów sprawdzalne WYŁĄCZNIE na żywo: wbudowany serwer PHP
     * w testach integracyjnych nie czyta `.htaccess`, więc `test_assety.php`
     * pilnuje treści pliku, a odpowiedzi serwera — dopiero to.
     *
     * Sprawdzamy OBIE strony reguły. Sama obecność `immutable` w skrypcie nie
     * wystarcza: bez zapytania o adres BEZ wersji przeoczylibyśmy jedyny
     * groźny przypadek, czyli przypięcie pliku na rok pod adresem, który
     * nigdy się nie zmieni.
     */
    check('kontroluje buforowanie assetów po wdrożeniu',
        str_contains($polecenia, 'immutable') && str_contains($polecenia, 'must-revalidate'),
        'deploy ma pytać o adres z wersją i bez wersji');
    check('kontrola cache pyta o adres z wersją i bez',
        str_contains($polecenia, '/assets/app.css?v=')
        && preg_match('#"https://[^"]*/assets/app\.css"#', $polecenia) === 1,
        'jedno z dwóch zapytań nie istnieje');
    echo "\n== deploy.sh: zrzut bazy ==\n";

    /*
     * USTERKA Z PRODUKCJI: `mysqldump --single-transaction >` bez nazwy bazy.
     * `~/.my.cnf` przechowuje dane logowania, a nie wybór bazy — wpis `database=`
     * został z niego usunięty, bo psuł samo `mysqldump`. Skrypt meldował
     * „pominięto — sprawdź ~/.my.cnf", choć to samo polecenie z nazwą bazy
     * działało ręcznie bez zarzutu.
     */
    check('zrzut podaje nazwę bazy z DB_NAME',
        str_contains($polecenia, 'DB_NAME=$(wartosc_env DB_NAME)')
        && str_contains($polecenia, '"$DB_NAME"'),
        'mysqldump bez nazwy bazy kończy się „No database selected"');

    check('brak DB_NAME zatrzymuje wdrożenie',
        preg_match('/-z "\$DB_NAME".{0,400}exit 1/s', $polecenia) === 1);

    // Błąd `mysqldump` szedł do /dev/null, więc prawdziwa przyczyna przepadała,
    // a komunikat kierował diagnostykę na `~/.my.cnf` niezależnie od powodu.
    // Wyłącznie linie będące WYWOŁANIEM polecenia. Samo wystąpienie słowa
    // „mysqldump" łapie też komentarze, które wyjaśniają, czemu nie wyciszamy
    // błędu — i test zapalał się na własnym uzasadnieniu.
    $liniaZrzutu = '';
    foreach (explode("\n", $polecenia) as $l) {
        if (preg_match('/^(if\s+)?mysqldump\b/', trim($l)) === 1) {
            $liniaZrzutu .= $l . ' ';
        }
    }
    check('błąd mysqldump NIE jest wyciszany do /dev/null',
        !str_contains($liniaZrzutu, '/dev/null'),
        'wyciszony błąd gubi przyczynę i myli diagnostykę');

    check('powód awarii zrzutu trafia na wyjście',
        str_contains($polecenia, '.blad" >&2'));

    // Nieudany zrzut zostawiał urwany plik `pre-*.sql`, nieodróżnialny w katalogu
    // od prawdziwej kopii — odkrywany dopiero przy próbie odtworzenia bazy.
    check('zrzut powstaje w pliku tymczasowym i jest przenoszony po powodzeniu',
        str_contains($polecenia, 'mktemp') && str_contains($polecenia, 'mv -f "$ZRZUT_TMP"'),
        'zapis wprost pod nazwą docelową obcina plik przed uruchomieniem mysqldump');

    check('nieudany zrzut przerywa wdrożenie',
        preg_match('/nie udało się zrzucić bazy.{0,400}exit 1/s', $polecenia) === 1,
        'wdrożenie bez kopii bazy to zmiana bez odwrotu');

    check('nieudany zrzut nie zostawia pliku udającego kopię',
        str_contains($polecenia, 'trap') && str_contains($polecenia, '$ZRZUT_TMP'));

    check(
        'nieudana kontrola przerywa wdrożenie kodem błędu',
        preg_match('/exit\s+1/', $polecenia) === 1,
        'ostrzeżenie, którego nikt nie czyta, nie jest kontrolą'
    );
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
