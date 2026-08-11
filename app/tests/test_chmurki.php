<?php
declare(strict_types=1);

/**
 * Chmurki powiadomień: ograniczenia skryptu i wariant bez skryptu.
 *
 * ODSTĘPSTWO OD ZASADY „ZERO SKRYPTÓW" jest zatwierdzone i ograniczone do
 * powiadomień. Ten test pilnuje granic tego odstępstwa — bo odstępstwo bez
 * granicy przestaje być odstępstwem i staje się nową zasadą.
 *
 * Uruchomienie:  php app/tests/test_chmurki.php
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

/** Kod PHP bez komentarzy — komentarze w tym projekcie wymieniają zakazane konstrukcje. */
function bezKomentarzy(string $kod): string
{
    $out = '';
    foreach (token_get_all($kod) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $out .= is_array($token) ? $token[1] : $token;
    }
    return $out;
}

$layout = (string) file_get_contents($root . '/app/src/Views/layout.php');
$skrypt = $root . '/app/public/assets/powiadomienia.js';
$js     = is_file($skrypt) ? (string) file_get_contents($skrypt) : '';

echo "== skrypt: jeden plik, bez zależności ==\n";

check('skrypt istnieje', $js !== '', $skrypt);

// Cały panel ma mieć DOKŁADNIE JEDEN skrypt. Drugi znaczy, że odstępstwo się
// rozlało — a wtedy „panel bez JavaScriptu" przestaje być prawdą.
preg_match_all('/<script\b[^>]*>/i', bezKomentarzy($layout), $znaczniki);
check('layout ładuje dokładnie jeden skrypt', count($znaczniki[0]) === 1,
    count($znaczniki[0]) . ' znaczników <script>');

$znacznik = $znaczniki[0][0] ?? '';
check('skrypt ma atrybut defer', str_contains($znacznik, 'defer'), $znacznik);
check('skrypt jest własny, nie z obcego serwera',
    str_contains($znacznik, '/assets/powiadomienia.js') && !str_contains($znacznik, '//'),
    $znacznik);
check('skrypt stoi na końcu dokumentu',
    strpos(bezKomentarzy($layout), '<script') > strpos(bezKomentarzy($layout), '</footer>'));

// Brak `import`, `require` i adresów zewnętrznych — jeden plik ma być całością.
check('skrypt nie ciągnie zależności',
    !preg_match('/\b(import\s|require\s*\(|from\s+[\'"])/', $js));
check('skrypt nie sięga poza własny serwer',
    !preg_match('#[\'"]https?://#', $js));

echo "\n== skrypt nie wstawia HTML-a ==\n";

/*
 * Punkt końcowy zwraca DANE, a skrypt buduje z nich elementy. Tytuł
 * powiadomienia zawiera nazwy klubów, czyli tekst wpisany przez użytkownika —
 * wstawiony jako HTML byłby kodem do wykonania.
 *
 * Sprawdzamy kod po usunięciu komentarzy, bo komentarz w skrypcie tłumaczy
 * właśnie, dlaczego `innerHTML` się tu nie pojawia.
 */
$jsBezKomentarzy = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js) ?? $js;

foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval('] as $zakazana) {
    check("skrypt nie używa {$zakazana}", !str_contains($jsBezKomentarzy, $zakazana));
}

check('skrypt buduje treść przez textContent', str_contains($jsBezKomentarzy, 'textContent'));
check('skrypt tworzy elementy przez createElement', str_contains($jsBezKomentarzy, 'createElement'));

echo "\n== odpytywanie jest miarkowane ==\n";

// Otwarta karta bez hamulców to kilkanaście tysięcy żądań na dobę.
check('skrypt zatrzymuje się przy karcie w tle',
    str_contains($jsBezKomentarzy, 'visibilitychange') && str_contains($jsBezKomentarzy, 'document.hidden'));
check('skrypt wydłuża odstęp przy braku nowych',
    str_contains($jsBezKomentarzy, 'ODSTEP_MAX') && str_contains($jsBezKomentarzy, 'MNOZNIK'));
check('skrypt przestaje pytać po odpowiedzi 404',
    preg_match('/status\s*===\s*404/', $jsBezKomentarzy) === 1);

