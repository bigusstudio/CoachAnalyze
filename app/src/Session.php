<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Sesja i token CSRF.
 *
 * Ciasteczko sesji: HttpOnly (niedostępne dla JS), SameSite=Lax (nie leci przy
 * żądaniach z obcych stron, ale zwykłe wejście z linku działa), Secure gdy HTTPS.
 */
final class Session
{
    private const CSRF_KEY = '_csrf';
    private const USER_KEY = '_uid';
    private const LEVEL_KEY = '_level';
    private const AUTH_FP_KEY = '_auth_fp';

    /**
     * Nazwa ciasteczka sesji jako stała, nie tylko argument `session_name()`.
     *
     * Potrzebna PRZED `session_start()`: żeby odróżnić „przeglądarka nie
     * odesłała ciasteczka" od „sesja wygasła", trzeba zajrzeć do `$_COOKIE`,
     * a po starcie sesji PHP jedno od drugiego już nie odróżnia.
     */
    public const COOKIE = 'ca_session';

    /**
     * Powody odrzucenia tokenu CSRF.
     *
     * PO CO ROZRÓŻNIENIE: prowadzą do RÓŻNYCH czynności użytkownika, a tylko
     * jeden z nich naprawia się powtórzeniem próby. „Formularz stracił ważność.
     * Spróbuj jeszcze raz." przy braku sesji obiecuje coś, co nie ma prawa
     * zadziałać — użytkownik klika w kółko, bo komunikat go do tego zachęca.
     * Zgłoszone z produkcji (Firefox/Windows, ostrzeżenie o certyfikacie).
     *
     * `no_cookie` — żądanie przyszło BEZ ciasteczka sesji. Sesja nie istnieje
     *               i nie powstanie, dopóki przeglądarka ciasteczka nie zapisze.
     * `no_token`  — ciasteczko przyszło, ale sesja po stronie serwera jest pusta:
     *               wygasła, została sprzątnięta albo serwer nie umie jej zapisać.
     * `mismatch`  — sesja żyje, token się nie zgadza. TO JEST stary formularz
     *               i tylko tutaj powtórzenie faktycznie pomaga.
     */
    public const CSRF_OK        = 'ok';
    public const CSRF_NO_COOKIE = 'no_cookie';
    public const CSRF_NO_TOKEN  = 'no_token';
    public const CSRF_MISMATCH  = 'mismatch';

    /**
     * Poziom uwierzytelnienia sesji.
     *
     * `password`   — użytkownik podał hasło w tej sesji. Pełne uprawnienia.
     * `remembered` — sesja odtworzona z ciasteczka trwałego. Wystarcza do pracy,
     *                NIE wystarcza do operacji na koncie.
     *
     * Rozróżnienie istnieje po to, żeby skradzione ciasteczko nie pozwoliło
     * zmienić hasła i odciąć właściciela od własnego konta.
     */
    public const LEVEL_PASSWORD = 'password';
    public const LEVEL_REMEMBERED = 'remembered';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,                       // do zamknięcia przeglądarki
            'path'     => '/',
            'domain'   => '',
            'secure'   => self::secureCookie(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name(self::COOKIE);
        session_start();
    }

    /**
     * Czy ciasteczko sesji jest oznaczane `Secure`.
     *
     * Rozstrzyga APP_URL, a NIE schemat bieżącego żądania — nagłówki schematu
     * przychodzą od klienta i wyprowadzanie z nich tej decyzji pozwoliłoby
     * zdjąć `Secure` samym żądaniem po HTTP.
     */
    public static function secureCookie(): bool
    {
        return str_starts_with((string) Config::get('APP_URL', ''), 'https://');
    }

    /** Czy przeglądarka odesłała ciasteczko sesji z TYM żądaniem. */
    public static function cookieReturned(): bool
    {
        return isset($_COOKIE[self::COOKIE]) && $_COOKIE[self::COOKIE] !== '';
    }

    /**
     * Ciasteczko sesji nie ma jak wrócić: jest `Secure`, a żądanie przyszło
     * bez szyfrowania. Przeglądarka odrzuca je po cichu, więc sesja nigdy nie
     * powstaje i KAŻDA próba logowania kończy się odrzuceniem tokenu CSRF.
     *
     * Wynik służy WYŁĄCZNIE do diagnozy i komunikatu — nigdy do osłabienia
     * flagi `Secure` (patrz `secureCookie()`). Dlatego wolno tu zaglądać
     * do nagłówków pośrednika: fałszywy „to jest HTTPS" niczego nie otwiera,
     * daje najwyżej mniej trafny komunikat.
     */
    public static function cookieUnreachable(): bool
    {
        return self::secureCookie() && !self::requestIsHttps();
    }

