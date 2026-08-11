<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Biblioteka meczów: filtrowanie, sortowanie, stronicowanie.
 *
 * ZASADA: żaden fragment zapytania nie powstaje z danych żądania. Wartości idą
 * parametrami, a kierunek i kolumna sortowania pochodzą z ZAMKNIĘTEJ LISTY —
 * `ORDER BY` i `LIMIT` nie przyjmują parametrów w SQL, więc jedyną bezpieczną
 * drogą jest wybór z mapy, nie sklejanie napisu.
 */
final class Matches
{
    public const PER_PAGE = 20;

    /**
     * Dozwolone sortowania. Klucz przychodzi z adresu, wartość jest wpisana
     * tutaj — wartość z żądania nigdy nie trafia do zapytania.
     */
    private const SORTS = [
        'data_desc'   => 'ORDER BY (m.played_at IS NULL), m.played_at DESC, m.id DESC',
        'data_asc'    => 'ORDER BY (m.played_at IS NULL), m.played_at ASC, m.id ASC',
        'status_asc'  => "ORDER BY CASE m.status WHEN 'failed' THEN 0 WHEN 'running' THEN 1"
                       . " WHEN 'queued' THEN 2 WHEN 'draft' THEN 3 ELSE 4 END, m.played_at DESC",
        'status_desc' => "ORDER BY CASE m.status WHEN 'done' THEN 0 WHEN 'draft' THEN 1"
                       . " WHEN 'queued' THEN 2 WHEN 'running' THEN 3 ELSE 4 END, m.played_at DESC",
    ];

    public static function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    public static function normalizeSort(?string $sort): string
    {
        return isset(self::SORTS[$sort]) ? (string) $sort : 'data_desc';
    }

    /**
     * @param array{club?:int|null, season?:int|null, sort?:string|null, page?:int} $filters
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int, per_page:int}
     */
    public static function search(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['club'])) {
            // Klub po dowolnej stronie — operator myśli „mecze Hutnika",
            // a nie „mecze, w których Hutnik był stroną `us`".
            $where[] = '(m.club_home_id = :club OR m.club_away_id = :club)';
            $params['club'] = (int) $filters['club'];
        }

        if (!empty($filters['season'])) {
            $where[] = 'm.season_id = :season';
            $params['season'] = (int) $filters['season'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'm.status = :status';
            $params['status'] = (string) $filters['status'];
        }

        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Db::one(
            'SELECT COUNT(*) AS c FROM matches m' . $sql,
            $params
        )['c'];

        $perPage = self::PER_PAGE;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, (int) ($filters['page'] ?? 1)));

        $order = self::SORTS[self::normalizeSort($filters['sort'] ?? null)];

        $rows = Db::all(
            'SELECT m.id, m.played_at, m.status, m.competition, m.season_id,
                    h.name AS home_name, a.name AS away_name,
                    s.label AS season_label,
                    (SELECT COUNT(*) FROM reports r WHERE r.match_id = m.id) AS reports_count,
                    (SELECT i.id FROM imports i WHERE i.match_id = m.id ORDER BY i.id DESC LIMIT 1) AS import_id
               FROM matches m
               LEFT JOIN clubs h   ON h.id = m.club_home_id
               LEFT JOIN clubs a   ON a.id = m.club_away_id
               LEFT JOIN seasons s ON s.id = m.season_id'
            . $sql . ' ' . $order . ' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage]
        );

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT m.*, h.name AS home_name, a.name AS away_name, s.label AS season_label
               FROM matches m
               LEFT JOIN clubs h   ON h.id = m.club_home_id
               LEFT JOIN clubs a   ON a.id = m.club_away_id
               LEFT JOIN seasons s ON s.id = m.season_id
              WHERE m.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Ustawienie daty meczu wraz z wykryciem sezonu.
     *
     * Sezon nadpisujemy tylko wtedy, gdy mecz go jeszcze nie ma — ręczne
     * przypisanie przez operatora jest ważniejsze niż wykrywanie z daty.
     */
    public static function setDate(int $matchId, ?string $date, int $userId): void
    {
        $match = self::find($matchId);
        if ($match === null) {
            return;
        }

        $seasonId = $match['season_id'] !== null
            ? (int) $match['season_id']
            : Seasons::detect($date, $userId);

        Db::run('UPDATE matches SET played_at = :d, season_id = :s WHERE id = :id', [
            'd'  => $date !== '' ? $date : null,
            's'  => $seasonId,
            'id' => $matchId,
        ]);
        Audit::log('match.updated', $userId, 'match', $matchId, ['played_at' => $date]);
    }

    public static function setSeason(int $matchId, ?int $seasonId, int $userId): void
    {
        Db::run('UPDATE matches SET season_id = :s WHERE id = :id', [
            's'  => $seasonId,
            'id' => $matchId,
        ]);
        Audit::log('match.season', $userId, 'match', $matchId, ['season_id' => $seasonId]);
    }
}
