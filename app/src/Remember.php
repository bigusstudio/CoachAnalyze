<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Trwałe logowanie: „zapamiętaj mnie na tym urządzeniu".
 *
 * OSOBNE POŚWIADCZENIE, NIE DŁUŻSZA SESJA. Sesja kończy się z przeglądarką;
 * ten token żyje 30 dni i daje logowanie o SŁABSZYCH uprawnieniach.
 *
 * Trzy zasady, na których to stoi:
 *
 * 1. ROTACJA. Token jest jednorazowy: przy każdym użyciu unieważniamy go
 *    i wydajemy nowy. Bez tego skradzione ciasteczko działa pełne 30 dni,
 *    a właściciel nie ma jak tego zauważyć.
 *
 * 2. SŁABSZE UPRAWNIENIA. Zalogowanie tokenem nie pozwala zmienić hasła ani
 *    ruszyć konta — do tego trzeba podać hasło jeszcze raz. Skradzione
 *    ciasteczko nie może więc odciąć właściciela od własnego konta.
 *
 * 3. UNIEWAŻNIENIE HURTEM. Zmiana hasła i wylogowanie kasują WSZYSTKIE tokeny
 *    użytkownika. To jest droga odzyskania kontroli po zgubieniu urządzenia.
 */
final class Remember
{
    public const COOKIE = 'ca_remember';

    /** 32 bajty = 256 bitów. Wymagane minimum to 128. */
    private const TOKEN_BYTES = 32;

    public const DAYS = 30;

    /**
     * Nowy token dla użytkownika. Zwraca wartość JAWNĄ do ciasteczka —
     * w bazie ląduje wyłącznie jej skrót i nigdzie indziej jej nie zapisujemy.
     */
    public static function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        Db::run(
            'INSERT INTO remember_tokens
                (user_id, token_hash, expires_at, created_at, user_agent_hash, ip_hash, device_label)
             VALUES (:uid, :hash, :expires, :now, :ua, :ip, :label)',
            [
                'uid'     => $userId,
                'hash'    => self::hash($token),
                'expires' => Stats::now('+' . self::DAYS . ' days'),
                'now'     => Stats::now(),
                'ua'      => self::userAgentHash(),
                'ip'      => self::ipHash(),
                'label'   => self::deviceLabel(),
            ]
        );

