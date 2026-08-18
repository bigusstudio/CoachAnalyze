<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Kluby: dane, barwy, herb, `club_key`.
 *
 * Konfiguracja klubu jest zapisana raz i używana przy KAŻDYM kolejnym meczu
 * tego klubu — operator nie wpisuje nazw i barw przy każdym imporcie.
 * Dopasowanie do eksportu idzie przez `aliases_json`: nazwy, pod jakimi klub
 * występuje w plikach z LiveTag.
 */
final class Clubs
{
    /**
     * Alfabet base32 bez znaków, które mylą się przy przepisywaniu z kartki
     * albo dyktowaniu przez telefon: brak 0/O, 1/I/L, U.
     * `club_key` bywa czytany na głos — to nie jest teoretyczny problem.
     */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const KEY_LENGTH = 10;

    /**
     * Klucz klubu: losowy, stały, unikalny. Generowany RAZ, przy tworzeniu.
     *
     * Nigdy nie zmieniamy go później — stoi w publicznych adresach raportów
     * `/r/{club_key}/{token}`, a zmiana unieważniłaby linki wysłane do klubu.
     * Odwoływalny jest `token`, nie klucz.
     */
    public static function generateKey(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $key = '';
            for ($i = 0; $i < self::KEY_LENGTH; $i++) {
                $key .= self::ALPHABET[random_int(0, $max)];
            }
            if (Db::one('SELECT id FROM clubs WHERE club_key = :k', ['k' => $key]) === null) {
                return $key;
            }
        }
        // 30^10 możliwości — dziesięć kolizji z rzędu oznacza awarię CSPRNG,
        // a nie pecha. Lepiej przerwać niż wydać klucz, który już istnieje.
        throw new \RuntimeException('Nie udało się wylosować unikalnego club_key.');
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Db::all(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM matches m
                      WHERE m.club_home_id = c.id OR m.club_away_id = c.id) AS matches_count
               FROM clubs c
              ORDER BY c.is_own_team DESC, c.name'
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM clubs WHERE id = :id', ['id' => $id]);
    }

    /**
     * Kluby własne — TENANCI. To one, i wyłącznie one, są „klubem do wyboru"
     * w nawigacji (Sesja 2, docs/PRZEBUDOWA_KLUB_SESJE.md).
     *
     * Rywale (`is_own_team = 0`) ZOSTAJĄ poza tą listą — żyją w warstwie danych
     * jako `club_away_id` meczów i nie mają własnego huba. Nie znikają z systemu:
     * nadal da się do nich dotrzeć przez `/kluby/{id}` (link z pokrycia importu,
     * z listy meczów), tylko nie mają wejścia z głównej nawigacji.
     *
     * Liczniki liczone są PO TENANCIE (`club_id`), nie po stronie boiska
     * (`club_home_id`/`club_away_id`) — zgodnie z regułą z `Matches::search()`:
     * `club_id` mówi, czyja jest analiza, a nie kto grał.
     *
     * @return list<array<string,mixed>>
     */
    public static function tenants(): array
    {
        return Db::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM matches m WHERE m.club_id = c.id) AS matches_count,
                    (SELECT COUNT(*) FROM reports r WHERE r.club_id = c.id) AS reports_count,
                    (SELECT MAX(i.created_at)
                       FROM imports i JOIN matches m2 ON m2.id = i.match_id
                      WHERE m2.club_id = c.id) AS last_import_at
               FROM clubs c
              WHERE c.is_own_team = 1
              ORDER BY c.name"
        );
    }

    /**
     * Domyślny tenant — klub, do którego trafia praca, gdy kontekst klubu nie
     * został jeszcze wybrany w interfejsie.
     *
     * PO CO TO ISTNIEJE: `matches.club_id` jest NOT NULL od migracji 012, a
     * import startuje dziś z ekranu bez wyboru klubu. Zwracamy klub oznaczony
     * jako własna drużyna — w instalacji jednoklubowej to jedyny sensowny
     * właściciel analizy, a taka jest produkcja w chwili tej migracji.
     *
     * Przy kilku klubach własnych rozstrzyga najstarszy, żeby wynik był
     * powtarzalny — losowanie „któregoś" dawałoby mecze rozrzucone po klubach
     * bez żadnej reguły.
     *
     * SESJA 2: `/klub/{id}/import` przekazuje `club_id` wprost z adresu i NIE
     * woła już tej metody. Zostaje jako domknięcie dla globalnego `/import`
     * (ekran bez wyboru klubu — świadomie zachowany dla ciągłości, patrz
     * `handleImport()`) oraz dla zadań uruchamianych poza kontekstem żądania.
     */
    public static function tenantDefault(): ?int
    {
        $row = Db::one(
            'SELECT id FROM clubs WHERE is_own_team = 1 ORDER BY id LIMIT 1'
        );
        if ($row !== null) {
            return (int) $row['id'];
        }
        // Brak klubu własnego: bierzemy pierwszy istniejący, żeby instalacja bez
        // odhaczonego „nasza drużyna" nie stanęła na imporcie.
        $any = Db::one('SELECT id FROM clubs ORDER BY id LIMIT 1');
        return $any === null ? null : (int) $any['id'];
    }

    /**
     * Klub po kluczu z adresu publicznego.
     *
     * Osobno od `find()`, bo `club_key` jest tym, co widzi świat zewnętrzny,
     * a `id` tym, co widzi baza. Trasy publiczne nie mają prawa przyjmować `id`.
     *
     * @return array<string,mixed>|null
     */
    public static function findByKey(string $clubKey): ?array
    {
        return Db::one('SELECT * FROM clubs WHERE club_key = :k', ['k' => $clubKey]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create(int $ownerId, array $data): int
    {
        Db::run(
            'INSERT INTO clubs (owner_id, club_key, name, short_name, color_primary,
                                color_secondary, crest_path, is_own_team, aliases_json,
                                details, created_at)
             VALUES (:owner, :key, :name, :short, :c1, :c2, :crest, :own, :aliases,
                     :details, :now)',
            [
                'owner'   => $ownerId,
                'key'     => self::generateKey(),
                'name'    => $data['name'],
                'short'   => $data['short_name'] ?: null,
                'c1'      => $data['color_primary'],
                'c2'      => $data['color_secondary'] ?: null,
                'crest'   => $data['crest_path'] ?? null,
                'own'     => !empty($data['is_own_team']) ? 1 : 0,
                'aliases' => self::encodeAliases($data['aliases'] ?? []),
                'details' => self::encodeDetails($data['details'] ?? []),
                'now'     => Stats::now(),
            ]
        );
        $id = (int) Db::pdo()->lastInsertId();
        Audit::log('club.created', $ownerId, 'club', $id, ['name' => $data['name']]);
        return $id;
    }

    /**
     * Aktualizacja. `club_key` NIE jest tu wymieniony i to jest celowe —
     * pola, którego nie da się zmienić, nie ma w formularzu ani w UPDATE.
     *
     * @param array<string,mixed> $data
     */
    public static function update(int $id, int $userId, array $data): void
    {
        Db::run(
            'UPDATE clubs SET name = :name, short_name = :short, color_primary = :c1,
                              color_secondary = :c2, is_own_team = :own, aliases_json = :aliases,
                              updated_at = :now
              WHERE id = :id',
            [
                'name'    => $data['name'],
                'short'   => $data['short_name'] ?: null,
                'c1'      => $data['color_primary'],
                'c2'      => $data['color_secondary'] ?: null,
                'own'     => !empty($data['is_own_team']) ? 1 : 0,
                'aliases' => self::encodeAliases($data['aliases'] ?? []),
                'now'     => Stats::now(),
                'id'      => $id,
            ]
        );
        Audit::log('club.updated', $userId, 'club', $id);
    }

    public static function setCrest(int $id, ?string $path, int $userId): void
    {
        Db::run('UPDATE clubs SET crest_path = :p WHERE id = :id', ['p' => $path, 'id' => $id]);
        Audit::log('club.crest', $userId, 'club', $id);
    }

    /**
     * Pola opisowe klubu (miasto, liga, sezon bieżący).
     *
     * OSOBNA METODA, nie kolejne pole w `update()`, i to jest ta sama decyzja
     * co przy `setCrest()`. Gdyby `details` siedziały w `update()`, każdy
     * formularz, który ich nie przesyła — a dziś nie przesyła ich żaden —
     * kasowałby je przy zapisie nazwy albo barw. Pole, którego nie ma
     * w formularzu, nie ma prawa zniknąć z bazy przy zapisie tego formularza.
     *
     * @param array<string,mixed> $details
     */
    public static function setDetails(int $id, array $details, int $userId): void
    {
        Db::run(
            'UPDATE clubs SET details = :d, updated_at = :now WHERE id = :id',
            ['d' => self::encodeDetails($details), 'now' => Stats::now(), 'id' => $id]
        );
        Audit::log('club.details', $userId, 'club', $id);
    }

    /**
     * Usunięcie klubu użytego w meczu zerwałoby klucz obcy i zabrałoby raportom
     * nazwy drużyn. Zamiast łapać błąd bazy, mówimy o tym wprost.
     *
     * @return array{ok:bool, error?:string}
     */
    public static function delete(int $id, int $userId): array
    {
        $used = (int) Db::one(
            // Dwa symbole na tę samą wartość — MySQL przy natywnym przygotowaniu
            // zapytania nie przyjmuje powtórzonego symbolu („Invalid parameter number").
            'SELECT COUNT(*) AS c FROM matches WHERE club_home_id = :id_h OR club_away_id = :id_a',
            ['id_h' => $id, 'id_a' => $id]
        )['c'];

        if ($used > 0) {
            return ['ok' => false, 'error' => 'club.err.in_use'];
        }

        $club = self::find($id);
        Db::run('DELETE FROM clubs WHERE id = :id', ['id' => $id]);

        if ($club !== null && !empty($club['crest_path']) && Storage::isInside((string) $club['crest_path'])) {
            @unlink((string) $club['crest_path']);
        }
        Audit::log('club.deleted', $userId, 'club', $id);
        return ['ok' => true];
    }

    /**
     * Klub pasujący do nazwy z eksportu. Porównanie po nazwie, skrócie
     * i aliasach — bez uwzględniania wielkości liter i nadmiarowych spacji.
     *
     * @return array<string,mixed>|null
     */
    public static function matchByExportName(string $detected): ?array
    {
        $needle = self::normalize($detected);
        foreach (self::all() as $club) {
            $candidates = array_merge(
                [(string) $club['name'], (string) ($club['short_name'] ?? '')],
                self::decodeAliases($club['aliases_json'] ?? null)
            );
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && self::normalize($candidate) === $needle) {
                    return $club;
                }
            }
        }
        return null;
    }

    /** Dopisanie nazwy z eksportu do aliasów, żeby następny import dopasował się sam. */
    public static function rememberAlias(int $clubId, string $detected): void
    {
        $club = self::find($clubId);
        if ($club === null) {
            return;
        }
        $aliases = self::decodeAliases($club['aliases_json'] ?? null);
        foreach ($aliases as $alias) {
            if (self::normalize($alias) === self::normalize($detected)) {
                return;
            }
        }
        if (self::normalize((string) $club['name']) === self::normalize($detected)) {
            return;
        }
        $aliases[] = $detected;
        Db::run('UPDATE clubs SET aliases_json = :a WHERE id = :id', [
            'a'  => self::encodeAliases($aliases),
            'id' => $clubId,
        ]);
    }

    /**
     * Konfiguracja drużyn dla silnika (docs/KONTRAKT_CLI.md). Zwracamy nazwy,
     * skróty, barwy i ścieżkę herbu — silnik nie odgaduje ich z danych.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function engineConfig(?int $usId, ?int $themId): array
    {
        $out = [];
        foreach (['us' => $usId, 'them' => $themId] as $side => $id) {
            if ($id === null) {
                continue;
            }
            $club = self::find($id);
            if ($club === null) {
                continue;
            }
            $out[$side] = [
                'name'  => (string) $club['name'],
                'short' => $club['short_name'] !== null ? (string) $club['short_name'] : null,
                'color' => (string) $club['color_primary'],
                'crest' => $club['crest_path'] !== null ? (string) $club['crest_path'] : null,
            ];
        }
        return $out;
    }

    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
    }

    /** @return list<string> */
    public static function decodeAliases(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    /**
     * Pola opisowe jako tablica. Kształt jest CELOWO otwarty — miasto, liga,
     * sezon i cokolwiek dojdzie później. To dane opisowe: nie wchodzą do metryk
     * i żadna liczba w raporcie od nich nie zależy.
     *
     * @return array<string,mixed>
     */
    public static function decodeDetails(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string,mixed> $details */
    public static function encodeDetails(array $details): ?string
    {
        $clean = [];
        foreach ($details as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
            }
            // Puste pole formularza to brak danych, nie pusty napis do zapisania.
            if ($value !== '' && $value !== null) {
                $clean[(string) $key] = $value;
            }
        }
        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string>|string $aliases */
    public static function encodeAliases(array|string $aliases): ?string
    {
        if (is_string($aliases)) {
            // Z formularza przychodzi jedna nazwa na linię.
            $aliases = preg_split('/\R/u', $aliases) ?: [];
        }
        $clean = [];
        foreach ($aliases as $alias) {
            $alias = trim((string) $alias);
            if ($alias !== '' && !in_array($alias, $clean, true)) {
                $clean[] = $alias;
            }
        }
        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }
}
