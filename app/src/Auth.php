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
        'memory_cost' => 98304,  // 96 MB
        'time_cost'   => 5,
        // JEDEN wątek — nie z wygody, tylko z konieczności. libargon2 na lh.pl
        // jest zbudowane bez obsługi wątków i `password_hash` z p>1 kończy się
        // błędem „A thread value other than 1 is not supported by this
        // implementation". Szczegóły: docs/OGRANICZENIA_HOSTINGU.md.
        'threads'     => 1,
    ];

    /**
     * Prawdziwy hash losowego napisu, którego nikt nie zna. Służy wyłącznie do
     * porównania przy NIEISTNIEJĄCYM koncie, żeby czas odpowiedzi był taki sam
     * jak przy koncie istniejącym. Hash sklejony ręcznie nie zadziała:
     * `password_verify` odrzuca niepoprawny format natychmiast i właśnie ta
     * różnica czasu zdradzałaby, które adresy są zarejestrowane.
     *
     * Zapisany z p=1 — wariant z p=2 nie dałby się zweryfikować na lh.pl
     * i ochrona przed sondowaniem listy kont przestałaby działać dokładnie
     * tam, gdzie jest potrzebna.
     *
     * Przy mocnym podniesieniu parametrów w .env warto go przeliczyć, żeby czas
     * porównania dla konta nieistniejącego nadal odpowiadał temu dla istniejącego.
     */
    private const DUMMY_HASH =
        '$argon2id$v=19$m=98304,t=5,p=1$T3lTbWVXM0FYT3cxQ0pMOA$2DZmaKwLn0qt8VJ2yNqrrATjXcjPcDAdwtJt9cDjeYA';

    public function __construct(private ?RateLimit $limiter = null) {}

    /**
     * Parametry argon2id z konfiguracji, z domyślnymi wartościami wyżej.
     *
     * W .env, a nie na sztywno: sprzęt i limity hostingu się zmieniają, a koszt
     * haszowania powinien dać się podnieść bez wydania nowej wersji aplikacji.
     *
     * @return array{memory_cost:int, time_cost:int, threads:int}
     */
    public static function argonOptions(): array
    {
        return [
            'memory_cost' => max(8192, Config::int('ARGON_MEMORY_COST', self::ARGON_OPTIONS['memory_cost'])),
            'time_cost'   => max(1, Config::int('ARGON_TIME_COST', self::ARGON_OPTIONS['time_cost'])),
            'threads'     => max(1, Config::int('ARGON_THREADS', self::ARGON_OPTIONS['threads'])),
        ];
    }

    /** Minimalna długość hasła. Z .env — polityka bywa zmieniana bez zmiany kodu. */
    public static function minPasswordLength(): int
    {
        return max(8, Config::int('PASSWORD_MIN_LENGTH', 8));
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, self::argonOptions());
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

        /*
         * `SELECT *`, a NIE lista kolumn.
         *
         * `status` i `must_change_password` pochodzą z migracji 008. Wymienione
         * z nazwy zamieniałyby wdrożenie kodu PRZED migracją w całkowitą blokadę
         * logowania — czyli awarię gorszą niż brak nowej funkcji, i to na jedynej
         * ścieżce, którą można by ją naprawić przez panel.
         *
         * Kolejność „kod, potem migracja" zdarzała się w tym projekcie już
         * kilkakrotnie. Odczyt wszystkich kolumn i wartości domyślne niżej
         * sprawiają, że taka kolejność jest niegroźna.
         */
        $user = Db::one('SELECT * FROM users WHERE email = :email', ['email' => $email]);

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
        // Konto założone na starych parametrach (m.in. p=2 sprzed zderzenia
        // z libargon2 na lh.pl) przechodzi na bieżące przy pierwszym udanym
        // logowaniu — o ile stary hash w ogóle daje się zweryfikować.
        if (password_needs_rehash((string) $user['pass_hash'], PASSWORD_ARGON2ID, self::argonOptions())) {
            Db::run('UPDATE users SET pass_hash = :hash WHERE id = :id', [
                'hash' => self::hashPassword($password),
                'id'   => $user['id'],
            ]);
        }

        /*
         * KONTO WYŁĄCZONE — sprawdzane PO weryfikacji hasła.
         *
         * Kolejność ma znaczenie: gdyby stan konta rozstrzygał przed hasłem,
         * różnica w komunikacie zdradzałaby, które adresy istnieją w systemie.
         * Tutaj hasło musi się zgadzać, żeby w ogóle dojść do tego miejsca,
         * a komunikat i tak jest ten sam co przy złych danych.
         */
        // Brak kolumny (baza sprzed migracji 008) znaczy „konto czynne" —
        // taka jest też wartość domyślna w migracji.
        if ((string) ($user['status'] ?? 'active') === 'disabled') {
            Audit::log('login.disabled', (int) $user['id'], 'user', (int) $user['id']);
            return ['ok' => false, 'error' => 'invalid_credentials'];
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

    /**
     * Zalogowanie ciasteczkiem trwałym.
     *
     * Sesja dostaje poziom `remembered` — wystarcza do pracy, nie wystarcza do
     * operacji na koncie. Token jest zużywany i wymieniany na nowy (rotacja),
     * więc wywołujący MUSI zapisać zwróconą wartość w ciasteczku.
     *
     * @return array{ok:bool, token?:string}
     */
    public function loginFromCookie(string $presented): array
    {
        $result = Remember::consume($presented);
        if (!$result['ok']) {
            return ['ok' => false];
        }

        $userId = (int) $result['user_id'];

        // Konto mogło zniknąć od czasu wydania tokenu.
        if (Db::one('SELECT id FROM users WHERE id = :id', ['id' => $userId]) === null) {
            Remember::forgetAll($userId, 'brak konta');
            return ['ok' => false];
        }

        Session::login($userId, Session::LEVEL_REMEMBERED);
        return ['ok' => true, 'token' => (string) $result['token']];
    }

    /**
     * Zmiana hasła. Unieważnia WSZYSTKIE tokeny trwałe użytkownika.
     *
     * Wymaga sesji o pełnych uprawnieniach ORAZ podania dotychczasowego hasła —
     * samo ciasteczko trwałe nie może odciąć właściciela od konta.
     *
     * @return array{ok:bool, error?:string}
     */
    public function changePassword(int $userId, string $current, string $new): array
    {
        if (!Session::hasFullAccess()) {
            return ['ok' => false, 'error' => 'account.err.reauth'];
        }

        $user = Db::one('SELECT pass_hash FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null || !password_verify($current, (string) $user['pass_hash'])) {
            return ['ok' => false, 'error' => 'account.err.current'];
        }

        if (mb_strlen($new, 'UTF-8') < self::minPasswordLength()) {
            return ['ok' => false, 'error' => 'account.err.short'];
        }

        Db::run('UPDATE users SET pass_hash = :hash WHERE id = :id', [
            'hash' => self::hashPassword($new),
            'id'   => $userId,
        ]);

        // Zmiana hasła to najczęściej reakcja na podejrzenie wycieku. Każde
        // zapamiętane urządzenie musi wtedy stracić dostęp.
        Remember::forgetAll($userId, 'zmiana hasła');

        // Flaga wymuszonej zmiany zdejmuje się TYLKO tutaj — czyli wtedy, gdy
        // hasło zmienił sam właściciel konta, podając stare. Reset przez
        // administratora flagę zakłada, a nie zdejmuje.
        Users::clearPasswordChangeFlag($userId);

        Audit::log('user.password_changed', $userId, 'user', $userId);

        return ['ok' => true];
    }

    public function logout(): void
    {
        $userId = Session::userId();
        if ($userId !== null) {
            // Wylogowanie kasuje WSZYSTKIE tokeny trwałe tego użytkownika,
            // nie tylko bieżące urządzenie — tak brzmi wymaganie i tak jest
            // bezpieczniej: „wyloguj" ma znaczyć „odetnij dostęp".
            Remember::forgetAll($userId, 'wylogowanie');
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
        /*
         * Konto wyłączone przestaje być zalogowane NATYCHMIAST, także w sesji
         * otwartej przed dezaktywacją. Bez tego warunku administrator wyłączałby
         * konto, a osoba z otwartą kartą pracowałaby dalej do wygaśnięcia sesji.
         */
        $user = Db::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);

        if ($user === null) {
            return null;
        }

        // Warunek stanu w PHP, nie w SQL — z tego samego powodu, co wyżej:
        // zapytanie z `status = 'active'` wywalałoby się na bazie bez tej kolumny.
        if ((string) ($user['status'] ?? 'active') === 'disabled') {
            return null;
        }

        // Hash hasła nie ma po co krążyć po warstwie widoku.
        unset($user['pass_hash']);
        return $user;
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