        return $token;
    }

    /**
     * Próba zalogowania tokenem z ciasteczka.
     *
     * Przy powodzeniu token jest ZUŻYWANY i zastępowany nowym (rotacja),
     * a wywołujący dostaje nową wartość do ustawienia w ciasteczku.
     *
     * @return array{ok:bool, user_id?:int, token?:string}
     */
    public static function consume(string $presented): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $presented)) {
            return ['ok' => false];
        }

        $row = Db::one(
            'SELECT id, user_id, expires_at FROM remember_tokens WHERE token_hash = :hash',
            ['hash' => self::hash($presented)]
        );

        if ($row === null) {
            return ['ok' => false];
        }

        // Wygasły token kasujemy przy okazji — nie ma powodu, żeby leżał dalej.
        if ((string) $row['expires_at'] <= Stats::now()) {
            Db::run('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
            return ['ok' => false];
        }

        $userId = (int) $row['user_id'];

        // ROTACJA: stary ginie, nowy powstaje. Kolejność ma znaczenie — gdyby
        // zapis nowego się nie udał, lepiej zostać bez tokenu niż z użytym.
        Db::run('DELETE FROM remember_tokens WHERE id = :id', ['id' => $row['id']]);
        $fresh = self::issue($userId);

        Db::run(
            'UPDATE remember_tokens SET last_used_at = :now WHERE token_hash = :hash',
            ['now' => Stats::now(), 'hash' => self::hash($fresh)]
        );

        Audit::log('login.remember', $userId, 'user', $userId, [
            'device' => self::deviceLabel(),
        ]);

        return ['ok' => true, 'user_id' => $userId, 'token' => $fresh];
    }

    /** Unieważnienie jednego urządzenia. Zwraca true, gdy coś faktycznie zniknęło. */
    public static function forget(int $tokenId, int $userId): bool
    {
        // Warunek na `user_id` w zapytaniu, nie w PHP — bez niego numer z adresu
        // pozwalałby wylogować urządzenie cudzego konta.
        $gone = Db::run(
            'DELETE FROM remember_tokens WHERE id = :id AND user_id = :uid',
            ['id' => $tokenId, 'uid' => $userId]
        )->rowCount() === 1;

        if ($gone) {
            Audit::log('remember.forget', $userId, 'remember_token', $tokenId);
        }
        return $gone;
    }

    /**
     * Unieważnienie WSZYSTKICH tokenów użytkownika.
     *
     * Wołane przy wylogowaniu, przy zmianie hasła i z przycisku „Wyloguj wszędzie".
     * To jedyny sposób odzyskania kontroli po zgubieniu urządzenia.
     */
    public static function forgetAll(int $userId, string $reason = 'manual'): int
    {
        $count = Db::run(
            'DELETE FROM remember_tokens WHERE user_id = :uid',
            ['uid' => $userId]
        )->rowCount();

        if ($count > 0) {
            Audit::log('remember.forget_all', $userId, 'user', $userId, [
                'count'  => $count,
                'reason' => $reason,
            ]);
        }
        return $count;
    }

    /**
     * Aktywne urządzenia użytkownika.
     *
     * @return list<array<string,mixed>>
     */
    public static function devices(int $userId): array
    {
        $rows = Db::all(
            'SELECT id, device_label, created_at, last_used_at, expires_at, user_agent_hash
               FROM remember_tokens
              WHERE user_id = :uid AND expires_at > :now
              ORDER BY (last_used_at IS NULL), last_used_at DESC, id DESC',
            ['uid' => $userId, 'now' => Stats::now()]
        );

        $obecne = self::userAgentHash();
        foreach ($rows as &$row) {
            // Rozpoznanie „to urządzenie" to jedyne, do czego skrót się nadaje:
            // porównać można, odtworzyć nie.
            $row['is_current'] = $obecne !== null && hash_equals((string) $row['user_agent_hash'], $obecne);
            $row['label'] = $row['device_label'] !== null && $row['device_label'] !== ''
                ? (string) $row['device_label']
                : View::t('device.unknown');
        }
        return $rows;
    }

    /** Sprzątanie wygasłych — wołane z nadzorcy. */
    public static function purgeExpired(): int
    {
        return Db::run(
            'DELETE FROM remember_tokens WHERE expires_at <= :now',
            ['now' => Stats::now()]
        )->rowCount();
    }

    // ------------------------------------------------------------------ pomocnicze

    /**
     * SHA-256, nie argon2id — patrz komentarz w migracji 004. Token ma 256 bitów
     * z CSPRNG, więc nie ma czego spowalniać, a skrót musi dać się wyszukać indeksem.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function userAgentHash(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return $ua === '' ? null : hash('sha256', $ua);
    }

    public static function ipHash(): ?string
    {
        $ip = Audit::clientIp();
        return $ip === null ? null : hash('sha256', $ip);
    }

    /**
     * Zgrubna etykieta urządzenia — „Chrome · macOS".
     *
     * CELOWO bez numerów wersji: właścicielowi wystarczy do rozpoznania własnego
     * sprzętu, a wyciek tabeli nie ujawnia, która dokładnie wersja przeglądarki
     * chodzi na tym komputerze.
     */
    public static function deviceLabel(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '') {
            return View::t('device.unknown');
        }

        $przegladarka = match (true) {
            str_contains($ua, 'Firefox/')                        => 'Firefox',
            str_contains($ua, 'Edg/')                            => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/')                         => 'Chrome',
            str_contains($ua, 'Safari/')                         => 'Safari',
            default                                              => View::t('device.browser'),
        };

        $system = match (true) {
            str_contains($ua, 'Android')                         => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Windows')                         => 'Windows',
            str_contains($ua, 'Linux')                           => 'Linux',
            default                                              => View::t('device.system'),
        };

        return $przegladarka . ' · ' . $system;
    }
}