echo "\n== panel działa BEZ skryptu ==\n";

/*
 * Sedno wymagania: skrypt tylko przyspiesza. Chmurki muszą być w HTML-u
 * wysłanym przez serwer, a zamknięcie musi działać zwykłym formularzem.
 */
check('layout renderuje chmurki po stronie serwera',
    str_contains($layout, 'unreadForToasts'),
    'bez tego przy wyłączonym JS nie widać nic');

check('chmurka bez skryptu ma formularz zamknięcia',
    str_contains($layout, 'method="post"')
    && str_contains($layout, '/odczytane'));

check('formularz zamknięcia niesie token CSRF',
    preg_match('#/odczytane".*?name="csrf"#s', $layout) === 1);

check('licznik w nagłówku nie zależy od skryptu',
    str_contains($layout, 'unreadCount'));

echo "\n== punkt końcowy ==\n";

$router = (string) file_get_contents($root . '/app/public/index.php');

/*
 * Trasa MUSI być obsłużona przed `requireLogin()`, które przekierowuje na
 * `/login`. Bez tego skrypt dostawałby stronę logowania zamiast JSON-a,
 * a brak sesji dawałby 302 zamiast 404.
 */
$pozycjaTrasy = strpos($router, "'/powiadomienia/nowe'");
$pozycjaAuth  = strpos($router, 'Auth::requireLogin()');
check('trasa punktu końcowego stoi przed middleware sesji',
    $pozycjaTrasy !== false && $pozycjaAuth !== false && $pozycjaTrasy < $pozycjaAuth);

check('brak sesji daje 404, nie 401 ani 302',
    preg_match('#/powiadomienia/nowe.{0,400}http_response_code\(404\)#s', $router) === 1,
    '401 na istniejącej trasie potwierdza, że trasa istnieje');

check('punkt końcowy odpowiada JSON-em',
    str_contains($router, "Content-Type: application/json"));

check('odpowiedź nie jest buforowana',
    preg_match('#function notificationsFeed.{0,600}Cache-Control: no-store#s', $router) === 1);

check('zamykanie chmurki wymaga tokenu CSRF',
    preg_match('#odczytane\$\#.{0,200}requireCsrf\(\)#s', $router) === 1);

echo "\n== barwy wyłącznie przez zmienne ==\n";

$css = (string) file_get_contents($root . '/app/public/assets/app.css');
$sekcja = strstr($css, '/* --- chmurki powiadomień');

check('sekcja chmurek istnieje w arkuszu', $sekcja !== false);
check('trzy odmiany mają własne style',
    $sekcja !== false
    && str_contains($sekcja, '.chmurka--ready')
    && str_contains($sekcja, '.chmurka--pending')
    && str_contains($sekcja, '.chmurka--failed'));

// Ani jednej wartości szesnastkowej — barwy wyłącznie ze zmiennych motywu.
$heksy = [];
if ($sekcja !== false) {
    preg_match_all('/#[0-9A-Fa-f]{3,8}\b/', $sekcja, $trafienia);
    $heksy = $trafienia[0];
}
check('chmurki nie zawierają żadnej barwy wpisanej wprost', $heksy === [],
    implode(', ', $heksy));

check('chmurki biorą barwy ze zmiennych motywu',
    $sekcja !== false && substr_count($sekcja, 'var(--') >= 6);

check('animacja ustępuje przy prefers-reduced-motion',
    $sekcja !== false && str_contains($sekcja, 'prefers-reduced-motion'));

echo "\n== raport nadal bez ani jednego skryptu ==\n";

/*
 * Odstępstwo dotyczy panelu, nie raportu. Szablon raportu jest samowystarczalny
 * i to jest cecha, nie brak (CLAUDE.md §8) — sprawdzamy, że nie rozlało się tam.
 */
$szablon = $root . '/engine/coachanalyze/templates/dashboard_template.html';
if (is_file($szablon)) {
    $tresc = (string) file_get_contents($szablon);
    check('szablon raportu nie ładuje skryptu z zewnątrz',
        !preg_match('#<script[^>]+src=#i', $tresc));
} else {
    echo "  --   brak szablonu raportu w tym drzewie, pomijam\n";
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
