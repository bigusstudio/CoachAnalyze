<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Import eksportu: wiersz w `matches` + wiersz w `imports`, raport pokrycia,
 * kolejkowanie renderu.
 *
 * `coverage_json` i `warnings_json` zapisujemy DOKŁADNIE tak, jak przyszły
 * z silnika. PHP ich nie przelicza ani nie streszcza — od liczenia jest silnik
 * (CLAUDE.md §4), a raport pokrycia ma pokazywać to, co zobaczył parser.
 */
final class Imports
{
    /**
     * Nowy import wraz z meczem w stanie `draft`.
     *
     * BEZ POKRYCIA. Warstwa żądań nie uruchamia silnika (disable_functions na
     * PHP-FPM), więc `coverage_json`, `warnings_json` i `sections_json`
     * wypełnia dopiero zadanie `inspect` podniesione przez crona.
     */
    public static function create(
        int $ownerId,
        string $csvPath,
        ?string $jsonPath,
        string $checksum,
    ): int {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run(
                'INSERT INTO matches (owner_id, season_id, status, created_at)
                 VALUES (:owner, NULL, :status, :now)',
                ['owner' => $ownerId, 'status' => 'draft', 'now' => Stats::now()]
            );
            $matchId = (int) $pdo->lastInsertId();

            Db::run(
                'INSERT INTO imports (match_id, csv_path, json_path, checksum_csv, created_at)
                 VALUES (:mid, :csv, :json, :sum, :now)',
                [
                    'mid'  => $matchId,
                    'csv'  => $csvPath,
                    'json' => $jsonPath,
                    'sum'  => $checksum,
                    'now'  => Stats::now(),
                ]
            );
            $importId = (int) $pdo->lastInsertId();

            $pdo->commit();
            Audit::log('import.created', $ownerId, 'import', $importId, ['match_id' => $matchId]);
            return $importId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Zapis wyniku `inspect`. Wołane WYŁĄCZNIE z procesu roboczego.
     *
     * @param array<string,mixed> $meta
     */
    public static function saveInspection(int $importId, array $meta): void
    {
        Db::run(
            'UPDATE imports
                SET format_fingerprint = :fp, coverage_json = :cov, warnings_json = :warn,
                    sections_json = :sec, engine_version = :ver
              WHERE id = :id',
            [
                'fp'   => self::bareFingerprint($meta['format_fingerprint'] ?? null),
                'cov'  => self::encode($meta['coverage'] ?? null),
                'warn' => self::encode($meta['warnings'] ?? null),
                'sec'  => self::encode([
                    'available'   => $meta['sections_available'] ?? [],
                    'unavailable' => $meta['sections_unavailable'] ?? [],
                ]),
                'ver'  => $meta['engine_version'] ?? null,
                'id'   => $importId,
            ]
        );

        $import = self::find($importId);
        if ($import !== null) {
            Db::run('UPDATE matches SET half_split_ms = :hs WHERE id = :id', [
                'hs' => $meta['half_split_ms'] ?? null,
                'id' => (int) $import['match_id'],
            ]);
            // Kluby dopasowujemy dopiero teraz — wcześniej nie znaliśmy nazw z danych.
            self::assignClubs((int) $import['match_id'],
                array_map('strval', (array) ($meta['coverage']['teams'] ?? [])));
        }
    }

    /** Zadanie `inspect` do kolejki. Nic nie uruchamia — podnosi je cron. */
    public static function queueInspect(int $importId, int $userId): int
    {
        $import = self::find($importId);
        if ($import === null) {
            throw new \RuntimeException("Import {$importId} nie istnieje");
        }

        Db::run(
            'INSERT INTO jobs (type, payload_json, status, attempts, created_at)
             VALUES (:type, :payload, :status, 0, :now)',
            [
                'type'    => 'inspect',
                'payload' => json_encode([
                    'import_id' => $importId,
                    'match_id'  => (int) $import['match_id'],
                ], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
                'now'     => Stats::now(),
            ]
        );
        $jobId = (int) Db::pdo()->lastInsertId();
        Audit::log('inspect.queued', $userId, 'import', $importId, ['job_id' => $jobId]);
        return $jobId;
    }

    /** Ostatnie zadanie danego typu dla importu — do pokazania stanu. */
    public static function latestJob(int $importId, ?string $type = null): ?array
    {
        $rows = Db::all(
            'SELECT id, type, status, payload_json FROM jobs ORDER BY id DESC LIMIT 200'
        );
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload) || (int) ($payload['import_id'] ?? 0) !== $importId) {
                continue;
            }
            if ($type !== null && $row['type'] !== $type) {
                continue;
            }
            return $row;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT i.*, m.id AS match_id, m.status AS match_status, m.played_at, m.season_id
               FROM imports i
               JOIN matches m ON m.id = i.match_id
              WHERE i.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Pokrycie, ostrzeżenia i sekcje — wszystko z artefaktów zapisanych przez
     * proces roboczy. Warstwa żądań nie ma jak zapytać silnika (disable_functions),
     * więc to jest jedyne źródło tych danych.
     *
     * @return array{coverage:array<string,mixed>, warnings:list<array<string,mixed>>,
     *               sections_available:list<string>, sections_unavailable:list<array<string,mixed>>}
     */
    public static function report(array $import): array
    {
        $sections = self::decode($import['sections_json'] ?? null);

        $meta = self::decode($import['coverage_json'] ?? null);

        return [
            'coverage'             => $meta,
            'warnings'             => array_values(self::decode($import['warnings_json'] ?? null)),
            'sections_available'   => array_values((array) ($sections['available'] ?? [])),
            'sections_unavailable' => array_values((array) ($sections['unavailable'] ?? [])),
            // Co NIE weszlo do analizy. Raport pokrycia, ktory tego nie pokazuje,
            // sugeruje kompletnosc, ktorej nie ma — a to najgrozniejszy rodzaj
            // bledu w raporcie pokazywanym zarzadowi.
            'excluded'             => self::excluded($import, $meta),
        ];
    }

    /**
     * Dopasowanie nazw z eksportu do klubów i przypisanie ich do meczu.
     *
     * Wołane przy imporcie ORAZ tuż przed renderem — operator mógł w międzyczasie
     * założyć brakujący klub na ekranie pokrycia i wrócić.
     *
     * Eksport LiveTag NIE niesie informacji o tym, kto był gospodarzem, i nie
     * udajemy jej. Klub oznaczony „mój" trafia do `club_home_id`, drugi do
     * `club_away_id`, ale te kolumny znaczą `us` i `them` z kontraktu silnika —
     * nie strony boiska. W interfejsie widać „Nasza drużyna" i „Rywal”.
     *
     * @param list<string> $detected nazwy z `meta.coverage.teams`
     */
    public static function assignClubs(int $matchId, array $detected): void
    {
        $own = null;
        $other = null;

        foreach ($detected as $name) {
            $club = Clubs::matchByExportName((string) $name);
            if ($club === null) {
                continue;
            }
            Clubs::rememberAlias((int) $club['id'], (string) $name);

            if (!empty($club['is_own_team']) && $own === null) {
                $own = (int) $club['id'];
            } elseif ($other === null) {
                $other = (int) $club['id'];
            }
        }

        // Gdy żaden klub nie jest oznaczony jako „mój", pierwszy dopasowany
        // idzie na pozycję gospodarza — kolejność z eksportu jest tu jedyną,
        // jaką mamy, i lepsza niż zostawienie obu pól pustych.
        if ($own === null && $other !== null) {
            $own = $other;
            $other = null;
        }

        Db::run('UPDATE matches SET club_home_id = :h, club_away_id = :a WHERE id = :id', [
            'h'  => $own,
            'a'  => $other,
            'id' => $matchId,
        ]);
    }

    /** Nazwy drużyn wykryte w danych, zapisane w raporcie pokrycia. */
    public static function detectedTeams(array $import): array
    {
        $coverage = self::decode($import['coverage_json'] ?? null);
        return array_values(array_map('strval', (array) ($coverage['teams'] ?? [])));
    }

    public static function queueBuild(int $importId, int $userId): int
    {
        $import = self::find($importId);
        if ($import === null) {
            throw new \RuntimeException("Import {$importId} nie istnieje");
        }

        // Kluby mogły powstać dopiero teraz, na ekranie pokrycia.
        self::assignClubs((int) $import['match_id'], self::detectedTeams($import));

        Db::run(
            'INSERT INTO jobs (type, payload_json, status, attempts, created_at)
             VALUES (:type, :payload, :status, 0, :now)',
            [
                'type'    => 'build_report',
                'payload' => json_encode([
                    'import_id' => $importId,
                    'match_id'  => (int) $import['match_id'],
                ], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
                'now'     => Stats::now(),
            ]
        );
        $jobId = (int) Db::pdo()->lastInsertId();

        Db::run('UPDATE matches SET status = :s WHERE id = :id', [
            's'  => 'queued',
            'id' => (int) $import['match_id'],
        ]);

        Audit::log('report.queued', $userId, 'import', $importId, ['job_id' => $jobId]);
        return $jobId;
    }

    /**
     * Najnowszy import meczu — źródło do ponownego wygenerowania raportu.
     *
     * Bierzemy najnowszy, nie pierwszy: jeśli operator wgrał poprawiony eksport,
     * to on jest tym, z którego ma powstać kolejny raport.
     *
     * @return array<string,mixed>|null
     */
    public static function latestForMatch(int $matchId): ?array
    {
        return Db::one(
            'SELECT * FROM imports WHERE match_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $matchId]
        );
    }


    /**
     * Zdarzenia poza analiza: ile ich jest i z jakich tagow pochodza.
     *
     * DWA ZRODLA, obydwa musza byc widoczne:
     *  - tagi NIEROZPOZNANE (silnik ich nie zna i nikt jeszcze nie zdecydowal),
     *  - tagi swiadomie oznaczone „nie analizuj" w profilu klubu.
     *
     * Drugie sa grozniejsze, bo wygladaja na obsluzone. Decyzja „pomijamy" jest
     * poprawna, ale musi byc WIDOCZNA przy kazdym raporcie — inaczej po pol roku
     * nikt nie pamieta, ze jedna trzecia zdarzen nie wchodzi do liczb.
     *
     * @return array{count:?int, unrecognised:list<string>, ignored:list<string>, total:?int}
     */
    private static function excluded(array $import, array $meta): array
    {
        $clubId = self::mappingClubId($import);

        $nierozpoznane = [];
        foreach ((array) ($meta['unmapped_tags'] ?? []) as $poz) {
            $nazwa = is_array($poz) ? (string) ($poz['tag'] ?? $poz['name'] ?? '') : (string) $poz;
            if ($nazwa !== '') {
                $nierozpoznane[] = $nazwa;
            }
        }

        $zdecydowane = $clubId !== null ? Mappings::decidedTags($clubId) : [];
        $pominiete   = $clubId !== null ? Mappings::ignoredTags($clubId) : [];

        // Z listy „nierozpoznanych" WYPADAJA tagi, o ktorych juz zdecydowano.
        //
        // `inspect` nie dostaje profilu klubu (docs/KONTRAKT_CLI.md), wiec zglasza
        // jako nierozpoznane rowniez te tagi, ktore operator wlasnie zmapowal.
        // Pokazywanie ich dalej jako nierozpoznanych sugerowaloby, ze decyzja
        // nie zadzialala — a zadziala przy najblizszym renderze, bo tam profil
        // juz idzie do silnika.
        $nierozpoznane = array_values(array_filter(
            $nierozpoznane,
            static fn(string $tag) => !isset($zdecydowane[$tag])
        ));

        // Liczba zdarzen: bierzemy ja z `meta`, jesli silnik ja podaje.
        // NIE liczymy jej w PHP — parsowanie eksportu tutaj oznaczaloby drugi
        // parser i wszystkie jedenascie pulapek formatu LiveTag od nowa.
        $ile = $meta['coverage']['unanalysed'] ?? ($meta['unanalysed_events'] ?? null);

        return [
            'count'        => $ile !== null ? (int) $ile : null,
            'total'        => isset($meta['coverage']['events']) ? (int) $meta['coverage']['events'] : null,
            'unrecognised' => array_values(array_unique($nierozpoznane)),
            'ignored'      => $pominiete,
        ];
    }

    /**
     * Klub „nasz" wykryty w eksporcie — właściciel profilu mapowań.
     *
     * Kreator działa PRZED ekranem pokrycia, czyli zanim kluby zostaną
     * przypisane do meczu. Rozstrzygamy więc z nazw wykrytych w danych,
     * tak samo jak `assignClubs()`, tylko bez zapisu.
     *
     * Gdy żaden klub nie pasuje, profilu nie ma i wszystkie tagi są nowe —
     * co jest prawdą o pierwszym imporcie nowego klubu.
     */
    public static function mappingClubId(array $import): ?int
    {
        $matchId = (int) ($import['match_id'] ?? 0);
        if ($matchId > 0) {
            $mecz = Db::one(
                'SELECT club_home_id FROM matches WHERE id = :id',
                ['id' => $matchId]
            );
            if ($mecz !== null && $mecz['club_home_id'] !== null) {
                return (int) $mecz['club_home_id'];
            }
        }

        $pierwszyDopasowany = null;
        foreach (self::detectedTeams($import) as $nazwa) {
            $club = Clubs::matchByExportName((string) $nazwa);
            if ($club === null) {
                continue;
            }
            if (!empty($club['is_own_team'])) {
                return (int) $club['id'];
            }
            $pierwszyDopasowany ??= (int) $club['id'];
        }

        return $pierwszyDopasowany;
    }

    /**
     * Czy import wymaga zatrzymania na kreatorze mapowań.
     *
     * Pytamy o to przy każdym wejściu na pokrycie, a nie raz po imporcie:
     * profil klubu mógł się w międzyczasie zmienić i decyzja podjęta wtedy
     * bywa już nieaktualna.
     */
    public static function needsMapping(array $import): bool
    {
        $meta = self::decode($import['coverage_json'] ?? null);
        if (!is_array($meta) || $meta === []) {
            return false;   // Bez pokrycia nie ma czego porównywać.
        }

        $nieznane = Mappings::unknown($meta, self::mappingClubId($import));
        return $nieznane['tags'] !== [] || $nieznane['labels'] !== [];
    }

    /**
     * Zapamiętanie, z jaką wersją profilu przeszedł ten import.
     *
     * Bez tego nie da się odróżnić „nie było nowych tagów, przeszliśmy dalej"
     * od „ekran mapowania nigdy się nie pokazał". Pierwsze jest poprawne,
     * drugie jest błędem — i mają wyglądać inaczej.
     */
    public static function setMappingProfile(int $importId, int $profileId): void
    {
        Db::run(
            'UPDATE imports SET mapping_profile_id = :p WHERE id = :id',
            ['p' => $profileId, 'id' => $importId]
        );
    }

    /** Najnowszy raport dla meczu — do odnośnika po zakończeniu. */
    public static function latestReport(int $matchId): ?array
    {
        return Db::one(
            'SELECT id, html_path, engine_version, generated_at
               FROM reports WHERE match_id = :mid ORDER BY id DESC LIMIT 1',
            ['mid' => $matchId]
        );
    }

    private static function bareFingerprint(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return substr(str_starts_with($value, 'sha256:') ? substr($value, 7) : $value, 0, 64);
    }

    private static function encode(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
