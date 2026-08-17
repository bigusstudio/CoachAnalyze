<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Konta użytkowników: zakładanie, role, dezaktywacja, reset hasła.
 *
 * ROZWIĄZANIE TYMCZASOWE i tak jest opisane w interfejsie. W tej wersji każde
 * konto widzi dane wszystkich klubów — rozdzielenie danych między klientami to
 * osobne wdrożenie, nie flaga do dopisania. Ostrzeżenie przy zakładaniu konta
 * jest częścią funkcji, nie ozdobą: bez niego ktoś założy konto trenerowi
 * z innego klubu i dowie się o tym za późno.
 *
 * HASŁA NIE PRZECHODZĄ PRZEZ ŻADEN LOG. Ani `audit_log`, ani `error_log`,
 * ani komunikat błędu. Jedyne miejsce, w którym hasło jawne istnieje, to
 * odpowiedź HTTP pokazana raz przy zakładaniu konta i przy resecie.
 */
final class Users
{
    /**
     * Role. `admin` zarządza kontami, `operator` pracuje na danych,
     * `viewer` tylko ogląda.
     */
    public const ROLE = ['admin', 'operator', 'viewer'];

    public const STATUS = ['active', 'disabled'];

    /**
     * Długość generowanego hasła. 16 znaków z alfabetu 58-znakowego to około
     * 94 bity entropii — hasło zakłada maszyna i człowiek nie musi go pamiętać,
     * więc nie ma powodu schodzić niżej.
     */
    public const DLUGOSC_HASLA = 16;

    /**
     * Uprawnienia ról. ŚWIADOMIE PROSTE — lista czynności, nie system reguł.
     *
     * Rozbudowany model uprawnień przy trzech rolach i jednym kliencie kosztuje
     * więcej, niż daje, i zwykle kończy się tym, że nikt nie wie, kto co może.
     * Gdy dojdzie czwarta rola albo separacja klientów, to jest miejsce do zmiany.
     */
    private const UPRAWNIENIA = [
        'admin' => [
            'accounts', 'upload', 'generate', 'share', 'clubs', 'seasons',
            'notes', 'mappings', 'reports', 'index',
        ],
        'operator' => [
            'upload', 'generate', 'share', 'clubs', 'seasons',
            'notes', 'mappings', 'reports', 'index',
        ],
        // Viewer OGLĄDA. Bez uploadu i bez udostępniania — te dwie czynności
        // wypuszczają dane poza panel i wymagają decyzji, a nie tylko dostępu.
        // Indeks współczynników czyta (`/indeks` nie ma bramki na GET),
        // ale nie edytuje — hasło opisuje metodykę, a metodyka to decyzja.
        'viewer' => ['notes', 'reports'],
    ];

    public static function can(?array $user, string $czynnosc): bool
    {
        $rola = (string) ($user['role'] ?? '');
        return in_array($czynnosc, self::UPRAWNIENIA[$rola] ?? [], true);
    }

    public static function isAdmin(?array $user): bool
    {
        return (string) ($user['role'] ?? '') === 'admin';
    }

    // ---------------------------------------------------------------- odczyt

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Db::all(
            'SELECT u.id, u.email, u.display_name, u.role, u.status,
                    u.must_change_password, u.created_at, u.last_login_at,
                    a.display_name AS autor
               FROM users u
               LEFT JOIN users a ON a.id = u.created_by
              ORDER BY u.status, u.created_at DESC'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** Ilu jest czynnych administratorów — do pilnowania, żeby został choć jeden. */
    public static function activeAdminCount(): int
    {
        $w = Db::one(
            "SELECT COUNT(*) AS ile FROM users WHERE role = 'admin' AND status = 'active'"
        );
        return (int) ($w['ile'] ?? 0);
    }

    // ---------------------------------------------------------------- hasło

    /**
     * Hasło z CSPRNG.
     *
     * Alfabet bez znaków mylących przy przepisywaniu (`0`, `O`, `l`, `I`, `1`) —
     * hasło jest pokazywane raz na ekranie i bywa przepisywane ręcznie albo
     * dyktowane przez telefon. Znak, którego nie da się jednoznacznie odczytać,
     * zamienia jednorazowe hasło w telefon do administratora.
     *
     * `random_int` zamiast `rand`: to jedyne źródło losowości, któremu wolno tu
     * zaufać. Reszta nadaje się do animacji, nie do haseł.
     */
    public static function generatePassword(int $dlugosc = self::DLUGOSC_HASLA): string
    {
        $alfabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alfabet) - 1;

        $haslo = '';
        for ($i = 0; $i < max(12, $dlugosc); $i++) {
            $haslo .= $alfabet[random_int(0, $max)];
        }
        return $haslo;
    }

    // ---------------------------------------------------------------- zapis

    /**
     * Nowe konto. Zwraca identyfikator i hasło jawne DO JEDNORAZOWEGO POKAZANIA.
     *
     * Hasło wraca stąd wyłącznie po to, żeby trafić na ekran. Nie zapisujemy go
     * nigdzie — ani w bazie (jest hash), ani w `audit_log`, ani w logu aplikacji.
     *
     * @return array{id:int, password:string}
     * @throws \RuntimeException gdy adres zajęty albo dane niepoprawne
     */
    public static function create(array $dane, int $authorId): array
    {
        $email = trim((string) ($dane['email'] ?? ''));
        $nazwa = trim((string) ($dane['display_name'] ?? ''));
        $rola  = (string) ($dane['role'] ?? 'operator');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException(View::t('users.err.email'));
        }
        if ($nazwa === '') {
            throw new \RuntimeException(View::t('users.err.name'));
        }
        if (!in_array($rola, self::ROLE, true)) {
            throw new \RuntimeException(View::t('users.err.role'));
        }
        if (Db::one('SELECT id FROM users WHERE email = :e', ['e' => $email]) !== null) {
            throw new \RuntimeException(View::t('users.err.taken'));
        }

