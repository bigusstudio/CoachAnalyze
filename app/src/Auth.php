<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Logowanie operatora. Hasła: argon2id (CLAUDE.md §5).
 *
 * Zasada komunikatów: użytkownik dostaje ZAWSZE ten sam tekst przy złym haśle
 * i przy nieistniejącym koncie. Rozróżnienie tych dwóch przypadków pozwala
 * sprawdzić, które adresy są zarejestrowane.
 */
final class Auth
{
    /**
     * Parametry argon2id. Świadomie powyżej domyślnych PHP: koszt logowania jest
     * ponoszony raz na sesję, koszt łamania — przy każdej próbie z listy.
     */
    private const ARGON_OPTIONS = [
        'memory_cost' => 65536,  // 64 MB
        'time_cost'   => 4,
        'threads'     => 2,
    ];

    /**
     * Prawdziwy hash losowego napisu, którego nikt nie zna. Służy wyłącznie do
     * porównania przy NIEISTNIEJĄCYM koncie, żeby czas odpowiedzi był taki sam
     * jak przy koncie istniejącym (~64 ms). Hash sklejony ręcznie nie zadziała:
     * `password_verify` odrzuca niepoprawny format natychmiast i właśnie ta
     * różnica czasu zdradzałaby, które adresy są zarejestrowane.
     */
    private const DUMMY_HASH =
        '$argon2id$v=19$m=65536,t=4,p=2$aElBZTZEbFRWSGdoWi9ocw$ASDifOnT83xTSSkrk4WsQfT566BUe8E1p2Yg7nik1oI';

    public function __construct(private ?RateLimit $limiter = null) {}

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
    }

    /**
     * @return array{ok:bool, user?:array<string,mixed>, error?:string, retry_after?:int}
     */
    public function attempt(string $email, string $password): array
    {
        $email = trim($email);
        $limiter = $this->limiter ??= new RateLimit();

        // 1. Limit prób. Awaria Redisa blokuje logowanie — patrz komentarz w RateLimit.
        try {
            $blockedFor = $limiter->check($email);
        } catch (\RuntimeException $e) {
            error_log('rate limit: ' . $e->getMessage());
            Audit::log(Audit::LOGIN_BACKEND_DOWN, null, 'user', null, ['email' => $email]);
            return ['ok' => false, 'error' => 'login_unavailable'];
        }

        if ($blockedFor > 0) {
            Audit::log(Audit::LOGIN_RATE_LIMITED, null, 'user', null, [
                'email' => $email,
                'retry_after' => $blockedFor,
            ]);
            return ['ok' => false, 'error' => 'rate_limited', 'retry_after' => $blockedFor];
        }

        $user = Db::one(
            'SELECT id, email, pass_hash, display_name, role FROM users WHERE email = :email',
            ['email' => $email]
        );

        // Przy nieistniejącym koncie i tak wykonujemy pełne porównanie — patrz DUMMY_HASH.
        $hash = $user['pass_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, (string) $hash) && $user !== null;

        if (!$valid) {
            $limiter->registerFailure($email);
            Audit::log(Audit::LOGIN_FAIL, $user['id'] ?? null, 'user', $user['id'] ?? null, [
                'email' => $email,
            ]);
            return ['ok' => false, 'error' => 'invalid_credentials'];
        }

        // Parametry haszowania mogły się zmienić od czasu założenia konta.
        if (password_needs_rehash((string) $user['pass_hash'], PASSWORD_ARGON2ID, self::ARGON_OPTIONS)) {
            Db::run('UPDATE users SET pass_hash = :hash WHERE id = :id', [
                'hash' => self::hashPassword($password),
                'id'   => $user['id'],
            ]);
        }

        $limiter->clear($email);
        Session::login((int) $user['id']);
        // Czas z aplikacji, nie z NOW() — jedno źródło zegara w całej warstwie PHP
        // (tak samo w Stats) i zapytania dają się uruchomić poza MySQL-em.
        Db::run('UPDATE users SET last_login_at = :now WHERE id = :id', [
            'now' => Stats::now(),
            'id'  => $user['id'],
        ]);
        Audit::log(Audit::LOGIN_OK, (int) $user['id'], 'user', (int) $user['id']);

        unset($user['pass_hash']);
        return ['ok' => true, 'user' => $user];
    }

    public function logout(): void
    {
        $userId = Session::userId();
        if ($userId !== null) {
            Audit::log(Audit::LOGOUT, $userId, 'user', $userId);
        }
        Session::destroy();
    }

    /** @return array<string,mixed>|null */
    public static function currentUser(): ?array
    {
        $id = Session::userId();
        if ($id === null) {
            return null;
        }
        return Db::one(
            'SELECT id, email, display_name, role, last_login_at FROM users WHERE id = :id',
            ['id' => $id]
        );
    }

    public static function isLoggedIn(): bool
    {
        return Session::userId() !== null;
    }

    /** Przekierowanie na ekran logowania dla tras panelu. */
    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            Session::destroy();
            header('Location: /login');
            exit;
        }
        return $user;
    }
}
