<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Notatnik: notatki na trzech poziomach — mecz, klub, zdarzenie.
 *
 * Poziom `event` wskazuje konkretne zdarzenie w raporcie przez `event_ref`.
 * Dzięki temu uwaga „tu ustawienie się rozpadło" zostaje przy TYM zagraniu,
 * a nie w ogólnej notatce do meczu, w której nikt jej potem nie znajdzie.
 *
 * Wyszukiwanie korzysta z indeksu FULLTEXT z migracji 001, ale MUSI działać
 * też bez niego: SQLite go nie ma, a MariaDB nie zwróci krótkich słów (poniżej
 * `ft_min_word_len`). Dlatego zapytanie pełnotekstowe ma zapasową ścieżkę LIKE.
 */
final class Notes
{
    public const SCOPES = ['match', 'club', 'event'];

    /** Kategorie są stałą listą — swobodne wpisywanie rozjeżdża je po literówkach. */
    public const CATEGORIES = ['taktyka', 'zawodnik', 'organizacja', 'przeciwnik', 'inne'];

    /** @param array<string,mixed> $data */
    public static function create(int $ownerId, array $data): int
    {
        $scope = in_array($data['scope'] ?? '', self::SCOPES, true) ? $data['scope'] : 'match';

        /*
         * KLUB NOTATKI — podany wprost albo wywiedziony z meczu.
         *
         * Do migracji 012 `club_id` wypełniały wyłącznie notatki o zasięgu
         * klubowym; meczowe i zdarzeniowe zostawiały je puste, choć klub był
         * znany przez mecz. Backfill w 012 uzupełnił historię, a to miejsce
         * pilnuje, żeby nowe notatki nie odtwarzały starej dziury — inaczej
         * lista notatek klubu gubiłaby wszystko, co powstało po migracji.
         *
         * Wcześniej stał tu warunek, którego obie gałęzie robiły to samo.
         */
        $clubId = $data['club_id'] ?? null;
        $matchId = $scope === 'club' ? null : ($data['match_id'] ?? null);

        if ($clubId === null && $matchId !== null) {
            $mecz = Db::one('SELECT club_id FROM matches WHERE id = :id', ['id' => (int) $matchId]);
            $clubId = $mecz === null ? null : ($mecz['club_id'] ?? null);
        }

        Db::run(
            'INSERT INTO notes (owner_id, scope, match_id, club_id, event_ref, title, body,
                                tags_json, created_at)
             VALUES (:owner, :scope, :match, :club, :event, :title, :body, :tags, :now)',
            [
                'owner' => $ownerId,
                'scope' => $scope,
                'match' => $matchId,
                'club'  => $clubId,
                'event' => $scope === 'event' ? ($data['event_ref'] ?? null) : null,
                'title' => $data['title'] !== '' ? $data['title'] : null,
                'body'  => $data['body'],
                'tags'  => self::encodeTags($data['tags'] ?? []),
                'now'   => Stats::now(),
            ]
        );

