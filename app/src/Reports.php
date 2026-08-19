<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Lista raportów niezależna od meczu.
 *
 * DLACZEGO OSOBNY EKRAN, skoro raport jest zawsze czyjś: biblioteka meczów
 * odpowiada na pytanie „co się działo z tym meczem", a ta lista na „co
 * wygenerowaliśmy i co z tego jest udostępnione". To drugie pytanie zadaje się
 * przy sprawozdaniu dla zarządu i przy przeglądzie, komu co poszło — wtedy
 * wchodzenie w każdy mecz po kolei jest bez sensu.
 *
 * Jeden mecz miewa KILKA raportów (regeneracja po poprawce w silniku albo po
 * uzupełnieniu klubów). Wszystkie zostają — pytanie „dlaczego raport z marca
 * pokazuje inną liczbę" musi mieć odpowiedź (CLAUDE.md §7). Dlatego lista
 * oznacza najnowszy dla danego meczu, zamiast starsze ukrywać.
 */
final class Reports
{
    public const NA_STRONE = 20;

    /** Dozwolone porządki sortowania. Wartość spoza listy wraca do domyślnej. */
    private const SORTOWANIA = [
        'date_desc' => 'r.generated_at DESC, r.id DESC',
        'date_asc'  => 'r.generated_at ASC, r.id ASC',
    ];

    public static function normalizeSort(?string $sort): string
    {
        return isset(self::SORTOWANIA[(string) $sort]) ? (string) $sort : 'date_desc';
    }

    /**
     * Wyszukiwanie z filtrami i stronicowaniem.
     *
     * @param array{club?:?int, season?:?int, sort?:?string, page?:int} $filters
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function search(array $filters = []): array
    {
        $warunki = [];
        $params  = [];

        /*
         * TENANT — właściciel raportu, kolumna `r.club_id` (migracja 012).
         * Osobny wymiar niż filtr `club` niżej: tenant mówi, CZYJ jest raport,
         * a `club` — kto grał w meczu. Opis rozdziału: `Matches::search()`.
         *
         * Filtrujemy po `r.club_id`, a nie przez złączenie z `matches` —
         * denormalizacja w `reports` istnieje właśnie po to, żeby lista
         * raportów klubu nie musiała dotykać drugiej tabeli.
         *
         * SESJA 2: `/klub/{id}/raporty` przekazuje `tenant` obowiązkowo.
         * Globalna `/raporty` go nie przekazuje — to świadomy, osobny widok
         * „co wygenerowaliśmy w ogóle", nie pozostałość po TODO.
         */
        if (!empty($filters['tenant'])) {
            $warunki[] = 'r.club_id = :tenant';
            $params['tenant'] = (int) $filters['tenant'];
        }

        // Filtr po klubie łapie mecz z OBU stron. Klub bywa raz „nasz", raz
        // rywalem (mecze sparingowe między własnymi drużynami klubu), a operator
        // pytający „pokaż raporty Hutnika" chce jednego i drugiego.
        if (!empty($filters['club'])) {
            /*
             * DWA OSOBNE SYMBOLE NA TĘ SAMĄ WARTOŚĆ.
             *
             * `PDO::ATTR_EMULATE_PREPARES => false` (Db.php) oznacza natywne
             * przygotowanie zapytania po stronie serwera. MySQL/MariaDB NIE
             * pozwala wtedy użyć tego samego symbolu dwa razy i odrzuca zapytanie
             * komunikatem „Invalid parameter number".
             *
             * SQLite powtórzenie przyjmuje — dlatego testy tego nie łapały,
             * a ekran wywalał się wyłącznie na produkcji. Pilnuje tego teraz
             * `app/tests/test_sql_parametry.php`.
             */
            $warunki[] = '(m.club_home_id = :club_h OR m.club_away_id = :club_a)';
            $params['club_h'] = (int) $filters['club'];
            $params['club_a'] = (int) $filters['club'];
        }

        if (!empty($filters['season'])) {
            $warunki[] = 'm.season_id = :season';
            $params['season'] = (int) $filters['season'];
        }

        $where = $warunki === [] ? '' : ' WHERE ' . implode(' AND ', $warunki);
        $order = self::SORTOWANIA[self::normalizeSort($filters['sort'] ?? null)];

        $licznik = Db::one(
            'SELECT COUNT(*) AS ile FROM reports r JOIN matches m ON m.id = r.match_id' . $where,
            $params
        );
        $total = (int) ($licznik['ile'] ?? 0);

        $pages = max(1, (int) ceil($total / self::NA_STRONE));
        $page  = max(1, min($pages, (int) ($filters['page'] ?? 1)));

        $rows = Db::all(
            'SELECT r.id, r.match_id, r.html_path, r.engine_version, r.generated_at,
                    r.template_version,
                    m.played_at, m.competition, m.season_id,
                    h.name AS home_name, a.name AS away_name,
                    s.label AS season_label
               FROM reports r
               JOIN matches m  ON m.id = r.match_id
               LEFT JOIN clubs h   ON h.id = m.club_home_id
               LEFT JOIN clubs a   ON a.id = m.club_away_id
               LEFT JOIN seasons s ON s.id = m.season_id'
            . $where
            . ' ORDER BY ' . $order
            . ' LIMIT :lim OFFSET :off',
            $params + ['lim' => self::NA_STRONE, 'off' => ($page - 1) * self::NA_STRONE]
        );