    private static function requestIsHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strcasecmp($https, 'off') !== 0) {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        if (strcasecmp((string) ($_SERVER['REQUEST_SCHEME'] ?? ''), 'https') === 0) {
            return true;
        }
        // Pośrednik kończący TLS. `X-Forwarded-Proto` bywa listą — liczy się pierwszy wpis.
        $proto = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
        if (strcasecmp($proto, 'https') === 0) {
            return true;
        }
        return strcasecmp((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''), 'on') === 0;
    }

    /**
     * Czy serwer w ogóle jest w stanie ZAPISAĆ sesję. Zwraca powód
     * niepowodzenia (do logu) albo null, gdy zapis działa.
     *
     * PO CO: sesja, której nie da się zapisać, wygląda z przeglądarki
     * identycznie jak zablokowane ciasteczka — ciasteczko wraca, a sesja jest
     * pusta. Zanim obwinimy przeglądarkę użytkownika, sprawdzamy siebie.
     *
     * Sprawdzamy PRÓBĄ ZAPISU, nie `is_writable` — na lh.pl `is_writable` na
     * ścieżce spoza `open_basedir` kłamie (ten sam powód, co przy LOG_PATH
     * w `bootstrap.php`).
     */
    public static function storageProblem(): ?string
    {
        $path = (string) session_save_path();

        // Format „N;/ścieżka" albo „N;tryb;/ścieżka" — ścieżka jest na końcu.
        if (str_contains($path, ';')) {
            $path = substr($path, (int) strrpos($path, ';') + 1);
        }
        if ($path === '') {
            $path = sys_get_temp_dir();
        }
        // Magazyn niepikowy (redis://, memcached://) — nie zgadujemy, jak go sprawdzić.
        if (str_contains($path, '://')) {
            return null;
        }
        if (!is_dir($path)) {
            return 'katalog sesji nie istnieje: ' . $path;
        }

        $probka = rtrim($path, '/') . '/ca_probe_' . bin2hex(random_bytes(6));
        if (@file_put_contents($probka, 'x') === false) {
            return 'katalog sesji jest niezapisywalny: ' . $path;
        }
        @unlink($probka);

        return null;
    }

    /**
     * Po zalogowaniu ZAWSZE nowy identyfikator sesji. Bez tego identyfikator
     * podsunięty przed logowaniem zostaje ważny po nim (session fixation).
     */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    public static function login(int $userId, string $level = self::LEVEL_PASSWORD): void
    {
        self::regenerate();
        $_SESSION[self::USER_KEY] = $userId;
        $_SESSION[self::LEVEL_KEY] = $level === self::LEVEL_REMEMBERED
            ? self::LEVEL_REMEMBERED
            : self::LEVEL_PASSWORD;
    }

    public static function level(): string
    {
        self::start();
        $level = $_SESSION[self::LEVEL_KEY] ?? self::LEVEL_PASSWORD;
        return $level === self::LEVEL_REMEMBERED ? self::LEVEL_REMEMBERED : self::LEVEL_PASSWORD;
    }

    /** Czy sesja wystarcza do operacji na koncie (zmiana hasła itp.). */
    public static function hasFullAccess(): bool
    {
        return self::userId() !== null && self::level() === self::LEVEL_PASSWORD;
    }

    public static function userId(): ?int
    {
        self::start();
        $id = $_SESSION[self::USER_KEY] ?? null;
        return is_int($id) ? $id : null;
    }

    /**
     * Odcisk poświadczenia, którym ta sesja została otwarta.
     *
     * PO CO: zmiana hasła ma unieważniać POZOSTAŁE sesje. Sesje PHP leżą
     * w plikach i nie da się ich wskazać z bazy, więc zamiast szukać cudzych
     * sesji, każda sesja nosi skrót hasha hasła z chwili zalogowania.
     * `Auth::currentUser()` porównuje go z bieżącym — po zmianie hasła
     * wszystkie sesje poza tą, która zmieniała, przestają się zgadzać
     * i wygasają przy najbliższym żądaniu.
     *
     * Sesja sprzed wdrożenia nie ma odcisku (null) — jest honorowana do
     * wylogowania; odcisk dostanie przy następnym zalogowaniu.
     */
    public static function bindAuth(string $fingerprint): void
    {
        self::start();
        $_SESSION[self::AUTH_FP_KEY] = $fingerprint;
    }

    public static function authFingerprint(): ?string
    {
        self::start();
        $fp = $_SESSION[self::AUTH_FP_KEY] ?? null;
        return is_string($fp) && $fp !== '' ? $fp : null;
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'],
            ]);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::CSRF_KEY];
    }

    /** Porównanie w stałym czasie — zwykłe === wycieka informację przez czas odpowiedzi. */
    public static function checkCsrf(?string $token): bool
    {
        return self::csrfProblem($token) === self::CSRF_OK;
    }

    /**
     * To samo sprawdzenie co `checkCsrf()`, ale z NAZWANĄ przyczyną odrzucenia.
     *
     * Warstwa prezentacji potrzebuje przyczyny, bo tylko przy `mismatch` wolno
     * powiedzieć „spróbuj jeszcze raz" — w pozostałych przypadkach powtórzenie
     * da dokładnie ten sam wynik (patrz opis stałych CSRF_*).
     *
     * Kolejność ma znaczenie: najpierw sprawdzamy, czy sesja NIESIE token.
     * Gdy go nie ma, nie ma z czym porównywać i pytanie „czy token pasuje"
     * traci sens — rozstrzyga wtedy to, czy przeglądarka odesłała ciasteczko.
     */
    public static function csrfProblem(?string $token): string
    {
        // Zapamiętane PRZED startem sesji: `session_start()` zakłada tablicę
        // `$_SESSION` niezależnie od tego, czy klient cokolwiek przysłał.
        $ciasteczko = self::cookieReturned();

        self::start();
        $expected = $_SESSION[self::CSRF_KEY] ?? null;

        if (is_string($expected) && $expected !== '') {
            return is_string($token) && $token !== '' && hash_equals($expected, $token)
                ? self::CSRF_OK
                : self::CSRF_MISMATCH;
        }

        return $ciasteczko ? self::CSRF_NO_TOKEN : self::CSRF_NO_COOKIE;
    }

    /** @param mixed $value */
    public static function flash(string $key, $value = null)
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $out = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $out;
    }
}
