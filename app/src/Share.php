<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Publiczne linki do raportów: /r/{club_key}/{token}
 *
 * DWIE ZASADY, KTÓRYCH NIE WOLNO ROZLUŹNIĆ:
 *
 * 1. Przy złym `club_key` i przy złym `token` odpowiedź musi być IDENTYCZNA —
 *    to samo 404 i porównywalny czas. Inaczej klucz klubu da się wysondować:
 *    pytanie zadane tysiąc razy pokazuje, przy którym kluczu serwer „myśli
 *    dłużej", a to wystarczy, żeby ustalić istniejący klub.
 *
 * 2. Token ma co najmniej 128 bitów z CSPRNG. Adres jest jedyną ochroną
 *    raportu — nie ma za nim sesji ani hasła.
 */
final class Share
{
    /** 16 bajtów = 128 bitów. Zapis szesnastkowy daje 32 znaki, tyle ma kolumna. */
    private const TOKEN_BYTES = 16;

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Nowy link do raportu. Poprzednie linki zostają — odwołanie jest osobną
     * decyzją, żeby wygenerowanie nowego adresu nie unieważniało po cichu
     * tego, który klient właśnie komuś wysłał.
     */
    public static function create(int $reportId, int $clubId, ?string $expiresAt, int $userId): string
    {
        $token = self::generateToken();

        Db::run(
            'INSERT INTO share_links (report_id, club_id, token, created_at, expires_at)
             VALUES (:report, :club, :token, :now, :expires)',
            [
                'report'  => $reportId,
                'club'    => $clubId,
                'token'   => $token,
                'now'     => Stats::now(),
                'expires' => $expiresAt !== null && $expiresAt !== '' ? $expiresAt : null,
            ]
        );

        Audit::log('share.created', $userId, 'report', $reportId, ['club_id' => $clubId]);
        return $token;
    }

    /**
     * Rozwiązanie pary (club_key, token) na raport.
     *
     * Jedno zapytanie z OBOMA warunkami. Sprawdzanie po kolei — najpierw klubu,
     * potem tokenu — dawałoby dwie różne ścieżki wykonania i mierzalną różnicę
     * czasu między „zły klub" a „zły token".
     *
     * @return array<string,mixed>|null
     */
    public static function resolve(string $clubKey, string $token): ?array
    {
        // Odsiewamy wartości spoza formatu, ale BEZ wcześniejszego zwrotu —
        // wynik i tak przechodzi tą samą drogą co poprawny.
        //
        // Filtr jest CELOWO luźniejszy niż alfabet generatora: to tylko tania
        // sanityzacja długości i zestawu znaków, a rozstrzyga i tak porównanie
        // w bazie. Filtr węższy od kolumny zablokowałby klucze nadane wcześniej,
        // według innego alfabetu — a `club_key` jest niezmienny i stoi w adresach,
        // które klub ma już rozesłane.
        $token = preg_match('/^[0-9a-f]{32}$/', $token) === 1 ? $token : str_repeat('0', 32);
        $clubKey = preg_match('/^[A-Z0-9]{8,10}$/', $clubKey) === 1 ? $clubKey : 'XXXXXXXXXX';

        return Db::one(
            'SELECT sl.id, sl.token, sl.expires_at, sl.revoked_at, sl.views,
                    r.id AS report_id, r.html_path, r.generated_at,
                    c.id AS club_id, c.name AS club_name
               FROM share_links sl
               JOIN reports r ON r.id = sl.report_id
               JOIN clubs   c ON c.id = sl.club_id
              WHERE c.club_key = :key AND sl.token = :token',
            ['key' => $clubKey, 'token' => $token]
        );
    }

    /** Czy link nadaje się do wyświetlenia. Odwołany i wygasły traktujemy tak samo. */
    public static function isUsable(?array $link): bool
    {
        if ($link === null) {
            return false;
        }
        if ($link['revoked_at'] !== null) {
            return false;
        }
        if ($link['expires_at'] !== null && (string) $link['expires_at'] < Stats::now()) {
            return false;
        }
        return true;
    }

    /** Licznik wejść i data ostatniego. Zapis nie może wywrócić wyświetlenia raportu. */
    public static function registerView(int $linkId): void
    {
        try {
            Db::run(
                'UPDATE share_links SET views = views + 1, last_viewed_at = :now WHERE id = :id',
                ['now' => Stats::now(), 'id' => $linkId]
            );
        } catch (\Throwable $e) {
            error_log('share: nie udało się zapisać wejścia: ' . $e->getMessage());
        }
    }

    public static function revoke(int $linkId, int $userId): bool
    {
        $changed = Db::run(
            'UPDATE share_links SET revoked_at = :now WHERE id = :id AND revoked_at IS NULL',
            ['now' => Stats::now(), 'id' => $linkId]
        )->rowCount() === 1;

        if ($changed) {
            Audit::log('share.revoked', $userId, 'share_link', $linkId);
        }
        return $changed;
    }

    /**
     * Linki dla raportu, wraz ze stanem. Kolumna `stan` liczona w PHP, a nie
     * w SQL — to prezentacja, nie dane.
     *
     * @return list<array<string,mixed>>
     */
    public static function forReport(int $reportId): array
    {
        $rows = Db::all(
            'SELECT sl.*, c.club_key, c.name AS club_name
               FROM share_links sl
               JOIN clubs c ON c.id = sl.club_id
              WHERE sl.report_id = :id
              ORDER BY sl.id DESC',
            ['id' => $reportId]
        );

        foreach ($rows as &$row) {
            $row['stan'] = match (true) {
                $row['revoked_at'] !== null => 'revoked',
                $row['expires_at'] !== null && (string) $row['expires_at'] < Stats::now() => 'expired',
                default => 'active',
            };
            $row['url'] = '/r/' . $row['club_key'] . '/' . $row['token'];
        }
        return $rows;
    }

    /** Wszystkie aktywne linki — do przeglądu w panelu. */
    public static function active(): array
    {
        $rows = Db::all(
            'SELECT sl.*, c.club_key, c.name AS club_name, r.generated_at, r.match_id
               FROM share_links sl
               JOIN clubs c   ON c.id = sl.club_id
               JOIN reports r ON r.id = sl.report_id
              WHERE sl.revoked_at IS NULL AND (sl.expires_at IS NULL OR sl.expires_at > :now)
              ORDER BY sl.id DESC',
            ['now' => Stats::now()]
        );

        foreach ($rows as &$row) {
            $row['url'] = '/r/' . $row['club_key'] . '/' . $row['token'];
        }
        return $rows;
    }

    /**
     * Klub, do którego przypisujemy link raportu.
     *
     * Bierzemy klub „nasz" z meczu — to jego klucz stoi w adresie i to on
     * dostaje raport. Gdy mecz nie ma przypisanego klubu, linku nie da się
     * utworzyć i mówimy o tym wprost, zamiast wstawiać przypadkowy.
     */
    public static function clubForReport(int $reportId): ?int
    {
        $row = Db::one(
            'SELECT m.club_home_id, m.club_away_id
               FROM reports r JOIN matches m ON m.id = r.match_id
              WHERE r.id = :id',
            ['id' => $reportId]
        );
        if ($row === null) {
            return null;
        }
        $id = $row['club_home_id'] ?? $row['club_away_id'];
        return $id !== null ? (int) $id : null;
    }
}
