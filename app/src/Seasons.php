<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Sezony rozgrywkowe.
 *
 * Sezon polski biegnie od LIPCA do CZERWCA — mecz z sierpnia 2026 i mecz z maja
 * 2027 należą do tego samego sezonu „2026/2027". Rok kalendarzowy dałby tu złą
 * odpowiedź dla całej rundy wiosennej, czyli dla połowy sezonu.
 */
final class Seasons
{
    /** Miesiąc, od którego zaczyna się sezon. Lipiec. */
    private const START_MONTH = 7;

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Db::all(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM matches m WHERE m.season_id = s.id) AS matches_count
               FROM seasons s
              ORDER BY s.date_from DESC'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM seasons WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array
    {
        return Db::one('SELECT * FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    }

    /**
     * Granice sezonu, do którego należy podana data.
     *
     * @return array{label:string, date_from:string, date_to:string}
     */
    public static function boundsFor(string $date): array
    {
        $stamp = new \DateTimeImmutable($date);
        $year = (int) $stamp->format('Y');
        $month = (int) $stamp->format('n');

        // Styczeń–czerwiec to druga połowa sezonu, który zaczął się rok wcześniej.
        $startYear = $month >= self::START_MONTH ? $year : $year - 1;

        return [
            'label'     => sprintf('%d/%d', $startYear, $startYear + 1),
            'date_from' => sprintf('%d-07-01', $startYear),
            'date_to'   => sprintf('%d-06-30', $startYear + 1),
        ];
    }

    /**
     * Sezon dla daty meczu — istniejący albo nowo utworzony.
     *
     * Wykrywanie jest po to, żeby operator nie zakładał sezonu ręcznie przy
     * pierwszym imporcie po wakacjach. Dopasowujemy po ZAKRESIE DAT, nie po
     * etykiecie: etykieta jest tekstem i klub może ją sobie nazwać inaczej.
     */
    public static function detect(?string $date, ?int $userId = null): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }

        $bounds = self::boundsFor($date);

        $existing = Db::one(
            // Dwa symbole na tę samą datę — patrz komentarz w Reports::search().
            'SELECT id FROM seasons WHERE date_from <= :d_od AND date_to >= :d_do ORDER BY id LIMIT 1',
            ['d_od' => substr($date, 0, 10), 'd_do' => substr($date, 0, 10)]
        );
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        Db::run(
            'INSERT INTO seasons (owner_id, label, date_from, date_to, is_current)
             VALUES (:owner, :label, :from, :to, 0)',
            [
                'owner' => $userId ?? 1,
                'label' => $bounds['label'],
                'from'  => $bounds['date_from'],
                'to'    => $bounds['date_to'],
            ]
        );
        $id = (int) Db::pdo()->lastInsertId();
        Audit::log('season.detected', $userId, 'season', $id, ['label' => $bounds['label']]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function create(int $ownerId, array $data): int
    {
        Db::run(
            'INSERT INTO seasons (owner_id, label, date_from, date_to, is_current)
             VALUES (:owner, :label, :from, :to, 0)',
            [
                'owner' => $ownerId,
                'label' => $data['label'],
                'from'  => $data['date_from'],
                'to'    => $data['date_to'],
            ]
        );
        $id = (int) Db::pdo()->lastInsertId();

        if (!empty($data['is_current'])) {
            self::markCurrent($id, $ownerId);
        }
        Audit::log('season.created', $ownerId, 'season', $id, ['label' => $data['label']]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, int $userId, array $data): void
    {
        Db::run(
            'UPDATE seasons SET label = :label, date_from = :from, date_to = :to WHERE id = :id',
            [
                'label' => $data['label'],
                'from'  => $data['date_from'],
                'to'    => $data['date_to'],
                'id'    => $id,
            ]
        );

        if (!empty($data['is_current'])) {
            self::markCurrent($id, $userId);
        } else {
            Db::run('UPDATE seasons SET is_current = 0 WHERE id = :id', ['id' => $id]);
        }
        Audit::log('season.updated', $userId, 'season', $id);
    }

    /** Bieżący sezon jest dokładnie jeden — ustawienie nowego zdejmuje znacznik z pozostałych. */
    public static function markCurrent(int $id, ?int $userId = null): void
    {
        Db::run('UPDATE seasons SET is_current = 0 WHERE id <> :id', ['id' => $id]);
        Db::run('UPDATE seasons SET is_current = 1 WHERE id = :id', ['id' => $id]);
        Audit::log('season.current', $userId, 'season', $id);
    }

    /**
     * Usunięcie sezonu, do którego przypisano mecze, zabrałoby im przynależność.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function delete(int $id, int $userId): array
    {
        $used = (int) Db::one(
            'SELECT COUNT(*) AS c FROM matches WHERE season_id = :id',
            ['id' => $id]
        )['c'];

        if ($used > 0) {
            return ['ok' => false, 'error' => 'season.err.in_use'];
        }

        Db::run('DELETE FROM seasons WHERE id = :id', ['id' => $id]);
        Audit::log('season.deleted', $userId, 'season', $id);
        return ['ok' => true];
    }
}
