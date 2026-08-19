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
     * @param array{tenant?:int|null, club?:int|null, season?:int|null, sort?:string|null, page?:int} $filters
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int, per_page:int}
     */
    public static function search(array $filters = []): array
    {
        $where = [];
        $params = [];

        /*
         * TENANT — właściciel analizy. To NIE JEST to samo co filtr `club` niżej
         * i te dwa nie mają się zlać w jeden.
         *
         *   tenant → `m.club_id`      : czyje to mecze (kto zamówił analizę)
         *   club   → club_home/away   : kto grał w meczu (dowolna ze stron)
         *
         * Dziś dla całej historii `club_id = club_home_id`, więc różnicy nie
         * widać. Zobaczy ją pierwszy mecz scoutingowy — analiza dwóch obcych
         * drużyn ma tenanta, ale żadna ze stron nim nie jest.
         *
         * SESJA 2: `/klub/{id}/mecze` przekazuje `tenant` obowiązkowo. Globalna
         * `/mecze` (poza kontekstem klubu) go NIE przekazuje i przegląda
         * historię wszystkich tenantów naraz — to świadomy, osobny widok, nie
         * pozostałość po TODO.
         */
        if (!empty($filters['tenant'])) {
            $where[] = 'm.club_id = :tenant';
            $params['tenant'] = (int) $filters['tenant'];
        }

        if (!empty($filters['club'])) {
            // Klub po dowolnej stronie — operator myśli „mecze Hutnika",
            // a nie „mecze, w których Hutnik był stroną `us`".
            //
            // DWA SYMBOLE NA TĘ SAMĄ WARTOŚĆ: przy `ATTR_EMULATE_PREPARES => false`
            // MySQL nie przyjmuje powtórzonego symbolu i odrzuca zapytanie
            // („Invalid parameter number"). SQLite przyjmuje, więc testy tego
            // nie łapały — pilnuje tego `app/tests/test_sql_parametry.php`.
            $where[] = '(m.club_home_id = :club_h OR m.club_away_id = :club_a)';
            $params['club_h'] = (int) $filters['club'];
            $params['club_a'] = (int) $filters['club'];
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

    /**
     * Metadane meczu z formularza (Sesja 6 przebudowy).
     *
     * PRZECIWNIK JEST WIERSZEM W `clubs`, NIE WOLNYM TEKSTEM — rozstrzygnięcie
     * z Sesji 1, spisane w docs/PRZEBUDOWA_KLUB_SESJE.md. Wiersz niesie herb,
     * barwy i `aliases_json`, czyli nazwy, pod jakimi klub występuje
     * w eksportach LiveTag. To one napędzają automatyczne dopasowanie drużyn
     * przy imporcie; wolny tekst zabiłby dopasowanie i kazałby operatorowi
     * wpisywać przeciwnika od nowa przy każdym meczu, także przy rewanżu.
     *
     * `is_home` WYPEŁNIA WYŁĄCZNIE TEN FORMULARZ. Eksport LiveTag nie niesie
     * informacji o gospodarzu i nie wolno jej zgadywać — `NULL` znaczy
     * „nie wiemy" i jest poprawną wartością dla całej historii.
     *
     * Wynik trzymamy w dwóch kolumnach (`score_us` / `score_them`, migracja
     * 013), a nie w napisie „3:1": porównania sezonowe muszą sumować bramki,
     * a napis nie mówi, która liczba jest czyja, dopóki nie zna się `is_home`.
     *
     * Sezon nadpisujemy TYLKO gdy podany — ręczne przypisanie operatora jest
     * ważniejsze niż wykrywanie z daty (tak samo jak w `setDate()`).
     *
     * @param array<string,mixed> $data
     */
    public static function saveMeta(int $matchId, array $data, int $userId): void
    {
        $data_meczu = trim((string) ($data['played_at'] ?? ''));
        $sezon = $data['season_id'] ?? null;

        if ($sezon === null && $data_meczu !== '') {
            $sezon = Seasons::detect($data_meczu, $userId);
        }

        Db::run(
            'UPDATE matches
                SET club_away_id = :away,
                    played_at    = :date,
                    is_home      = :home,
                    competition  = :comp,
                    season_id    = :season,
                    score_us     = :us,
                    score_them   = :them
              WHERE id = :id',
            [
                'away'   => $data['club_away_id'] ?? null,
                'date'   => $data_meczu !== '' ? $data_meczu : null,
                // Trzy stany, nie dwa: u siebie, na wyjeździe, nie wiemy.
                'home'   => $data['is_home'] === null ? null : (int) (bool) $data['is_home'],
                'comp'   => ($data['competition'] ?? '') !== '' ? $data['competition'] : null,
                'season' => $sezon,
                'us'     => self::bramki($data['score_us'] ?? null),
                'them'   => self::bramki($data['score_them'] ?? null),
                'id'     => $matchId,
            ]
        );

        Audit::log('match.meta', $userId, 'match', $matchId, [
            'club_away_id' => $data['club_away_id'] ?? null,
            'played_at'    => $data_meczu !== '' ? $data_meczu : null,
        ]);
    }

    /**
     * Liczba bramek albo `null`.
     *
     * Puste pole to BRAK DANYCH, nie zero. Zero jest konkretnym wynikiem
     * i nie da się go odróżnić od „nie podano" (CLAUDE.md §8).
     */
    private static function bramki(mixed $wartosc): ?int
    {
        if ($wartosc === null || trim((string) $wartosc) === '') {
            return null;
        }
        $liczba = (int) $wartosc;
        return $liczba >= 0 && $liczba <= 99 ? $liczba : null;
    }

    public static function setSeason(int $matchId, ?int $seasonId, int $userId): void
    {
        Db::run('UPDATE matches SET season_id = :s WHERE id = :id', [
            's'  => $seasonId,
            'id' => $matchId,
        ]);
        Audit::log('match.season', $userId, 'match', $matchId, ['season_id' => $seasonId]);
    }

    /**
     * Historia zdarzeń meczu: wgranie, pokrycie, raport, udostępnienie.
     *
     * SKŁADANA Z FAKTÓW W TABELACH, nie z `audit_log`. Audyt notuje, kto co
     * kliknął, i służy do rozliczania dostępu; tutaj chodzi o to, co się
     * z meczem stało — łącznie ze zdarzeniami, których nikt nie klikał, bo
     * zrobił je proces roboczy z crona. Gdyby brać to z audytu, przetwarzanie
     * w tle byłoby w historii niewidoczne, czyli zniknęłaby połowa odpowiedzi
     * na pytanie „dlaczego to trwało tak długo".
     *
     * Każdy wpis: `kind`, `at`, `label`, opcjonalnie `url` i `detail`.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $matchId): array
    {
        $zdarzenia = [];

        foreach (Db::all(
            'SELECT id, created_at, coverage_json, engine_version
               FROM imports WHERE match_id = :id ORDER BY id',
            ['id' => $matchId]
        ) as $import) {
            $zdarzenia[] = [
                'kind'   => 'import',
                'at'     => (string) $import['created_at'],
                'ref_id' => (int) $import['id'],
                'url'    => '/import/' . (int) $import['id'],
            ];

            // Pokrycie to osobne zdarzenie, bo dzieje się później niż wgranie —
            // między nimi stoi kolejka i to właśnie tam bywa opóźnienie.
            if (!empty($import['coverage_json'])) {
                $zdarzenia[] = [
                    'kind'   => 'coverage',
                    'at'     => (string) $import['created_at'],
                    'ref_id' => (int) $import['id'],
                    'url'    => '/import/' . (int) $import['id'],
                    'detail' => $import['engine_version'] !== null
                        ? 'silnik ' . $import['engine_version'] : null,
                ];
            }
        }

        foreach (Db::all(
            'SELECT id, generated_at, engine_version FROM reports WHERE match_id = :id ORDER BY id',
            ['id' => $matchId]
        ) as $report) {
            $zdarzenia[] = [
                'kind'   => 'report',
                'at'     => (string) $report['generated_at'],
                'ref_id' => (int) $report['id'],
                'url'    => '/raport/' . (int) $report['id'],
                'detail' => 'silnik ' . (string) $report['engine_version'],
            ];
        }

        foreach (Db::all(
            'SELECT sl.id, sl.created_at, sl.revoked_at, sl.views, sl.report_id
               FROM share_links sl
               JOIN reports r ON r.id = sl.report_id
              WHERE r.match_id = :id ORDER BY sl.id',
            ['id' => $matchId]
        ) as $link) {
            $zdarzenia[] = [
                'kind'   => 'share',
                'at'     => (string) $link['created_at'],
                'ref_id' => (int) $link['id'],
                'url'    => '/raport/' . (int) $link['report_id'] . '/udostepnij',
                'detail' => (int) $link['views'] > 0 ? 'wejść: ' . (int) $link['views'] : null,
            ];

            // Odwołanie linku jest osobnym zdarzeniem w czasie, nie cechą linku.
            if ($link['revoked_at'] !== null) {
                $zdarzenia[] = [
                    'kind'   => 'share_revoked',
                    'at'     => (string) $link['revoked_at'],
                    'ref_id' => (int) $link['id'],
                    'url'    => '/raport/' . (int) $link['report_id'] . '/udostepnij',
                ];
            }
        }

        // Zadania zakończone błędem — bez nich historia pokazywałaby wyłącznie
        // sukcesy i wyglądałaby na kompletną, nie będąc nią.
        //
        // Powiązanie zadania z importem siedzi w `payload_json`, więc rozwiązujemy
        // je w PHP, tak samo jak `Imports::latestJob()`. Funkcje JSON-owe SQL-a
        // różnią się między MariaDB a SQLite, a testy chodzą na tym drugim —
        // zapytanie z `JSON_EXTRACT` działałoby na produkcji i wywracało się w CI.
        $importIds = array_map(
            static fn(array $i) => (int) $i['id'],
            Db::all('SELECT id FROM imports WHERE match_id = :id', ['id' => $matchId])
        );

        if ($importIds !== []) {
            foreach (Db::all(
                "SELECT id, finished_at, type, error_text, payload_json
                   FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT :lim",
                ['lim' => 500]
            ) as $job) {
                $payload = json_decode((string) $job['payload_json'], true);
                if (!is_array($payload) || !in_array((int) ($payload['import_id'] ?? 0), $importIds, true)) {
                    continue;
                }
                $zdarzenia[] = [
                    'kind'   => 'failed',
                    'at'     => (string) ($job['finished_at'] ?? ''),
                    'ref_id' => (int) $job['id'],
                    'url'    => '/zadania/' . (int) $job['id'],
                    'detail' => self::pierwszaLinia((string) ($job['error_text'] ?? '')),
                ];
            }
        }

        usort($zdarzenia, static function (array $a, array $b): int {
            return [$a['at'], $a['kind']] <=> [$b['at'], $b['kind']];
        });

        return $zdarzenia;
    }

    /** Skrót powodu awarii na jedną linię — pełny zapis zostaje w podglądzie zadania. */
    private static function pierwszaLinia(string $tekst): ?string
    {
        $tekst = trim($tekst);
        if ($tekst === '') {
            return null;
        }
        $koniec = strpos($tekst, "\n");
        $linia = $koniec === false ? $tekst : substr($tekst, 0, $koniec);
        return mb_substr($linia, 0, 160);
    }
}
