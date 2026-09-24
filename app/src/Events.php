<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Tabela zdarzeń meczu (migracja 014). Zapis z artefaktu `--out-events` silnika.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PHP NIE LICZY TU NICZEGO (CLAUDE.md §4). Minuta, połowa, strona drużyny
 * i przypisanie gola przychodzą POLICZONE z silnika — ta klasa przepisuje
 * wiersze do bazy i tyle. Gdyby cokolwiek z tego liczyło się tutaj, byłoby
 * drugim miejscem, w którym te same reguły mogą się rozjechać.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ZDARZENIA SĄ ODTWARZALNE, RAPORT NIE. Dlatego awaria zapisu do tej tabeli
 * nie unieważnia raportu: surowy eksport leży na dysku i zdarzenia da się
 * wstawić ponownie przeliczeniem. Wywrócenie zadania z powodu tabeli
 * pomocniczej kosztowałoby operatora gotowy raport.
 */
final class Events
{
    /**
     * Ile wierszy w jednym `INSERT`. Przy 294 zdarzeniach i 17 kolumnach to
     * jedno zapytanie; przy dużym eksporcie kilka. Granica jest po to, żeby
     * nie zbliżać się do `max_allowed_packet` ani do limitu parametrów PDO.
     */
    public const PACZKA = 500;

    /**
     * Podmiana zdarzeń meczu: kasujemy stare, wstawiamy nowe, w JEDNEJ transakcji.
     *
     * KASUJEMY CAŁOŚĆ, NIE SCALAMY. Reimport i przeliczenie mogą dać inną liczbę
     * zdarzeń niż poprzednio (nowa wersja tagowania, poprawiony eksport), a
     * scalanie po jakimkolwiek kluczu wymagałoby identyfikatora zdarzenia,
     * którego eksport LiveTag nie niesie. Zdarzenia są odtwarzalne z pliku
     * źródłowego, więc podmiana w całości jest tańsza niż wymyślony klucz.
     *
     * TRANSAKCJA JEST OBOWIĄZKOWA: bez niej nieudany `INSERT` zostawiałby mecz
     * z połową zdarzeń — stanem, który wygląda jak poprawny i którego nic
     * nie zgłasza.
     *
     * @param list<array<string,mixed>> $zdarzenia wiersze z artefaktu silnika
     * @return int liczba wstawionych wierszy
     */
    public static function replaceForMatch(int $matchId, ?int $importId, array $zdarzenia): int
    {
        $pdo = Db::pdo();
        $wTransakcji = $pdo->inTransaction();

        if (!$wTransakcji) {
            $pdo->beginTransaction();
        }

        try {
            Db::run('DELETE FROM events WHERE match_id = :m', ['m' => $matchId]);

            $wstawione = 0;
            foreach (array_chunk($zdarzenia, self::PACZKA) as $paczka) {
                $wstawione += self::wstawPaczke($matchId, $importId, $paczka);
            }

            if (!$wTransakcji) {
                $pdo->commit();
            }
            return $wstawione;
        } catch (\Throwable $e) {
            if (!$wTransakcji && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Zdarzenia meczu w kolejności czasu. Do ekranów i eksportów. */
    public static function forMatch(int $matchId): array
    {
        return Db::all(
            'SELECT * FROM events WHERE match_id = :m ORDER BY t_ms, id',
            ['m' => $matchId]
        );
    }

    /** Ile zdarzeń ma mecz. Tanie sprawdzenie „czy w ogóle zapisane". */
    public static function countForMatch(int $matchId): int
    {
        $w = Db::one('SELECT COUNT(*) AS ile FROM events WHERE match_id = :m', ['m' => $matchId]);
        return (int) ($w['ile'] ?? 0);
    }

    /**
     * Jeden `INSERT` na całą paczkę.
     *
     * Wiersz po wierszu przy 294 zdarzeniach to 294 przejścia tam i z powrotem
     * do bazy — przy imporcie w kolejce nie boli, ale przy zbiorczym przeliczeniu
     * całego sezonu już tak. Parametry są NAZWANE Z NUMEREM PORZĄDKOWYM
     * (`:tag_0`, `:tag_1`…), bo `Db` przyjmuje wyłącznie nazwane.
     *
     * @param list<array<string,mixed>> $paczka
     */
    private static function wstawPaczke(int $matchId, ?int $importId, array $paczka): int
    {
        if ($paczka === []) {
            return 0;
        }

        $kolumny = ['match_id', 'import_id', 'tag_name', 'labels_json', 'team', 'team_side',
                    'player', 't_ms', 't_end_ms', 'half', 'minute', 'xg', 'xg_source',
                    'x', 'y', 'tx', 'ty', 'is_goal'];

        $wiersze = [];
        $parametry = [];

        foreach ($paczka as $i => $z) {
            $miejsca = [];
            foreach ($kolumny as $kol) {
                $klucz = $kol . '_' . $i;
                $miejsca[] = ':' . $klucz;
                $parametry[$klucz] = self::wartosc($kol, $z, $matchId, $importId);
            }
            $wiersze[] = '(' . implode(', ', $miejsca) . ')';
        }

        Db::run(
            'INSERT INTO events (' . implode(', ', $kolumny) . ') VALUES ' . implode(', ', $wiersze),
            $parametry
        );

        return count($paczka);
    }

    /**
     * Wartość jednej kolumny wiersza.
     *
     * NULL ZOSTAJE NULL-em. `team`, `player`, `xg` i współrzędne bywają puste
     * i to jest poprawny stan, nie brak do załatania: pusta kolumna drużyny
     * dotyczy większości zdarzeń (pułapka 5), a zero w `xg` znaczyłoby
     * „policzono zero", a nie „nie było wartości".
     */
    private static function wartosc(string $kolumna, array $z, int $matchId, ?int $importId): mixed
    {
        return match ($kolumna) {
            'match_id'    => $matchId,
            'import_id'   => $importId,
            'tag_name'    => (string) ($z['tag_name'] ?? ''),
            // Etykiety jako JSON — kolumna jest typu JSON, więc kodujemy tutaj
            // i nie pozwalamy, żeby gdziekolwiek indziej powstał drugi zapis.
            'labels_json' => json_encode(array_values((array) ($z['labels'] ?? [])),
                                         JSON_UNESCAPED_UNICODE),
            'team_side'   => in_array($z['team_side'] ?? null, ['us', 'them', 'none'], true)
                                ? $z['team_side'] : 'none',
            'is_goal'     => !empty($z['is_goal']) ? 1 : 0,
            't_ms', 'half', 'minute' => (int) ($z[$kolumna] ?? 0),
            't_end_ms'    => isset($z['t_end_ms']) && $z['t_end_ms'] !== null
                                ? (int) $z['t_end_ms'] : null,
            'xg', 'x', 'y', 'tx', 'ty' => isset($z[$kolumna]) && $z[$kolumna] !== null
                                ? (float) $z[$kolumna] : null,
            'xg_source'   => in_array($z['xg_source'] ?? null, ['analyst', 'model'], true)
                                ? $z['xg_source'] : null,
            default       => isset($z[$kolumna]) && $z[$kolumna] !== '' ? $z[$kolumna] : null,
        };
    }
}
