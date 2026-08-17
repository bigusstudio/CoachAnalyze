<?php
declare(strict_types=1);

/**
 * Skaner bramek ról — ta sama klasa zabezpieczenia co test_disabled_functions.
 *
 * USTERKA, KTÓRA GO ZRODZIŁA: POST /import/{id}/mapowanie i POST
 * /kluby/{id}/mapowania nie miały `requireCan()`, więc viewer mógł zapisać
 * profil mapowań — a profil zmienia liczby w raportach tak samo jak
 * generowanie, które bramkę miało od początku. Ukrycie przycisku w widoku
 * nie jest kontrolą dostępu: adres da się wpisać, POST wysłać z konsoli.
 *
 * Zasada odtąd egzekwowana mechanicznie: KAŻDA trasa POST w przełączniku tras
 * ma `requireCan()` ALBO stoi na jawnej białej liście czynności własnego konta
 * — z wpisanym powodem. Nowa trasa bez bramki i bez wpisu = czerwony test.
 *
 * Zakres: przełącznik tras w index.php (wszystko za requireLogin()). Trasy
 * przedlogowe (/login, reset hasła) są POZA przełącznikiem i poza rolami —
 * pilnują ich limity prób i CSRF, nie bramki ról.
 *
 * Uruchomienie:  php test_bramki_rol.php
 */

$root = dirname(__DIR__, 2);

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

/*
 * Biała lista: czynności WŁASNEGO konta i sesji. Dostępne dla każdej roli,
 * bo dotyczą wyłącznie tego, kto je wykonuje. Każdy wpis ma powód —
 * wpis bez powodu przy przeglądzie wygląda jak przemycony.
 */
$wyjatki = [
    '/motyw'                      => 'przełącznik motywu — preferencja przeglądarki, nie stan danych',
    '/logout'                     => 'wylogowanie własnej sesji',
    '/konto/powiadomienia'        => 'preferencje mailowe własnego konta',
    '/konto/urzadzenie/…/wyloguj' => 'wylogowanie własnego zapamiętanego urządzenia',
    '/konto/wyloguj-wszedzie'     => 'odzyskanie kontroli nad własnym kontem',
    '/konto/haslo'                => 'zmiana własnego hasła (wymaga starego)',
    '/powiadomienia/…/odczytane'  => 'zamknięcie własnej chmurki; filtr konta w SQL',
];

$zrodlo = (string) file_get_contents($root . '/app/public/index.php');
$linie = explode("\n", $zrodlo);

// ---------------------------------------------------------------- zbiór tras POST
echo "== każda trasa POST ma bramkę roli albo jawny wyjątek ==\n";

$przypadki = [];   // trasa => treść bloku case
$biezacaTrasa = null;
$blok = [];

foreach ($linie as $linia) {
    if (preg_match('/^\s*case\s+.*\$method === \'POST\'/', $linia) === 1) {
        // Trasa z porównania wprost ('/kluby') albo z wzorca (#^/kluby/(\d+)$#).
        // Z wzorca bierzemy PEŁNĄ ścieżkę z „…" za parametr — skrócenie do
        // prefiksu zlewało /kluby/{id}, /kluby/{id}/usun i /kluby/{id}/mapowania
        // w jeden wpis, a wtedy bramka jednej trasy ukrywa brak w drugiej.
        if (preg_match("#\\\$path === '([^']+)'#", $linia, $m) === 1) {
            $biezacaTrasa = $m[1];
        } elseif (preg_match('%#\^([^#]+)\$?#%', $linia, $m) === 1) {
            $biezacaTrasa = str_replace(['(\d+)', '$'], ['…', ''], $m[1]);
        } else {
            $biezacaTrasa = trim($linia);
        }
        if (isset($przypadki[$biezacaTrasa])) {
            // Dwie trasy POST o tym samym kluczu = parser coś zlewa. Głośno.
            $biezacaTrasa .= ' (duplikat!)';
        }
        $blok = [];
        continue;
    }

    if ($biezacaTrasa !== null) {
        $blok[] = $linia;
        // Koniec bloku: pierwszy `break;`. Bramki stoją na początku bloku,
        // więc wcześniejszy `break;` w gałęzi warunkowej niczego nie ukrywa.
        if (preg_match('/^\s*break;/', $linia) === 1) {
            $przypadki[$biezacaTrasa] = implode("\n", $blok);
            $biezacaTrasa = null;
        }
    }
}

check('parser widzi trasy POST (przynajmniej 20)', count($przypadki) >= 20,
    'znaleziono ' . count($przypadki));

$uzyte = [];
foreach ($przypadki as $trasa => $tresc) {
    $maBramke = str_contains($tresc, 'requireCan(');

    if (isset($wyjatki[$trasa])) {
        $uzyte[$trasa] = true;
        check("wyjątek: {$trasa}", true);
        continue;
    }

    check("bramka roli: {$trasa}", $maBramke,
        'trasa POST bez requireCan() i bez wpisu na białej liście');
}

// Wpis białej listy, którego nic nie używa, to martwy wyjątek — do usunięcia,
// zanim ktoś schowa pod nim nową trasę.
foreach (array_keys($wyjatki) as $wzor) {
    check("wpis białej listy używany: {$wzor}", isset($uzyte[$wzor]));
}

// ---------------------------------------------------------------- CSRF przy okazji
echo "\n== każda trasa POST w przełączniku ma token CSRF ==\n";

foreach ($przypadki as $trasa => $tresc) {
    check("CSRF: {$trasa}", str_contains($tresc, 'requireCsrf()'));
}

// ---------------------------------------------------------------- nazwy uprawnień
echo "\n== bramki używają istniejących uprawnień ==\n";

/*
 * `requireCan($user, 'literowka')` nie wybucha — zwraca 404 KAŻDEMU.
 * Funkcja znikałaby z panelu bez śladu w logach, więc nazwy sprawdzamy
 * przeciwko tablicy ról w Users.
 */
require $root . '/app/src/Users.php';
$uprawnienia = (new ReflectionClass(CoachAnalyze\Users::class))->getConstant('UPRAWNIENIA');
$znane = array_unique(array_merge(...array_values($uprawnienia)));

preg_match_all("/requireCan\\(\\\$user, '([a-z_]+)'\\)/", $zrodlo, $m);
$uzyteUprawnienia = array_unique($m[1]);

check('w kodzie są wywołania requireCan', $uzyteUprawnienia !== []);
foreach ($uzyteUprawnienia as $nazwa) {
    check("uprawnienie istnieje: {$nazwa}", in_array($nazwa, $znane, true),
        'znane: ' . implode(', ', $znane));
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
