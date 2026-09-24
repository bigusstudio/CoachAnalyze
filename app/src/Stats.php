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
    public static function counters(): array
    {
        $season = self::currentSeason();

        if ($season !== null) {
            $matches = (int) Db::one(
                'SELECT COUNT(*) AS c FROM matches WHERE season_id = :sid',
                ['sid' => $season['id']]
            )['c'];
            $scope = (string) $season['label'];
        } else {
            // Brak sezonu oznaczonego jako bieżący to stan normalny na starcie.
            // Pokazujemy wtedy sumę wszystkich meczów i mówimy o tym wprost,
            // zamiast wyświetlać zero, które wygląda jak błąd danych.
            $matches = (int) Db::one('SELECT COUNT(*) AS c FROM matches')['c'];
            $scope = View::t('dash.all_seasons');
        }

        return [
            'matches'       => $matches,
            'matches_scope' => $scope,
            'reports'       => (int) Db::one('SELECT COUNT(*) AS c FROM reports')['c'],
            // Czas podajemy parametrem, a nie przez NOW(): zapytanie daje się wtedy
            // uruchomić poza MySQL-em (testy) i nie zależy od zegara serwera bazy.
            'links'         => (int) Db::one(
                'SELECT COUNT(*) AS c FROM share_links
                  WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > :now)',
                ['now' => self::now()]
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

    /**
     * Ostatnie mecze do tabeli na pulpicie.
     *
     * Kluby dołączamy przez LEFT JOIN — mecz bez przypisanych klubów jest
     * poprawnym stanem tuż po imporcie i nie może wypaść z listy.
     *
     * @return list<array<string,mixed>>
     */
    public static function recentMatches(int $limit = 5): array
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
              ORDER BY (m.played_at IS NULL), m.played_at DESC, m.id DESC
              LIMIT :limit',
            ['limit' => max(1, min(50, $limit))]
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
    public static function railCounters(): array
    {
        $liczniki = self::counters();

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
    public static function lastFinishedMatch(): ?array
    {
        return Db::one(
            "SELECT m.id, m.played_at, m.round, m.competition, m.status,
                    m.club_home_id, m.club_away_id,
                    h.name AS home_name, h.crest_path AS home_crest,
                    a.name AS away_name, a.crest_path AS away_crest,
                    s.label AS season_label
               FROM matches m
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
               LEFT JOIN seasons s ON s.id = m.season_id
              WHERE m.status = 'done'
              ORDER BY (m.played_at IS NULL), m.played_at DESC, m.id DESC
              LIMIT 1"
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
    public static function seasonMatches(?int $seasonId, int $limit = 40): array
    {
        $warunek = $seasonId !== null ? 'WHERE m.season_id = :sid' : '';
        // OSOBNE SYMBOLE DLA KAŻDEGO WYSTĄPIENIA. PDO bez emulacji nie przyjmuje
        // tego samego symbolu nazwanego dwa razy w jednym zapytaniu, a emulacja
        // jest w tym projekcie wyłączona (pilnuje tego `test_sql_parametry.php`).
        $parametry = [
            'tag_us'   => self::TAG_STRZAL,
            'tag_them' => self::TAG_STRZAL,
            'limit'    => max(1, min(200, $limit)),
        ];
        if ($seasonId !== null) {
            $parametry['sid'] = $seasonId;
        }

        return Db::all(
            "SELECT m.id, m.played_at, m.round, m.status,
                    h.name AS home_name, a.name AS away_name,
                    SUM(CASE WHEN e.team_side = 'us'   THEN e.is_goal ELSE 0 END) AS goals_us,
                    SUM(CASE WHEN e.team_side = 'them' THEN e.is_goal ELSE 0 END) AS goals_them,
                    SUM(CASE WHEN e.team_side = 'us'   AND e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg_us,
                    SUM(CASE WHEN e.team_side = 'them' AND e.xg IS NOT NULL THEN e.xg ELSE 0 END) AS xg_them,
                    SUM(CASE WHEN e.team_side = 'us'   AND e.tag_name = :tag_us THEN 1 ELSE 0 END) AS shots_us,
                    SUM(CASE WHEN e.team_side = 'them' AND e.tag_name = :tag_them THEN 1 ELSE 0 END) AS shots_them,
                    COUNT(e.id) AS events
               FROM matches m
               LEFT JOIN clubs h  ON h.id = m.club_home_id
               LEFT JOIN clubs a  ON a.id = m.club_away_id
               LEFT JOIN events e ON e.match_id = m.id
              {$warunek}
              GROUP BY m.id, m.played_at, m.round, m.status, h.name, a.name
              ORDER BY (m.played_at IS NULL), m.played_at DESC, m.id DESC
              LIMIT :limit",
            $parametry
        );
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
