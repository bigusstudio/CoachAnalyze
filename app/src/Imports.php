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
     * @param array<string,mixed> $meta wynik `inspect`
     */
    public static function create(
        int $ownerId,
        string $csvPath,
        ?string $jsonPath,
        string $checksum,
        array $meta,
    ): int {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run(
                'INSERT INTO matches (owner_id, season_id, status, half_split_ms, created_at)
                 VALUES (:owner, NULL, :status, :hs, :now)',
                [
                    'owner'  => $ownerId,
                    'status' => 'draft',
                    'hs'     => $meta['half_split_ms'] ?? null,
                    'now'    => Stats::now(),
                ]
            );
            $matchId = (int) $pdo->lastInsertId();

            Db::run(
                'INSERT INTO imports (match_id, csv_path, json_path, checksum_csv,
                                      format_fingerprint, coverage_json, warnings_json,
                                      engine_version, created_at)
                 VALUES (:mid, :csv, :json, :sum, :fp, :cov, :warn, :ver, :now)',
                [
                    'mid'  => $matchId,
                    'csv'  => $csvPath,
                    'json' => $jsonPath,
                    'sum'  => $checksum,
                    // Odcisk zapisujemy bez prefiksu `sha256:` — kolumna to CHAR(64).
                    'fp'   => self::bareFingerprint($meta['format_fingerprint'] ?? null),
                    'cov'  => self::encode($meta['coverage'] ?? null),
                    'warn' => self::encode($meta['warnings'] ?? null),
                    'ver'  => $meta['engine_version'] ?? null,
                    'now'  => Stats::now(),
                ]
            );
            $importId = (int) $pdo->lastInsertId();

            $pdo->commit();

            // Kluby znane z wcześniejszych meczów dopasowują się same — po to
            // trzymamy aliasy. Operator nie wpisuje nazw przy każdym imporcie.
            self::assignClubs($matchId, array_map('strval', (array) ($meta['coverage']['teams'] ?? [])));

            Audit::log('import.created', $ownerId, 'import', $importId, ['match_id' => $matchId]);
            return $importId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
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
     * Sekcje niedostępne wracają z silnika w `meta`, ale w bazie trzymamy tylko
     * `coverage` i `warnings` (tak wygląda schemat). Ekran pokrycia potrzebuje
     * jednego i drugiego, więc rozpakowujemy je tutaj, w jednym miejscu.
     *
     * @return array{coverage:array<string,mixed>, warnings:list<array<string,mixed>>}
     */
    public static function report(array $import): array
    {
        return [
            'coverage' => self::decode($import['coverage_json'] ?? null),
            'warnings' => array_values(self::decode($import['warnings_json'] ?? null)),
        ];
    }

    /**
     * Zadanie renderu dla tego importu. Ten sam import można renderować wiele
     * razy — regeneracja nie wymaga ponownego wgrywania pliku.
     */
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
