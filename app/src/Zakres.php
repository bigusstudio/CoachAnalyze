<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Zakres danych zalogowanego — które kluby wolno mu oglądać (golden layout W2).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TRZY ROLE, BEZ NOWEJ KOLUMNY ROLI (`users.role`, migracja 001):
 *
 *   admin    = Administrator — wszystkie kluby i rzeczy [op],
 *   operator = Analityk      — kluby, których `clubs.owner_id` = jego id,
 *   viewer   = Trener        — DOKŁADNIE jeden klub z `users.club_id` (018).
 *
 * TO JEST KONTROLA DOSTĘPU, NIE MENU. Każda trasa z identyfikatorem zasobu
 * przechodzi przez `moze()` w routerze, a listy filtrują zapytaniem po
 * `dozwoloneKluby()`. Cudzy zasób daje TO SAMO 404 co nieistniejący —
 * 403 potwierdzałoby, że mecz o tym numerze istnieje w innym klubie.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class Zakres
{
    private const KLUCZ_SESJI = 'klub_biezacy';

    /** Administrator — rzeczy [op]. */
    public static function op(?array $user): bool
    {
        return Users::isAdmin($user);
    }

    public static function trener(?array $user): bool
    {
        return (string) ($user['role'] ?? '') === 'viewer';
    }

    /**
     * Identyfikatory klubów-tenantów w zasięgu. `null` = bez ograniczeń (admin).
     *
     * @return list<int>|null
     */
    public static function dozwoloneKluby(?array $user): ?array
    {
        if ($user === null) {
            return [];
        }
        if (self::op($user)) {
            return null;
        }
        if (self::trener($user)) {
            $klub = (int) ($user['club_id'] ?? 0);
            return $klub > 0 ? [$klub] : [];
        }
        return array_map('intval', array_column(Db::all(
            'SELECT id FROM clubs WHERE owner_id = :u AND is_own_team = 1 ORDER BY id',
            ['u' => (int) $user['id']]
        ), 'id'));
    }

    public static function moze(?array $user, ?int $clubId): bool
    {
        $dozwolone = self::dozwoloneKluby($user);
        if ($dozwolone === null) {
            return true;
        }
        return $clubId !== null && in_array($clubId, $dozwolone, true);
    }

    /**
     * Klub, którego dane widać teraz: trener — jego klub; pozostali — wybrany
     * w szynie (sesja), a bez wyboru pierwszy z zasięgu (u administratora —
     * klub własny domyślny, jak dotąd).
     *
     * @return array<string,mixed>|null
     */
    public static function biezacy(?array $user): ?array
    {
        $dozwolone = self::dozwoloneKluby($user);
        $wybrany = (int) (Session::get(self::KLUCZ_SESJI) ?? 0);

        if ($wybrany > 0 && ($dozwolone === null || in_array($wybrany, $dozwolone, true))) {
            $klub = Clubs::find($wybrany);
            if ($klub !== null && !empty($klub['is_own_team'])) {
                return $klub;
            }
        }
        $id = $dozwolone === null ? Clubs::tenantDefault() : ($dozwolone[0] ?? null);
        return $id !== null ? Clubs::find($id) : null;
    }

    /** Zapamiętanie wyboru klubu — wyłącznie w zasięgu. */
    public static function wybierz(?array $user, int $clubId): bool
    {
        if (!self::moze($user, $clubId) || self::trener($user)) {
            return false;
        }
        Session::set(self::KLUCZ_SESJI, $clubId);
        return true;
    }

    /**
     * Klub-tenant zasobu: meczu, raportu, importu, linku, notatki, zadania.
     * `null`, gdy zasobu nie ma — router odpowiada wtedy 404 tak samo jak
     * przy cudzym, bez rozróżniania.
     */
    public static function klubZasobu(string $rodzaj, int $id): ?int
    {
        $sql = match ($rodzaj) {
            'mecz'    => 'SELECT club_id AS k FROM matches WHERE id = :id',
            'raport'  => 'SELECT COALESCE(r.club_id, m.club_id) AS k FROM reports r
                            LEFT JOIN matches m ON m.id = r.match_id WHERE r.id = :id',
            'import'  => 'SELECT m.club_id AS k FROM imports i JOIN matches m ON m.id = i.match_id WHERE i.id = :id',
            'link'    => 'SELECT club_id AS k FROM share_links WHERE id = :id',
            'notatka' => 'SELECT COALESCE(n.club_id, m.club_id) AS k FROM notes n
                            LEFT JOIN matches m ON m.id = n.match_id WHERE n.id = :id',
            'klub'    => 'SELECT id AS k FROM clubs WHERE id = :id',
            default   => null,
        };
        if ($sql === null) {
            return null;
        }
        $w = Db::one($sql, ['id' => $id]);
        return $w !== null && $w['k'] !== null ? (int) $w['k'] : null;
    }

    /**
     * Fragment SQL `kolumna IN (…)` z parametrami — do list. Administrator
     * dostaje pusty warunek; rola bez klubów — warunek zawsze fałszywy.
     *
     * @return array{0:string,1:array<string,int>}
     */
    public static function warunek(?array $user, string $kolumna, string $prefiks = 'zk'): array
    {
        $dozwolone = self::dozwoloneKluby($user);
        if ($dozwolone === null) {
            return ['1 = 1', []];
        }
        if ($dozwolone === []) {
            return ['1 = 0', []];
        }
        $p = [];
        foreach ($dozwolone as $i => $id) {
            $p[$prefiks . $i] = $id;
        }
        return [$kolumna . ' IN (' . implode(', ', array_map(static fn($k) => ':' . $k, array_keys($p))) . ')', $p];
    }
}
