<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Dane pulpitu: liczniki, ostatnie mecze, zadania wymagające uwagi.
 *
 * Wyłącznie odczyt i wyłącznie liczby administracyjne — ile meczów, ile raportów,
 * ile zadań. Żadnych metryk piłkarskich: te liczy silnik (CLAUDE.md §4).
 */
final class Stats
{
    /**
     * Cztery liczniki pulpitu.
     *
     * @return array{matches:int, matches_scope:string, reports:int, links:int, queued:int}
     */
    public static function counters(?int $clubId = null): array
    {
        $season = self::currentSeason();
        // Zakres klubu (golden layout W2): pulpit i szyna mówią o klubie bieżącym.
        $wKlubie = $clubId !== null ? ' AND club_id = :club' : '';
        $pKlub = $clubId !== null ? ['club' => $clubId] : [];

        if ($season !== null) {
            $matches = (int) Db::one(
                'SELECT COUNT(*) AS c FROM matches WHERE season_id = :sid' . $wKlubie,
                ['sid' => $season['id']] + $pKlub
            )['c'];
            $scope = (string) $season['label'];
        } else {
            // Brak sezonu oznaczonego jako bieżący to stan normalny na starcie.
            // Pokazujemy wtedy sumę wszystkich meczów i mówimy o tym wprost,
            // zamiast wyświetlać zero, które wygląda jak błąd danych.
            $matches = (int) Db::one('SELECT COUNT(*) AS c FROM matches WHERE 1 = 1' . $wKlubie, $pKlub)['c'];
            $scope = View::t('dash.all_seasons');
        }

        return [
            'matches'       => $matches,
            'matches_scope' => $scope,
            'reports'       => (int) Db::one('SELECT COUNT(*) AS c FROM reports WHERE 1 = 1' . $wKlubie, $pKlub)['c'],
            // Czas podajemy parametrem, a nie przez NOW(): zapytanie daje się wtedy
            // uruchomić poza MySQL-em (testy) i nie zależy od zegara serwera bazy.
            'links'         => (int) Db::one(
                'SELECT COUNT(*) AS c FROM share_links
                  WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)' . $wKlubie,
                ['now' => self::now()] + $pKlub
            )['c'],
            'queued'        => (int) Db::one(
                "SELECT COUNT(*) AS c FROM jobs WHERE status = 'queued'"
            )['c'],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function currentSeason(): ?array
    {
        return Db::one('SELECT id, label FROM seasons WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
    }

    /*
     * NAJNOWSZY RAPORT MECZU I KOLEJNOŚĆ PULPITU (golden layout W1).
     *
     * Pulpit sortuje po DACIE MECZU, a przy jej braku po dacie najnowszego
     * raportu — mecz wgrany dziś bez daty lądował dotąd na końcu listy i nie
     * było widać, który raport jest najnowszy. Podzapytanie jest powtórzone
     * w ORDER BY zamiast aliasu: MySQL nie gwarantuje aliasu wewnątrz wyrażenia.
     * Kolejność „najnowszego" raportu taka sama jak w `Reports::search`.
     */
    private const SQL_RAPORT_ID = 'SELECT r.id FROM reports r WHERE r.match_id = m.id
                                    ORDER BY r.generated_at DESC, r.id DESC LIMIT 1';
    private const SQL_RAPORT_AT = 'SELECT MAX(r.generated_at) FROM reports r WHERE r.match_id = m.id';
    private const SQL_KOLEJNOSC_PULPITU =
        '(COALESCE(m.played_at, (SELECT MAX(r.generated_at) FROM reports r WHERE r.match_id = m.id)) IS NULL),
         COALESCE(m.played_at, (SELECT MAX(r.generated_at) FROM reports r WHERE r.match_id = m.id)) DESC, m.id DESC';

    /**
     * Ostatnie mecze do tabeli na pulpicie.
     *
     * Kluby dołączamy przez LEFT JOIN — mecz bez przypisanych klubów jest
     * poprawnym stanem tuż po imporcie i nie może wypaść z listy.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentMatches(int $limit = 5, ?int $clubId = null): array
    {
        // LIMIT jako PARAMETR, nie sklejony z liczbą. Zakres i tak przycinamy,
        // ale zapytanie nie ma prawa powstawać przez konkatenację — dziś jest to
        // liczba z kodu, jutro ktoś poda tu wartość z żądania.
        return Db::all(
            'SELECT m.id, m.played_at, m.status, m.competition,
                    h.name AS home_name, a.name AS away_name
               FROM matches m
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
              WHERE (:club_a IS NULL OR m.club_id = :club_b)
              ORDER BY (m.played_at IS NULL), m.played_at DESC, m.id DESC
              LIMIT :limit',
            ['limit' => max(1, min(50, $limit)), 'club_a' => $clubId, 'club_b' => $clubId]
        );
    }

    /**
     * Zadania wymagające uwagi: uruchomione oraz nieudane z ostatniej doby.
     *
     * Zadania `running` pokazujemy bez ograniczenia czasowego — takie, które wisi
     * od trzech dni, jest właśnie tym, co operator ma zobaczyć.
     *
     * @return list<array<string,mixed>>
     */
    public static function jobsNeedingAttention(): array
    {
        return Db::all(
            "SELECT id, type, status, attempts, exit_code, created_at, started_at, finished_at
               FROM jobs
              WHERE status = 'running'
                 OR (status = 'failed' AND created_at >= :since)
              ORDER BY CASE status WHEN 'failed' THEN 0 ELSE 1 END, created_at DESC
              LIMIT 20",
            ['since' => self::now('-1 day')]
        );
    }

    /**
     * Liczniki przy pozycjach szyny bocznej (sesja 3,5).
     *
     * WYŁĄCZNIE ODCZYT i wyłącznie z danych, KTÓRE JUŻ MAMY. Pozycja, dla której
     * nie ma czego policzyć, po prostu licznika nie dostaje — zero obok nazwy
     * wygląda jak awaria danych, a nie jak „nic nie czeka" (CLAUDE.md §8).
     *
     * Jedno wywołanie na render, bo szyna jest na każdej stronie panelu.
     *
     * @return array{season:string, matches:int, reports:int, links:int, queued:int}
     */
    public static function railCounters(?int $clubId = null): array
    {
        $liczniki = self::counters($clubId);

        return [
            'season'  => $liczniki['matches_scope'],
            'matches' => $liczniki['matches'],
            'reports' => $liczniki['reports'],
            'links'   => $liczniki['links'],
            'queued'  => $liczniki['queued'],
        ];
    }

    /**
     * Ostatni rozegrany mecz ze statusem `done` — karta „Ostatni mecz".
     *
     * @return array<string,mixed>|null
     */
    public static function lastFinishedMatch(?int $clubId = null): ?array
    {
        return Db::one(
            "SELECT m.id, m.played_at, m.round, m.competition, m.status,
                    (" . self::SQL_RAPORT_ID . ") AS report_id,
                    (" . self::SQL_RAPORT_AT . ") AS report_at,
                    m.club_home_id, m.club_away_id,
                    h.name AS home_name, h.crest_path AS home_crest,
                    a.name AS away_name, a.crest_path AS away_crest,
                    s.label AS season_label
               FROM matches m
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
               LEFT JOIN seasons s ON s.id = m.season_id
              WHERE m.status = 'done' AND (:club_a IS NULL OR m.club_id = :club_b)
              ORDER BY " . self::SQL_KOLEJNOSC_PULPITU . "
              LIMIT 1",
            ['club_a' => $clubId, 'club_b' => $clubId]
        );
    }

    /**
     * Liczby meczowe z tabeli `events`, w rozbiciu na strony.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * TO NIE JEST LICZENIE METRYKI PIŁKARSKIEJ PRZEZ PHP (CLAUDE.md §4).
     *
     * `is_goal` i `xg` policzył SILNIK i zapisał w wierszach (migracja 014).
     * Tutaj jest wyłącznie `SUM()` po gotowej kolumnie — to samo, co zrobiłby
     * arkusz kalkulacyjny. PHP nie rozstrzyga, który strzał był golem ani skąd
     * wzięło się xG; gdyby rozstrzygało, byłoby drugim miejscem, w którym te
     * same reguły mogą się rozjechać.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Zwraca `null`, gdy mecz NIE MA ANI JEDNEGO ZDARZENIA. To nie to samo, co
     * zera: mecz sprzed migracji 014 albo sprzed przeliczenia raportu nie ma
     * jeszcze zdarzeń i widok ma pokazać kreskę, a nie wynik 0:0.
     *
     * @return array{us:array{goals:int,xg:float,shots:int}, them:array{...}}|null
     */
    public static function matchFacts(int $matchId): ?array
    {
        $wiersze = Db::all(
            "SELECT team_side,
                    SUM(is_goal)                                    AS goals,
                    SUM(CASE WHEN xg IS NULL THEN 0 ELSE xg END)    AS xg,
                    SUM(CASE WHEN tag_name = :tag THEN 1 ELSE 0 END) AS shots,
                    COUNT(*)                                        AS events
               FROM events
              WHERE match_id = :m
              GROUP BY team_side",
            ['m' => $matchId, 'tag' => self::TAG_STRZAL]
        );

        if ($wiersze === []) {
            return null;
        }

        $puste = ['goals' => 0, 'xg' => 0.0, 'shots' => 0];
        $out = ['us' => $puste, 'them' => $puste, 'none' => $puste, 'events' => 0];

        foreach ($wiersze as $w) {
            $strona = (string) $w['team_side'];
            $out['events'] += (int) $w['events'];
            if (!isset($out[$strona])) {
                continue;
            }
            $out[$strona] = [
                'goals' => (int) $w['goals'],
                'xg'    => round((float) $w['xg'], 2),
                'shots' => (int) $w['shots'],
            ];
        }

        return $out;
    }

    /**
     * Mecze sezonu z liczbami ze zdarzeń — pasek sezonu i tabela na pulpicie.
     *
     * `LEFT JOIN` na `events`, bo mecz bez zdarzeń ma zostać na liście: brak
     * danych jest informacją, a wypadnięcie z listy wygląda jak brak meczu.
     *
     * @return list<array<string,mixed>>
     */
    public static function seasonMatches(?int $seasonId, int $limit = 40, ?int $clubId = null): array
    {
        $warunki = [];
        if ($seasonId !== null) {
            $warunki[] = 'm.season_id = :sid';
        }
        if ($clubId !== null) {
            $warunki[] = 'm.club_id = :club';
        }
        $warunek = $warunki === [] ? '' : 'WHERE ' . implode(' AND ', $warunki);
        // OSOBNE SYMBOLE DLA KAŻDEGO WYSTĄPIENIA. PDO bez emulacji nie przyjmuje
        // tego samego symbolu nazwanego dwa razy w jednym zapytaniu, a emulacja
        // jest w tym projekcie wyłączona (pilnuje tego `test_sql_parametry.php`).
        $parametry = [
            'tag_us'   => self::TAG_STRZAL,
            'tag_them' => self::TAG_STRZAL,
            'tag_all'  => self::TAG_STRZAL,
            'limit'    => max(1, min(200, $limit)),
        ];
        if ($seasonId !== null) {
            $parametry['sid'] = $seasonId;
        }
        if ($clubId !== null) {
            $parametry['club'] = $clubId;
        }

        return Db::all(
            "SELECT m.id, m.played_at, m.round, m.status,
                    h.name AS home_name, a.name AS away_name,
                    (" . self::SQL_RAPORT_ID . ") AS report_id,
                    (" . self::SQL_RAPORT_AT . ") AS report_at,
                    SUM(CASE WHEN e.team_side = 'us'   THEN e.is_goal ELSE 0 END) AS goals_us,
                    SUM(CASE WHEN e.team_side = 'them' THEN e.is_goal ELSE 0 END) AS goals_them,
                    SUM(CASE WHEN e.team_side = 'us'   AND e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg_us,
                    SUM(CASE WHEN e.team_side = 'them' AND e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg_them,
                    SUM(CASE WHEN e.team_side = 'us'   AND e.tag_name = :tag_us THEN 1 ELSE 0 END) AS shots_us,
                    SUM(CASE WHEN e.team_side = 'them' AND e.tag_name = :tag_them THEN 1 ELSE 0 END) AS shots_them,
                    -- Strzały meczu bez względu na stronę (golden layout W1): mecz ze
                    -- zdarzeniami, ale BEZ strzałów, pokazuje kreskę zamiast 0:0.
                    SUM(CASE WHEN e.tag_name = :tag_all THEN 1 ELSE 0 END) AS shots_all,
                    COUNT(e.id) AS events
               FROM matches m
               LEFT JOIN clubs h  ON h.id = m.club_home_id
               LEFT JOIN clubs a  ON a.id = m.club_away_id
               LEFT JOIN events e ON e.match_id = m.id
              {$warunek}
              GROUP BY m.id, m.played_at, m.round, m.status, h.name, a.name
              ORDER BY " . self::SQL_KOLEJNOSC_PULPITU . "
              LIMIT :limit",
            $parametry
        );
    }

    // ══════════════════════════════════════════════════════════════════════
    // MENU SEZONOWE (sesja 7 pivotu „viewer")
    //
    // WSZYSTKO PONIŻEJ TO ODCZYT. PHP nie liczy żadnej metryki piłkarskiej
    // (CLAUDE.md §4) — sumuje kolumny, które zapisał silnik, tak samo jak
    // `seasonMatches` wyżej. Jedyne, co robi tu PHP poza `SELECT`, to SCALANIE
    // dwóch zestawień zawodników po nazwie, bo ani MySQL, ani SQLite nie ma
    // `FULL OUTER JOIN`; sumy przychodzą gotowe z bazy.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Mecze klubu w sezonie — KOLEJNOŚĆ ROZGRYWKOWA, nie odwrotna chronologia.
     *
     * `seasonMatches()` sortuje od najnowszego, bo służy liście „ostatnie mecze".
     * Menu sezonowe jest listą KOLEJEK i czyta się je od pierwszej: kolejka 1
     * na górze, tak jak w tabeli ligowej.
     *
     * Sortujemy po `round` NUMERYCZNIE tam, gdzie się da. Kolumna jest napisem
     * (mieści „1/8 finału"), więc sam `ORDER BY round` dałby 1, 10, 11, 2 —
     * czyli kolejność, której nikt nie rozpozna jako kolejność.
     *
     * @return list<array<string,mixed>>
     */
    public static function seasonRounds(int $clubId, ?int $seasonId): array
    {
        $warunek = $seasonId !== null ? 'AND m.season_id = :sid' : '';
        $parametry = ['club' => $clubId, 'tag_us' => self::TAG_STRZAL,
                      'tag_them' => self::TAG_STRZAL, 'tag_all' => self::TAG_STRZAL];
        if ($seasonId !== null) {
            $parametry['sid'] = $seasonId;
        }

        return Db::all(
            "SELECT m.id, m.played_at, m.round, m.status, m.is_home,
                    m.score_us, m.score_them,
                    h.name AS home_name, a.name AS away_name,
                    SUM(CASE WHEN e.team_side = 'us'   THEN e.is_goal ELSE 0 END) AS goals_us,
                    SUM(CASE WHEN e.team_side = 'them' THEN e.is_goal ELSE 0 END) AS goals_them,
                    SUM(CASE WHEN e.team_side = 'us'   AND e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg_us,
                    SUM(CASE WHEN e.team_side = 'them' AND e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg_them,
                    SUM(CASE WHEN e.team_side = 'us'   AND e.tag_name = :tag_us THEN 1 ELSE 0 END) AS shots_us,
                    SUM(CASE WHEN e.team_side = 'them' AND e.tag_name = :tag_them THEN 1 ELSE 0 END) AS shots_them,
                    -- Strzały meczu bez względu na stronę (golden layout W1): mecz ze
                    -- zdarzeniami, ale BEZ strzałów, pokazuje kreskę zamiast 0:0.
                    SUM(CASE WHEN e.tag_name = :tag_all THEN 1 ELSE 0 END) AS shots_all,
                    COUNT(e.id) AS events
               FROM matches m
               LEFT JOIN clubs h  ON h.id = m.club_home_id
               LEFT JOIN clubs a  ON a.id = m.club_away_id
               LEFT JOIN events e ON e.match_id = m.id
              WHERE m.club_id = :club {$warunek}
              GROUP BY m.id, m.played_at, m.round, m.status, m.is_home,
                       m.score_us, m.score_them, h.name, a.name
              ORDER BY (m.round IS NULL OR m.round = ''),
                       CAST(m.round AS DECIMAL(10,0)), m.round,
                       (m.played_at IS NULL), m.played_at, m.id",
            $parametry
        );
    }

    /**
     * Sezony, w których klub ma mecze. Do przełącznika nad listą kolejek.
     *
     * WYŁĄCZNIE sezony z meczami: lista wszystkich sezonów systemu kazałaby
     * przeklikiwać puste, a pusty sezon nie jest wyborem, tylko ślepą uliczką.
     *
     * @return list<array<string,mixed>>
     */
    public static function seasonsWithMatches(int $clubId): array
    {
        return Db::all(
            "SELECT s.id, s.label, s.is_current, COUNT(m.id) AS matches
               FROM seasons s
               JOIN matches m ON m.season_id = s.id AND m.club_id = :club
              GROUP BY s.id, s.label, s.is_current
              ORDER BY s.label DESC",
            ['club' => $clubId]
        );
    }

    /**
     * Zawodnicy klubu: skład (mecze, minuty) scalony ze zdarzeniami
     * (strzały, xG, gole) PO PEŁNEJ NAZWIE, przez równość.
     *
     * DWA ŹRÓDŁA, BO OPISUJĄ CO INNEGO i żadne nie zastępuje drugiego:
     *
     *   `match_players` — kto był w protokole i ile zagrał. Zawodnik, który
     *     rozegrał 90 minut i nie zrobił nic, co analityk tagował, jest TYLKO tu.
     *   `events.player`  — kto co zrobił. Zawodnik, którego nikt nie wpisał do
     *     składu, a dostał taga, jest TYLKO tu — i to znaczy albo literówkę,
     *     albo skład, o którym zapomniano.
     *
     * Scalamy w PHP, bo `FULL OUTER JOIN` nie istnieje ani w MySQL, ani
     * w SQLite. Sumy przychodzą z bazy; PHP dokłada wyłącznie łączenie wierszy.
     *
     * Dopasowanie przez RÓWNOŚĆ CAŁEJ NAZWY (pułapka 7): fragment łapałby
     * „Nowak" wewnątrz „Nowakowski" i nikt by tego nie zauważył.
     *
     * @return list<array<string,mixed>>
     */
    /**
     * KADRA KLUBU W SEZONIE (golden layout W2, pkt 16 — do czasu modułu M7).
     *
     * Z `match_players`: numer i pozycja (ostatnie wpisane — numer zmienia się
     * rzadko, a „najczęstszy" wymagałby drugiego zapytania na zawodnika), mecze,
     * minuty i wyjścia w pierwszym składzie. Statystyki zdarzeń dokłada
     * `players()` — ten sam zestaw, który pokazuje ekran Zawodnicy.
     *
     * @return list<array<string,mixed>>
     */
    public static function kadra(int $clubId, ?int $seasonId = null): array
    {
        $warunek = $seasonId !== null ? 'AND m.season_id = :sid' : '';
        $p = ['club' => $clubId] + ($seasonId !== null ? ['sid' => $seasonId] : []);
        $kadra = Db::all(
            "SELECT mp.player, MAX(mp.number) AS number, MAX(mp.position) AS position,
                    COUNT(DISTINCT mp.match_id) AS matches,
                    SUM(CASE WHEN mp.minutes IS NOT NULL THEN mp.minutes ELSE 0 END) AS minutes,
                    SUM(CASE WHEN mp.minutes IS NOT NULL THEN 1 ELSE 0 END) AS with_minutes,
                    SUM(mp.is_starter) AS starts
               FROM match_players mp
               JOIN matches m ON m.id = mp.match_id
              WHERE mp.club_id = :club {$warunek}
              GROUP BY mp.player
              ORDER BY (MAX(mp.number) IS NULL), MAX(mp.number), mp.player",
            $p
        );
        $statystyki = [];
        foreach (self::players($clubId, $seasonId) as $z) {
            $statystyki[(string) $z['player']] = $z;
        }
        foreach ($kadra as &$k) {
            $st = $statystyki[(string) $k['player']] ?? null;
            $k['minutes'] = (int) $k['with_minutes'] > 0 ? (int) $k['minutes'] : null;
            $k['shots'] = $st['shots'] ?? 0;
            $k['goals'] = $st['goals'] ?? 0;
            $k['xg'] = $st['xg'] ?? 0.0;
            $k['events'] = $st['events'] ?? 0;
        }
        unset($k);
        return $kadra;
    }

    public static function players(int $clubId, ?int $seasonId = null): array
    {
        $warunek = $seasonId !== null ? 'AND m.season_id = :sid' : '';
        $parametrySkład = ['club' => $clubId];
        $parametryZdarzen = ['club' => $clubId, 'tag' => self::TAG_STRZAL];
        if ($seasonId !== null) {
            $parametrySkład['sid'] = $seasonId;
            $parametryZdarzen['sid'] = $seasonId;
        }

        $sklad = Db::all(
            "SELECT mp.player,
                    COUNT(DISTINCT mp.match_id) AS roster_matches,
                    SUM(CASE WHEN mp.minutes IS NOT NULL THEN mp.minutes ELSE 0 END) AS minutes,
                    SUM(CASE WHEN mp.minutes IS NOT NULL THEN 1 ELSE 0 END) AS with_minutes,
                    SUM(mp.is_starter) AS starts
               FROM match_players mp
               JOIN matches m ON m.id = mp.match_id
              WHERE mp.club_id = :club {$warunek}
              GROUP BY mp.player",
            $parametrySkład
        );

        $zdarzenia = Db::all(
            "SELECT e.player,
                    COUNT(DISTINCT e.match_id) AS event_matches,
                    SUM(CASE WHEN e.tag_name = :tag THEN 1 ELSE 0 END) AS shots,
                    SUM(CASE WHEN e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg,
                    SUM(e.is_goal) AS goals,
                    COUNT(e.id) AS events
               FROM events e
               JOIN matches m ON m.id = e.match_id
              WHERE m.club_id = :club AND e.player IS NOT NULL AND e.player <> '' {$warunek}
              GROUP BY e.player",
            $parametryZdarzen
        );

        $out = [];
        foreach ($sklad as $z) {
            $out[(string) $z['player']] = [
                'player'  => (string) $z['player'],
                'matches' => (int) $z['roster_matches'],
                // MINUTY TO `null`, GDY ŻADEN WIERSZ ICH NIE MIAŁ — nie zero.
                // „Nie podano minut" i „zagrał zero minut" to dwie różne rzeczy.
                'minutes' => (int) $z['with_minutes'] > 0 ? (int) $z['minutes'] : null,
                'starts'  => (int) $z['starts'],
                'shots'   => 0, 'xg' => 0.0, 'goals' => 0, 'events' => 0,
                'in_roster' => true,
            ];
        }

        foreach ($zdarzenia as $z) {
            $nazwa = (string) $z['player'];
            $wpis = $out[$nazwa] ?? [
                'player' => $nazwa, 'matches' => 0, 'minutes' => null, 'starts' => 0,
                'shots' => 0, 'xg' => 0.0, 'goals' => 0, 'events' => 0,
                'in_roster' => false,
            ];
            $wpis['matches'] = max((int) $wpis['matches'], (int) $z['event_matches']);
            $wpis['shots']  = (int) $z['shots'];
            $wpis['xg']     = round((float) $z['xg'], 2);
            $wpis['goals']  = (int) $z['goals'];
            $wpis['events'] = (int) $z['events'];
            $out[$nazwa] = $wpis;
        }

        $wiersze = array_values($out);
        usort($wiersze, static function (array $a, array $b): int {
            return [$b['matches'], $b['events'], $a['player']]
                <=> [$a['matches'], $a['events'], $b['player']];
        });
        return $wiersze;
    }

    /**
     * Mecze klubu w przedziale dat — do widoku miesięcznego kalendarza.
     *
     * Mecz BEZ DATY nie trafia do żadnego miesiąca i to jest poprawne: kalendarz
     * pokazuje, co i kiedy, a „nie wiadomo kiedy" nie ma gdzie stanąć. Widok
     * mówi o nich osobno, licznikiem — zamiast wieszać je na losowym dniu.
     *
     * @return list<array<string,mixed>>
     */
    public static function matchesBetween(int $clubId, string $od, string $do): array
    {
        return Db::all(
            "SELECT m.id, m.played_at, m.round, m.status, m.score_us, m.score_them,
                    h.name AS home_name, a.name AS away_name
               FROM matches m
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
              WHERE m.club_id = :club AND m.played_at >= :od AND m.played_at <= :do
              ORDER BY m.played_at, m.id",
            ['club' => $clubId, 'od' => $od, 'do' => $do]
        );
    }

    /** Ile meczów klubu nie ma daty — kalendarz mówi o nich osobno. */
    public static function matchesWithoutDate(int $clubId): int
    {
        $row = Db::one(
            'SELECT COUNT(*) AS c FROM matches WHERE club_id = :club AND played_at IS NULL',
            ['club' => $clubId]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Nazwa taga strzału. STAŁA, BO PHP NIE MA SŁOWNIKA TAGÓW.
     *
     * Raport liczy strzały po tej samej surowej nazwie (`e.tag==='STRZAŁ'`
     * w szablonie), więc trzecie miejsce z tą wartością byłoby trzecim
     * miejscem do rozjazdu. Klub, który nazywa strzał inaczej, zobaczy tu
     * zero — i to jest widoczny brak, nie cicha pomyłka.
     *
     * TODO(sesja 4): nazwa powinna przyjść z templatu klubu, tak jak reszta
     * słownika. Dziś templat nie jest czytany przez warstwę żądań.
     */
    public const TAG_STRZAL = 'STRZAŁ';

    /** Czas w formacie DATETIME. Jedno miejsce, żeby testy mogły go przesunąć. */
    public static function now(string $modify = 'now'): string
    {
        return (new \DateTimeImmutable($modify))->format('Y-m-d H:i:s');
    }
}