        self::dolaczStanLinkow($rows);
        self::oznaczNajnowsze($rows);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Stan linku publicznego i liczba wejść — jednym zapytaniem dla całej strony.
     *
     * Zapytanie w pętli po wierszach dałoby dwadzieścia zapytań na jedno
     * wyświetlenie listy. Przy dwóch tabelach i stronicowaniu to jeszcze nie boli,
     * ale wzorzec „N+1" wprowadzony raz zostaje na stałe.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function dolaczStanLinkow(array &$rows): void
    {
        foreach ($rows as &$r) {
            $r['link_stan'] = 'none';
            $r['link_id']   = null;
            $r['views']     = 0;
        }
        unset($r);

        if ($rows === []) {
            return;
        }

        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        // Lista identyfikatorów pochodzi z bazy, nie z żądania, ale i tak idzie
        // przez parametry — konkatenacja w SQL nie ma tu prawa się pojawić.
        $miejsca = implode(', ', array_map(static fn($i) => ':r' . $i, array_keys($ids)));
        $params  = [];
        foreach ($ids as $i => $id) {
            $params['r' . $i] = $id;
        }

        // Chwila odniesienia dla wygaśnięcia liczona w PHP, nie w SQL — stan linku
        // to prezentacja, tak samo jak w `Share::forReport()`. Do zapytania NIE
        // trafia, bo nadmiarowy parametr bez odpowiadającego mu miejsca w SQL
        // kończy się „column index out of range".
        $teraz = Stats::now();

        $linki = Db::all(
            'SELECT id, report_id, revoked_at, expires_at, views
               FROM share_links WHERE report_id IN (' . $miejsca . ')
              ORDER BY id ASC',
            $params
        );

        /** @var array<int, array{stan:string, id:?int, views:int}> $wg */
        $wg = [];
        foreach ($linki as $link) {
            $reportId = (int) $link['report_id'];

            $stan = match (true) {
                $link['revoked_at'] !== null => 'revoked',
                $link['expires_at'] !== null && (string) $link['expires_at'] < $teraz => 'expired',
                default => 'active',
            };

            // Wejścia sumujemy ze WSZYSTKICH linków raportu — dwa kolejne linki
            // do tego samego raportu to nadal ten sam raport oglądany przez klub.
            $poprzednie = $wg[$reportId] ?? ['stan' => 'none', 'id' => null, 'views' => 0];
            $poprzednie['views'] += (int) $link['views'];

            // Aktywny link wygrywa nad odwołanym i wygasłym: to on jest tym,
            // co można dziś odwołać i co ktoś może dziś otworzyć.
            if ($stan === 'active' || $poprzednie['stan'] !== 'active') {
                $poprzednie['stan'] = $stan;
                $poprzednie['id']   = (int) $link['id'];
            }

            $wg[$reportId] = $poprzednie;
        }

        foreach ($rows as &$r) {
            $stan = $wg[(int) $r['id']] ?? null;
            if ($stan !== null) {
                $r['link_stan'] = $stan['stan'];
                $r['link_id']   = $stan['id'];
                $r['views']     = $stan['views'];
            }
        }
    }

    /**
     * Który raport jest najnowszy dla swojego meczu.
     *
     * Liczone JEDNYM zapytaniem po wszystkich meczach z bieżącej strony, a nie
     * po samej stronie: raport z pozycji 1 może być drugim najnowszym, jeśli
     * nowszy wpadł na poprzednią stronę albo poza filtr.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function oznaczNajnowsze(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        $matchIds = array_values(array_unique(array_map(static fn($r) => (int) $r['match_id'], $rows)));
        $miejsca  = implode(', ', array_map(static fn($i) => ':m' . $i, array_keys($matchIds)));
        $params   = [];
        foreach ($matchIds as $i => $id) {
            $params['m' . $i] = $id;
        }

        $wszystkie = Db::all(
            'SELECT id, match_id FROM reports WHERE match_id IN (' . $miejsca . ')
              ORDER BY generated_at DESC, id DESC',
            $params
        );

        /** @var array<int,int> $najnowszy  match_id => report_id */
        $najnowszy = [];
        /** @var array<int,int> $ile */
        $ile = [];
        foreach ($wszystkie as $w) {
            $mid = (int) $w['match_id'];
            $ile[$mid] = ($ile[$mid] ?? 0) + 1;
            if (!isset($najnowszy[$mid])) {
                $najnowszy[$mid] = (int) $w['id'];
            }
        }

        foreach ($rows as &$r) {
            $mid = (int) $r['match_id'];
            $r['is_latest']     = ($najnowszy[$mid] ?? null) === (int) $r['id'];
            $r['siblings']      = $ile[$mid] ?? 1;
        }
    }

    /** Kluby występujące w jakimkolwiek raporcie — do listy rozwijanej filtru. */
    public static function filterClubs(): array
    {
        return Db::all(
            'SELECT DISTINCT c.id, c.name
               FROM clubs c
               JOIN matches m ON c.id = m.club_home_id OR c.id = m.club_away_id
               JOIN reports r ON r.match_id = m.id
              ORDER BY c.name'
        );
    }

    /** Sezony występujące w jakimkolwiek raporcie. */
    public static function filterSeasons(): array
    {
        return Db::all(
            'SELECT DISTINCT s.id, s.label
               FROM seasons s
               JOIN matches m ON m.season_id = s.id
               JOIN reports r ON r.match_id = m.id
              ORDER BY s.label DESC'
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT r.*, m.club_home_id, m.club_away_id, m.played_at,
                    h.name AS home_name, a.name AS away_name
               FROM reports r
               JOIN matches m ON m.id = r.match_id
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
              WHERE r.id = :id',
            ['id' => $id]
        );
    }
}
