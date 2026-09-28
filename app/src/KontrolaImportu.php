<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Niezmienniki importu po stronie panelu (W7 H) i alert dla admina.
 *
 * Silnik sprawdza to, co widzi sam (`meta.niezmienniki`: wiersze CSV =
 * zdarzenia = suma warstwy 1, każdy tag w warstwie 1, xG, kierunek). Dwóch
 * rzeczy silnik nie widzi, bo nie chodzi do bazy (CLAUDE.md §4):
 *
 * - czy do tabeli `events` trafiło tyle zdarzeń, ile wierszy ma CSV,
 * - czy każdy zawodnik z pliku jest na ekranie Zawodnicy (`Stats::players`
 *   i `Stats::rivalPlayers` — dokładnie te zapytania, które ekran wykonuje).
 *
 * Naruszenie niczego nie „naprawia": zapisuje `imports.niezmienniki_ok = 0`,
 * dopisuje wynik do pokrycia (karta meczu pokazuje baner) i daje alert admina.
 */
final class KontrolaImportu
{
    /**
     * Sprawdza, zapisuje i zwraca PEŁNĄ listę niezmienników (silnik + panel).
     *
     * @param array<string,mixed> $meta meta.json z przebiegu budowy
     * @return list<array{kod:string, ok:bool, opis:string}>
     */
    public static function sprawdz(int $importId, int $matchId, array $meta): array
    {
        $lista = array_values(array_filter(
            (array) ($meta['niezmienniki'] ?? []),
            static fn($n): bool => is_array($n) && isset($n['kod'])
        ));

        $wiersze = isset($meta['coverage']['events']) ? (int) $meta['coverage']['events'] : null;
        $wBazie = (int) (Db::one('SELECT COUNT(*) AS n FROM events WHERE match_id = :m', ['m' => $matchId])['n'] ?? 0);
        if ($wiersze !== null) {
            $lista[] = [
                'kod'  => 'zdarzenia_w_bazie',
                'ok'   => $wBazie === $wiersze,
                'opis' => sprintf('wiersze CSV %d = zdarzenia zapisane %d', $wiersze, $wBazie),
            ];
        }

        $mecz = Db::one('SELECT club_id, season_id FROM matches WHERE id = :m', ['m' => $matchId]);
        $gracze = array_map(
            static fn(array $w): string => (string) $w['player'],
            Db::all("SELECT DISTINCT player FROM events
                      WHERE match_id = :m AND player IS NOT NULL AND player <> ''", ['m' => $matchId])
        );
        if ($mecz !== null && $mecz['club_id'] !== null && $gracze !== []) {
            $sezon = $mecz['season_id'] !== null ? (int) $mecz['season_id'] : null;
            $naEkranie = [];
            foreach (array_merge(Stats::players((int) $mecz['club_id'], $sezon),
                                 Stats::rivalPlayers((int) $mecz['club_id'], $sezon)) as $p) {
                $naEkranie[(string) $p['player']] = true;
            }
            $brak = array_values(array_filter($gracze, static fn(string $g): bool => !isset($naEkranie[$g])));
            $lista[] = [
                'kod'  => 'zawodnicy_na_ekranie',
                'ok'   => $brak === [],
                // Liczby, nie nazwiska — opis trafia do logu i do alertu.
                'opis' => sprintf('zawodnicy z pliku na ekranie Zawodnicy: %d z %d',
                    count($gracze) - count($brak), count($gracze)),
            ];
        }

        $ok = $lista === [] ? null : !in_array(false, array_map(static fn(array $n): bool => (bool) $n['ok'], $lista), true);

        // Pełna lista wraca do pokrycia — karta meczu czyta ją stamtąd.
        $import = Imports::find($importId);
        $cov = json_decode((string) ($import['coverage_json'] ?? ''), true);
        if (is_array($cov)) {
            $cov['niezmienniki'] = $lista;
            Db::run('UPDATE imports SET coverage_json = :c, niezmienniki_ok = :ok WHERE id = :id', [
                'c'  => json_encode($cov, JSON_UNESCAPED_UNICODE),
                'ok' => $ok === null ? null : ($ok ? 1 : 0),
                'id' => $importId,
            ]);
        }
        if ($ok === false) {
            error_log(sprintf('[W7] import %d meczu %d: raport niezgodny z plikiem — %s', $importId, $matchId,
                implode('; ', array_map(static fn(array $n): string => (string) $n['opis'],
                    array_filter($lista, static fn(array $n): bool => !$n['ok'])))));
        }
        return $lista;
    }

    /**
     * Alert admina: mecze, których NAJNOWSZY import ma naruszony niezmiennik.
     *
     * @return list<array{level:string, code:string, msg:string, hint:string, count:int}>
     */
    public static function alerty(): array
    {
        $wiersze = Db::all(
            'SELECT i.match_id FROM imports i
              WHERE i.niezmienniki_ok = 0
                AND i.id = (SELECT MAX(i2.id) FROM imports i2 WHERE i2.match_id = i.match_id)'
        );
        if ($wiersze === []) {
            return [];
        }
        $mecze = array_map(static fn(array $w): string => '#' . (int) $w['match_id'], $wiersze);
        return [[
            'level' => Alerts::LEVEL_ERROR,
            'code'  => 'RAPORT_NIEZGODNY_Z_PLIKIEM',
            'msg'   => 'Raport niezgodny z plikiem LiveTag: mecz ' . implode(', ', $mecze) . '.',
            'hint'  => 'Karta meczu → Dane: lista niezmienników. Zgłoś wykonawcy z numerem meczu — to błąd silnika albo zapisu, nie tagowania.',
            'count' => count($wiersze),
        ]];
    }
}