        $haslo = self::generatePassword();

        Db::run(
            'INSERT INTO users
                (email, pass_hash, display_name, role, status, must_change_password,
                 created_at, created_by)
             VALUES (:e, :h, :n, :r, :s, 1, :now, :by)',
            [
                'e'   => $email,
                'h'   => Auth::hashPassword($haslo),
                'n'   => mb_substr($nazwa, 0, 120),
                'r'   => $rola,
                's'   => 'active',
                'now' => Stats::now(),
                'by'  => $authorId,
            ]
        );

        $id = (int) Db::pdo()->lastInsertId();

        // W audycie NIE MA HASŁA. Jest to, co wolno zapisać: kto, komu, jaka rola.
        Audit::log('user.created', $authorId, 'user', $id, [
            'email' => $email,
            'role'  => $rola,
        ]);

        return ['id' => $id, 'password' => $haslo];
    }

    /**
     * Zmiana roli.
     *
     * ADMIN NIE ZMIENIA ROLI SAMEMU SOBIE. Nie chodzi o zaufanie, tylko o to,
     * że jedno nieuważne kliknięcie zostawiłoby system bez administratora,
     * a odzyskanie dostępu wymagałoby wejścia do bazy przez SSH.
     *
     * @throws \RuntimeException z powodem po polsku
     */
    public static function setRole(int $userId, string $rola, int $authorId): void
    {
        if (!in_array($rola, self::ROLE, true)) {
            throw new \RuntimeException(View::t('users.err.role'));
        }
        if ($userId === $authorId) {
            throw new \RuntimeException(View::t('users.err.self_role'));
        }

        $user = self::find($userId);
        if ($user === null) {
            throw new \RuntimeException(View::t('users.err.missing'));
        }

        // Odebranie roli ostatniemu czynnemu administratorowi = system bez admina.
        if ((string) $user['role'] === 'admin'
            && $rola !== 'admin'
            && (string) $user['status'] === 'active'
            && self::activeAdminCount() <= 1) {
            throw new \RuntimeException(View::t('users.err.last_admin'));
        }

        Db::run('UPDATE users SET role = :r WHERE id = :id', ['r' => $rola, 'id' => $userId]);
        Audit::log('user.role_changed', $authorId, 'user', $userId, [
            'from' => (string) $user['role'],
            'to'   => $rola,
        ]);
    }

    /**
     * Dezaktywacja i przywrócenie konta.
     *
     * DEZAKTYWACJA UNIEWAŻNIA DOSTĘP NATYCHMIAST. Samo ustawienie flagi
     * zostawiłoby czynną sesję i ważne ciasteczko „zapamiętaj mnie" — konto
     * byłoby wyłączone na liście i działające w przeglądarce.
     *
     * @throws \RuntimeException z powodem po polsku
     */
    public static function setStatus(int $userId, string $status, int $authorId): void
    {
        if (!in_array($status, self::STATUS, true)) {
            throw new \RuntimeException(View::t('users.err.status'));
        }

        $user = self::find($userId);
        if ($user === null) {
            throw new \RuntimeException(View::t('users.err.missing'));
        }

        if ($status === 'disabled') {
            if ($userId === $authorId) {
                throw new \RuntimeException(View::t('users.err.self_disable'));
            }
            if ((string) $user['role'] === 'admin' && self::activeAdminCount() <= 1) {
                throw new \RuntimeException(View::t('users.err.last_admin'));
            }
        }

        Db::run('UPDATE users SET status = :s WHERE id = :id', ['s' => $status, 'id' => $userId]);

        if ($status === 'disabled') {
            // Tokeny trwałe — inaczej przeglądarka z ciasteczkiem wchodzi dalej.
            Remember::forgetAll($userId, 'account_disabled');
            // Sesje — patrz Auth::currentUser(), które od teraz odrzuca to konto.
            Db::run("DELETE FROM remember_tokens WHERE user_id = :id", ['id' => $userId]);
        }

        Audit::log($status === 'disabled' ? 'user.disabled' : 'user.enabled',
            $authorId, 'user', $userId);
    }

    /**
     * Reset hasła przez administratora. Zwraca nowe hasło DO JEDNORAZOWEGO POKAZANIA.
     *
     * Konto dostaje flagę wymuszonej zmiany: hasło zna teraz dwoje ludzi i ma
     * przestać być prawdziwe przy pierwszym logowaniu właściciela.
     *
     * @return string hasło jawne — nie zapisujemy go nigdzie
     */
    public static function resetPassword(int $userId, int $authorId): string
    {
        $user = self::find($userId);
        if ($user === null) {
            throw new \RuntimeException(View::t('users.err.missing'));
        }

        $haslo = self::generatePassword();

        Db::run(
            'UPDATE users SET pass_hash = :h, must_change_password = 1 WHERE id = :id',
            ['h' => Auth::hashPassword($haslo), 'id' => $userId]
        );

        // Wszystkie urządzenia wypadają: hasło się zmieniło, więc dostęp sprzed
        // zmiany przestaje obowiązywać.
        Remember::forgetAll($userId, 'password_reset');

        // BEZ HASŁA W AUDYCIE.
        Audit::log('user.password_reset', $authorId, 'user', $userId);

        return $haslo;
    }

    /** Zdjęcie flagi po samodzielnej zmianie hasła przez właściciela konta. */
    public static function clearPasswordChangeFlag(int $userId): void
    {
        Db::run('UPDATE users SET must_change_password = 0 WHERE id = :id', ['id' => $userId]);
    }
}
