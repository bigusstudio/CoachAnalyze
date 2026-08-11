<?php
declare(strict_types=1);

/**
 * Testy formularzy uwierzytelniania pod menedżery haseł.
 *
 * POWÓD ISTNIENIA: te atrybuty nie robią nic widocznego, więc przy przepisywaniu
 * szablonu wypadają pierwsze i nikt tego nie zauważa — aż menedżer haseł
 * przestaje podpowiadać dane i operator zaczyna wpisywać hasło z palca.
 *
 * Objaw z produkcji: Keychain na macOS nie rozpoznawał formularza logowania.
 *
 * Uruchomienie:  php app/tests/test_forms.php
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

/**
 * Szablon jako tekst, z WYCIĘTYMI wstawkami PHP.
 *
 * Bez tego `value="<?= … ?>"` rozbija dopasowanie znacznika: `>` z zamknięcia
 * wstawki wygląda dla wyrażenia regularnego jak koniec `<input …>`, więc
 * atrybuty stojące dalej są niewidoczne. Test zgłaszał wtedy brak `readonly`
 * i `autocomplete`, choć oba były w pliku.
 */
function widok(string $root, string $nazwa): string
{
    $html = (string) file_get_contents($root . '/app/src/Views/' . $nazwa . '.php');
    return (string) preg_replace('/<\?(php|=).*?\?>/s', 'WSTAWKA', $html);
}

/** Czy w znaczniku o podanej nazwie pola stoi dany atrybut. */
function poleMa(string $html, string $name, string $atrybut): bool
{
    // Znacznik <input …> zawierający name="…" — bez zakładania kolejności atrybutów.
    if (preg_match_all('/<input\b[^>]*>/i', $html, $wszystkie) === false) {
        return false;
    }
    foreach ($wszystkie[0] as $tag) {
        if (str_contains($tag, 'name="' . $name . '"') && str_contains($tag, $atrybut)) {
            return true;
        }
    }
    return false;
}

echo "== ekran logowania ==\n";

$login = widok($root, 'login');

check('formularz metodą POST', preg_match('/<form[^>]*method="post"/i', $login) === 1);
check('widoczny przycisk type="submit"',
    preg_match('/<button[^>]*type="submit"/i', $login) === 1
    && !preg_match('/<button[^>]*type="submit"[^>]*hidden/i', $login));

check('adres: type="email"',    poleMa($login, 'email', 'type="email"'));
check('adres: name="email"',    poleMa($login, 'email', 'name="email"'));
// autocomplete="username" na polu adresu to sygnał, po którym menedżer haseł
// w ogóle rozpoznaje formularz jako logowanie.
check('adres: autocomplete="username"', poleMa($login, 'email', 'autocomplete="username"'));

check('hasło: type="password"', poleMa($login, 'password', 'type="password"'));
check('hasło: name="password"', poleMa($login, 'password', 'name="password"'));
check('hasło: autocomplete="current-password"',
    poleMa($login, 'password', 'autocomplete="current-password"'));

// Oba pola MUSZĄ być w jednym formularzu — rozdzielone są dla menedżera
// dwoma osobnymi, niepowiązanymi ekranami.
$formularze = preg_split('/<form\b/i', $login);
$zLogowaniem = array_filter($formularze, static fn($f) =>
    str_contains($f, 'name="email"') && str_contains($f, 'name="password"'));
check('adres i hasło w TYM SAMYM formularzu', count($zLogowaniem) === 1);

echo "\n== powiązanie etykiet ==\n";

foreach (['pole-email', 'pole-haslo'] as $id) {
    check("etykieta wskazuje na #{$id}", str_contains($login, 'for="' . $id . '"'));
    check("pole ma id=\"{$id}\"", str_contains($login, 'id="' . $id . '"'));
}

echo "\n== zmiana hasła ==\n";

$konto = widok($root, 'account');

check('obecne hasło: autocomplete="current-password"',
    poleMa($konto, 'obecne', 'autocomplete="current-password"'));
check('nowe hasło: autocomplete="new-password"',
    poleMa($konto, 'nowe', 'autocomplete="new-password"'));
check('nowe hasło NIE ma current-password',
    !poleMa($konto, 'nowe', 'autocomplete="current-password"'));

/**
 * Bez pola z adresem konta menedżer haseł nie wie, do którego wpisu przypisać
 * nowe hasło — albo nie proponuje zapisu, albo tworzy wpis bez nazwy konta.
 */
check('formularz zmiany hasła ma pole z adresem konta',
    poleMa($konto, 'konto', 'autocomplete="username"'));
check('pole z adresem jest tylko do odczytu', poleMa($konto, 'konto', 'readonly'));
check('pole z adresem poza kolejnością wprowadzania', poleMa($konto, 'konto', 'tabindex="-1"'));

// Ukrycie klasą, nie atrybutem `hidden` ani `display:none`: pola całkowicie
// wyjęte z układu bywają przez menedżery haseł pomijane.
check('pole z adresem ukryte klasą, nie atrybutem hidden',
    poleMa($konto, 'konto', 'class="sr-only"') && !poleMa($konto, 'konto', ' hidden'));

$css = (string) file_get_contents($root . '/app/public/assets/app.css');
check('klasa .sr-only istnieje w arkuszu', str_contains($css, '.sr-only'));
check('.sr-only nie używa display:none',
    !preg_match('/\.sr-only\s*\{[^}]*display:\s*none/s', $css));

echo "\n== nagłówki nie blokują wykrywania ==\n";

$router = (string) file_get_contents($root . '/app/public/index.php');
$bootstrap = (string) file_get_contents($root . '/app/src/bootstrap.php');

// CSP na stronach panelu potrafi wyłączyć autouzupełnianie w części przeglądarek.
// U nas CSP jest wyłącznie przy serwowaniu herbów i tam ma zostać.
check('bootstrap nie ustawia CSP dla stron panelu',
    !str_contains($bootstrap, 'Content-Security-Policy'));

$cspLinie = [];
foreach (preg_split('/\R/', $router) ?: [] as $numer => $linia) {
    if (str_contains($linia, "header('Content-Security-Policy")) {
        $cspLinie[] = trim($linia);
    }
}
// W źródle PHP apostrofy są poprzedzone odwrotnym ukośnikiem — porównujemy
// po jego usunięciu, żeby test sprawdzał treść nagłówka, a nie sposób zapisu.
$cspNorm = str_replace('\\', '', implode(' | ', $cspLinie));
check('CSP tylko przy serwowaniu herbu (SVG jako treść wroga)',
    count($cspLinie) === 1 && str_contains($cspNorm, "default-src 'none'"),
    $cspNorm);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
