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
        $limit = max(1, min(50, $limit));
        return Db::all(
            'SELECT m.id, m.played_at, m.status, m.competition,
                    h.name AS home_name, a.name AS away_name
               FROM matches m
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
              ORDER BY (m.played_at IS NULL), m.played_at DESC, m.id DESC
              LIMIT ' . $limit
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

    /** Czas w formacie DATETIME. Jedno miejsce, żeby testy mogły go przesunąć. */
    public static function now(string $modify = 'now'): string
    {
        return (new \DateTimeImmutable($modify))->format('Y-m-d H:i:s');
    }
}
