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

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $url = Config::get('APP_URL', '');
        session_set_cookie_params([
            'lifetime' => 0,                       // do zamknięcia przeglądarki
            'path'     => '/',
            'domain'   => '',
            'secure'   => str_starts_with((string) $url, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('ca_session');
        session_start();
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

    public static function login(int $userId): void
    {
        self::regenerate();
        $_SESSION[self::USER_KEY] = $userId;
    }

    public static function userId(): ?int
    {
        self::start();
        $id = $_SESSION[self::USER_KEY] ?? null;
        return is_int($id) ? $id : null;
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
        self::start();
        $expected = $_SESSION[self::CSRF_KEY] ?? null;
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
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