        $id = (int) Db::pdo()->lastInsertId();
        Audit::log('note.created', $ownerId, 'note', $id, ['scope' => $scope]);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, int $userId, array $data): void
    {
        Db::run(
            'UPDATE notes SET title = :title, body = :body, tags_json = :tags, updated_at = :now
              WHERE id = :id',
            [
                'title' => $data['title'] !== '' ? $data['title'] : null,
                'body'  => $data['body'],
                'tags'  => self::encodeTags($data['tags'] ?? []),
                'now'   => Stats::now(),
                'id'    => $id,
            ]
        );
        Audit::log('note.updated', $userId, 'note', $id);
    }

    public static function delete(int $id, int $userId): void
    {
        Db::run('DELETE FROM notes WHERE id = :id', ['id' => $id]);
        Audit::log('note.deleted', $userId, 'note', $id);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Db::one('SELECT * FROM notes WHERE id = :id', ['id' => $id]);
        return $row === null ? null : self::decorate($row);
    }

    /**
     * Notatki przypięte do meczu — wraz z tymi przypiętymi do jego zdarzeń.
     *
     * @return list<array<string,mixed>>
     */
    public static function forMatch(int $matchId): array
    {
        return array_map(
            [self::class, 'decorate'],
            Db::all(
                'SELECT * FROM notes WHERE match_id = :id ORDER BY created_at DESC, id DESC',
                ['id' => $matchId]
            )
        );
    }

    /** @return list<array<string,mixed>> */
    public static function forClub(int $clubId): array
    {
        return array_map(
            [self::class, 'decorate'],
            Db::all(
                'SELECT * FROM notes WHERE club_id = :id ORDER BY created_at DESC, id DESC',
                ['id' => $clubId]
            )
        );
    }

    /**
     * Notatki przypięte do KONKRETNEGO zdarzenia — do pokazania w kontekście raportu.
     *
     * @return list<array<string,mixed>>
     */
    public static function forEvent(int $matchId, string $eventRef): array
    {
        return array_map(
            [self::class, 'decorate'],
            Db::all(
                "SELECT * FROM notes
                  WHERE scope = 'event' AND match_id = :id AND event_ref = :ref
                  ORDER BY created_at DESC",
                ['id' => $matchId, 'ref' => $eventRef]
            )
        );
    }

    /**
     * Wyszukiwanie po treści i po tagach.
     *
     * FULLTEXT jest szybszy, ale nie znajdzie słów krótszych niż `ft_min_word_len`
     * (domyślnie 4 znaki w MariaDB) i nie istnieje w SQLite. Fraza „SBZ" ma trzy
     * znaki i jest w tym projekcie codziennym słowem — wyszukiwarka, która jej
     * nie znajduje, jest bezużyteczna. Dlatego ścieżką podstawową jest LIKE,
     * a FULLTEXT dokłada trafienia tam, gdzie jest dostępny.
     *
     * @return list<array<string,mixed>>
     */
    public static function search(
        string $query,
        ?string $scope = null,
        ?string $tag = null,
        ?int $tenant = null
    ): array {
        $where = [];
        $params = [];

        /*
         * TENANT — klub, do którego notatka należy. Kolumna `club_id` istnieje
         * od migracji 001, ale do migracji 012 była wypełniana WYŁĄCZNIE dla
         * notatek o zasięgu klubowym; notatki meczowe i zdarzeniowe miały ją
         * pustą, choć klub dało się wywieść z meczu. Backfill w 012 to
         * ujednolicił i od teraz filtr obejmuje wszystkie trzy zasięgi.
         *
         * TODO(club-scope): wyszukiwarka notatek w panelu woła to bez tenanta —
         * kontekst klubu wchodzi w Sesji 2 i wtedy parametr przestaje być
         * opcjonalny.
         */
        if ($tenant !== null) {
            $where[] = 'n.club_id = :tenant';
            $params['tenant'] = $tenant;
        }

        $query = trim($query);
        if ($query !== '') {
            // Trzy osobne symbole na to samo wyrażenie — MySQL przy natywnym
            // przygotowaniu zapytania nie pozwala powtórzyć symbolu
            // („Invalid parameter number"). Patrz `app/tests/test_sql_parametry.php`.
            $where[] = '(n.title LIKE :q_t OR n.body LIKE :q_b OR n.tags_json LIKE :q_g)';
            // Znaki wieloznaczne LIKE muszą być zneutralizowane, inaczej `%`
            // wpisany w wyszukiwarkę zwraca wszystko.
            $wzorzec = '%' . self::escapeLike($query) . '%';
            $params['q_t'] = $wzorzec;
            $params['q_b'] = $wzorzec;
            $params['q_g'] = $wzorzec;
        }

        if ($scope !== null && in_array($scope, self::SCOPES, true)) {
            $where[] = 'n.scope = :scope';
            $params['scope'] = $scope;
        }

        if ($tag !== null && $tag !== '') {
            // Tag szukany jako CAŁA wartość w tablicy JSON, nie jako fragment:
            // „press" nie może się dopasować do „pressing".
            $where[] = 'n.tags_json LIKE :tag';
            $params['tag'] = '%"' . self::escapeLike($tag) . '"%';
        }

        $sql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        return array_map(
            [self::class, 'decorate'],
            Db::all(
                'SELECT n.*, m.played_at, c.name AS club_name
                   FROM notes n
                   LEFT JOIN matches m ON m.id = n.match_id
                   LEFT JOIN clubs c   ON c.id = n.club_id'
                . $sql . ' ORDER BY n.created_at DESC, n.id DESC LIMIT :limit',
                $params + ['limit' => 100]
            )
        );
    }

    /** Wszystkie użyte tagi wraz z liczbą wystąpień — do podpowiedzi i filtra. */
    public static function tagCloud(): array
    {
        $liczniki = [];
        foreach (Db::all('SELECT tags_json FROM notes WHERE tags_json IS NOT NULL') as $row) {
            foreach (self::decodeTags($row['tags_json']) as $tag) {
                $liczniki[$tag] = ($liczniki[$tag] ?? 0) + 1;
            }
        }
        arsort($liczniki);
        return $liczniki;
    }

    /** @param array<string,mixed> $row */
    private static function decorate(array $row): array
    {
        $row['tags'] = self::decodeTags($row['tags_json'] ?? null);
        return $row;
    }

    /** @return list<string> */
    public static function decodeTags(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /** @param list<string>|string $tags */
    public static function encodeTags(array|string $tags): ?string
    {
        if (is_string($tags)) {
            // Z formularza przychodzą rozdzielone przecinkiem.
            $tags = explode(',', $tags);
        }
        $clean = [];
        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim((string) $tag), 'UTF-8');
            if ($tag !== '' && !in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }
        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** Neutralizacja znaków wieloznacznych LIKE. */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
