<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Metryki piłkarskie liczone NA TABELI `events`, w SQL-u.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CZY TO NIE ŁAMIE CLAUDE.md §4 („PHP nie liczy żadnej metryki piłkarskiej")?
 *
 * NIE — i granica jest tu ostra, więc warto ją nazwać.
 *
 * §4 zabrania PHP ROZSTRZYGANIA, czym jest zdarzenie: czy ten strzał był golem,
 * skąd wzięło się xG, która drużyna jest „nasza". To wszystko policzył SILNIK
 * i zapisał w wierszach (migracja 014). Ten moduł nie podejmuje ani jednej
 * takiej decyzji — filtruje gotowe wiersze i sumuje gotowe kolumny.
 *
 * Gdyby liczył cokolwiek sam, byłby DRUGIM miejscem, w którym te same reguły
 * mogą się rozjechać — a rozjazd między raportem a pulpitem jest dokładnie tym
 * rodzajem błędu, którego nikt nie zauważy, bo obie liczby wyglądają sensownie.
 *
 * Dlatego agregacja idzie W SQL-u, nie w pętli PHP: baza sumuje kolumnę, PHP
 * odbiera jedną liczbę. Pętla po zdarzeniach byłaby zaproszeniem do dopisania
 * w niej warunku, a warunek w pętli to już reguła piłkarska.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * DEFINICJA METRYKI = FILTR + AGREGATOR. Filtr wybiera wiersze, agregator mówi,
 * co z nimi zrobić. Definicje domyślne leżą w `app/config/metryki_domyslne.php`;
 * w sesji 5 przejdą do templatu klubu i ten plik zniknie.
 */
final class Metrics
{
    /** Agregatory. `ratio` ma własną regułę zera — patrz `compute()`. */
    public const AGG_COUNT   = 'count';
    public const AGG_SUM_XG  = 'sum_xg';
    public const AGG_RATIO   = 'ratio';
    public const AGG_AVG     = 'avg_per_match';

    /**
     * Wartość metryki dla zakresu.
     *
     * @param array<string,mixed> $definicja  filtr + agregator (patrz plik definicji)
     * @param array<string,mixed> $zakres     club_id, season_id, match_id|match_ids
     * @return array{value:float|int|null, n:int|null, d:int|null, scope:string}
     */
    public static function compute(array $definicja, array $zakres): array
    {
        $agg = (string) ($definicja['agg'] ?? self::AGG_COUNT);
        $opisZakresu = self::opisZakresu($zakres);

        if ($agg === self::AGG_RATIO) {
            $licznik   = self::policz($definicja['filter'] ?? [], $zakres, self::AGG_COUNT);
            $mianownik = self::policz($definicja['of'] ?? [], $zakres, self::AGG_COUNT);

            /*
             * ZEROWY MIANOWNIK DAJE `null`, NIGDY ZERO.
             *
             * „0% wejść w SBZ zakończonych strzałem" i „nie było ani jednego
             * wejścia w SBZ" to dwa różne zdania o meczu. Zero w tym miejscu
             * mówi trenerowi, że jego drużyna wchodziła w SBZ i nic z tego nie
             * wynikło — a mogła nie wchodzić wcale, albo klub mógł nie tagować
             * tego zdarzenia. Ta sama zasada, co w pakiecie metryk silnika.
             */
            $wartosc = $mianownik > 0 ? round($licznik / $mianownik, 4) : null;

            return ['value' => $wartosc, 'n' => (int) $licznik, 'd' => (int) $mianownik,
                    'scope' => $opisZakresu];
        }

        if ($agg === self::AGG_AVG) {
            $suma  = self::policz($definicja['filter'] ?? [], $zakres, self::AGG_COUNT);
            $mecze = self::liczbaMeczow($zakres);
            $wartosc = $mecze > 0 ? round($suma / $mecze, 2) : null;

            return ['value' => $wartosc, 'n' => (int) $suma, 'd' => (int) $mecze,
                    'scope' => $opisZakresu];
        }

        $wartosc = self::policz($definicja['filter'] ?? [], $zakres, $agg);

        /*
         * BRAK ZDARZEŃ W ZAKRESIE TO `null`, nie zero — ta sama zasada, co przy
         * mianowniku. Mecz, dla którego nie ma ani jednego wiersza w `events`
         * (sprzed migracji 014 albo sprzed przeliczenia), nie ma metryki równej
         * zero; on jej po prostu nie ma.
         */
        if (self::liczbaZdarzen($zakres) === 0) {
            return ['value' => null, 'n' => 0, 'd' => null, 'scope' => $opisZakresu];
        }

        return ['value' => $agg === self::AGG_SUM_XG ? round((float) $wartosc, 2) : (int) $wartosc,
                'n' => null, 'd' => null, 'scope' => $opisZakresu];
    }

    /**
     * Komplet metryk z pliku definicji dla jednego zakresu.
     *
     * @return array{metrics:list<array<string,mixed>>, coverage:list<array<string,mixed>>}
     */
    public static function computeAll(array $zakres, ?array $definicje = null): array
    {
        $definicje ??= self::definicje();
        $katalog = self::katalogKlubu((int) ($zakres['club_id'] ?? 0));

        $metryki = [];
        $pokrycie = [];

        foreach ($definicje as $id => $d) {
            /*
             * BRAK TAGU W KATALOGU KLUBU = BRAK POKRYCIA, nie wynik zero.
             *
             * Klub, który nie taguje wejść w SBZ, ma dostać kreskę i wpis
             * w „Wymaga uwagi", a nie „0 wejść w SBZ". To jest cały powód,
             * dla którego `tag_catalog` w ogóle powstał (migracja 015).
             */
            $brakujace = self::brakujaceTagi($d, $katalog);

            if ($brakujace !== []) {
                $metryki[] = ['id' => (string) $id, 'label' => (string) ($d['label'] ?? $id),
                              'value' => null, 'n' => null, 'd' => null];
                $pokrycie[] = ['metric' => (string) $id, 'missing_tags' => $brakujace];
                continue;
            }

            $w = self::compute($d, $zakres);
            $metryki[] = ['id' => (string) $id, 'label' => (string) ($d['label'] ?? $id),
                          'value' => $w['value'], 'n' => $w['n'], 'd' => $w['d']];
        }

        return ['metrics' => $metryki, 'coverage' => $pokrycie];
    }

    /** Definicje domyślne. W sesji 5 przejdą do templatu klubu. */
    public static function definicje(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = require dirname(__DIR__) . '/config/metryki_domyslne.php';
        }
        return $cache;
    }

    /** Nazwy tagów klubu obecne w katalogu. Pusty katalog = brak sprawdzenia. */
    public static function katalogKlubu(int $clubId): array
    {
        if ($clubId <= 0) {
            return [];
        }
        $wiersze = Db::all(
            "SELECT name FROM tag_catalog WHERE club_id = :c AND kind = 'tag'",
            ['c' => $clubId]
        );
        return array_column($wiersze, 'name');
    }

    // ------------------------------------------------------------------ środek

    /**
     * Tagi definicji, których nie ma w katalogu klubu.
     *
     * PUSTY KATALOG NIE ZGŁASZA BRAKÓW. Klub sprzed migracji 015 nie ma jeszcze
     * ani jednego wpisu, a metryki dla niego działają — sprawdzenie pokrycia
     * ma pomagać, a nie wygaszać ekran przy pierwszym wdrożeniu.
     */
    private static function brakujaceTagi(array $definicja, array $katalog): array
    {
        if ($katalog === []) {
            return [];
        }

        $wymagane = [];
        foreach ([$definicja['filter'] ?? [], $definicja['of'] ?? []] as $filtr) {
            foreach ((array) ($filtr['tags'] ?? []) as $tag) {
                $wymagane[(string) $tag] = true;
            }
        }

        /*
         * ALIAS WYSTARCZY JEDEN. `ZDOBYCIE SBZ|SBZ PODAJĄCY` to dwie nazwy tego
         * samego zdarzenia w różnych wersjach tagowania — klub, który używa
         * drugiej, MA pokrycie. Wymaganie obu zgłaszałoby brak przy komplecie
         * danych.
         */
        $brak = array_values(array_diff(array_keys($wymagane), $katalog));
        return count($brak) === count($wymagane) ? $brak : [];
    }

    /** Jedna agregacja na `events` z filtrem definicji. */
    private static function policz(array $filtr, array $zakres, string $agg): float|int
    {
        [$where, $params] = self::warunki($filtr, $zakres);

        $wyrazenie = $agg === self::AGG_SUM_XG
            ? 'COALESCE(SUM(CASE WHEN e.xg IS NULL THEN 0 ELSE e.xg END), 0)'
            : 'COUNT(*)';

        $w = Db::one(
            'SELECT ' . $wyrazenie . ' AS wynik FROM events e WHERE ' . implode(' AND ', $where),
            $params
        );
        return $agg === self::AGG_SUM_XG ? (float) ($w['wynik'] ?? 0) : (int) ($w['wynik'] ?? 0);
    }

    private static function liczbaZdarzen(array $zakres): int
    {
        [$where, $params] = self::warunki([], $zakres);
        $w = Db::one('SELECT COUNT(*) AS c FROM events e WHERE ' . implode(' AND ', $where), $params);
        return (int) ($w['c'] ?? 0);
    }

    private static function liczbaMeczow(array $zakres): int
    {
        [$where, $params] = self::warunki([], $zakres);
        $w = Db::one(
            'SELECT COUNT(DISTINCT e.match_id) AS c FROM events e WHERE ' . implode(' AND ', $where),
            $params
        );
        return (int) ($w['c'] ?? 0);
    }

    /**
     * Warunki `WHERE` z filtra definicji i z zakresu.
     *
     * @return array{0:list<string>, 1:array<string,mixed>}
     */
    private static function warunki(array $filtr, array $zakres): array
    {
        $where = ['1 = 1'];
        $params = [];
        $i = 0;

        // ── zakres ────────────────────────────────────────────────────────
        $mecze = self::meczeZakresu($zakres);
        if ($mecze === []) {
            // Zakres bez ani jednego meczu — warunek niemożliwy do spełnienia.
            // Lepszy niż brak warunku: ten policzyłby CAŁĄ tabelę.
            return [['1 = 0'], []];
        }
        $miejsca = [];
        foreach ($mecze as $mid) {
            $klucz = 'm' . $i++;
            $miejsca[] = ':' . $klucz;
            $params[$klucz] = (int) $mid;
        }
        $where[] = 'e.match_id IN (' . implode(', ', $miejsca) . ')';

        // ── tagi (z aliasami) ─────────────────────────────────────────────
        if (!empty($filtr['tags'])) {
            $miejsca = [];
            foreach ((array) $filtr['tags'] as $tag) {
                $klucz = 't' . $i++;
                $miejsca[] = ':' . $klucz;
                $params[$klucz] = (string) $tag;
            }
            $where[] = 'e.tag_name IN (' . implode(', ', $miejsca) . ')';
        }

        // ── etykiety ──────────────────────────────────────────────────────
        foreach ((array) ($filtr['has_label'] ?? []) as $etykieta) {
            [$sql, $p] = self::warunekEtykiety((string) $etykieta, $i++, true);
            $where[] = $sql;
            $params += $p;
        }
        foreach ((array) ($filtr['no_label'] ?? []) as $etykieta) {
            [$sql, $p] = self::warunekEtykiety((string) $etykieta, $i++, false);
            $where[] = $sql;
            $params += $p;
        }

        // ── strona ────────────────────────────────────────────────────────
        if (!empty($filtr['team_side'])) {
            $strony = (array) $filtr['team_side'];
            $miejsca = [];
            foreach ($strony as $s) {
                $klucz = 's' . $i++;
                $miejsca[] = ':' . $klucz;
                $params[$klucz] = (string) $s;
            }
            $where[] = 'e.team_side IN (' . implode(', ', $miejsca) . ')';
        }

        // ── połowa i zakres minut ─────────────────────────────────────────
        if (!empty($filtr['half'])) {
            $where[] = 'e.half = :half';
            $params['half'] = (int) $filtr['half'];
        }
        if (isset($filtr['minute_from'])) {
            $where[] = 'e.minute >= :min_od';
            $params['min_od'] = (int) $filtr['minute_from'];
        }
        if (isset($filtr['minute_to'])) {
            $where[] = 'e.minute <= :min_do';
            $params['min_do'] = (int) $filtr['minute_to'];
        }

        return [$where, $params];
    }

    /**
     * Warunek „etykieta jest / nie jest na liście etykiet zdarzenia".
     *
     * ═══════════════════════════════════════════════════════════════════════
     * DWA SILNIKI, DWIE SKŁADNIE — i to nie jest nadmiarowość.
     *
     * Produkcja to MariaDB: `JSON_CONTAINS()`, wymaga 10.2.3+ (patrz nagłówek
     * migracji 015). Testy chodzą na SQLite, gdzie tej funkcji NIE MA — jest
     * za to `json_each()` z rozszerzenia JSON1.
     *
     * Kuszące byłoby `labels_json LIKE '%PRESSING%'` jako wspólny mianownik.
     * Odpada: `LIKE` nie odróżnia etykiety `PRESSING` od `PRESSING WYSOKI`,
     * więc metryka „SBZ po pressingu" liczyłaby oba i zawyżała wynik bez śladu.
     * To ta sama klasa błędu, co `substring` zamiast równości przy tagach
     * (CLAUDE.md, pułapka 7: `CELNY` wewnątrz `NIECELNY`).
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function warunekEtykiety(string $etykieta, int $nr, bool $ma): array
    {
        $klucz = 'lab' . $nr;
        $negacja = $ma ? '' : 'NOT ';

        if (Db::driver() === 'sqlite') {
            // `json_each` rozkłada tablicę na wiersze; porównujemy przez RÓWNOŚĆ.
            $sql = $negacja . 'EXISTS (SELECT 1 FROM json_each(e.labels_json) je'
                 . ' WHERE je.value = :' . $klucz . ')';
            return [$sql, [$klucz => $etykieta]];
        }

        // MariaDB/MySQL: wartość szukana musi być poprawnym dokumentem JSON,
        // stąd cudzysłowy wokół napisu.
        $sql = $negacja . 'JSON_CONTAINS(e.labels_json, :' . $klucz . ') = 1';
        return [$sql, [$klucz => json_encode($etykieta, JSON_UNESCAPED_UNICODE)]];
    }

    /**
     * Mecze objęte zakresem.
     *
     * `match_id` → jedna kolejka. Brak `match_id` → SUMA: ta sama metryka
     * policzona dla wszystkich meczów sezonu (albo klubu, gdy sezonu nie podano).
     * To jest cała różnica między kolejką a sumą — nie osobna definicja metryki.
     *
     * @return list<int>
     */
    private static function meczeZakresu(array $zakres): array
    {
        if (!empty($zakres['match_id'])) {
            return [(int) $zakres['match_id']];
        }
        if (!empty($zakres['match_ids'])) {
            return array_map('intval', (array) $zakres['match_ids']);
        }

        $where = [];
        $params = [];
        if (!empty($zakres['club_id'])) {
            $where[] = 'club_id = :c';
            $params['c'] = (int) $zakres['club_id'];
        }
        if (!empty($zakres['season_id'])) {
            $where[] = 'season_id = :s';
            $params['s'] = (int) $zakres['season_id'];
        }
        if ($where === []) {
            return [];
        }

        $wiersze = Db::all(
            'SELECT id FROM matches WHERE ' . implode(' AND ', $where) . ' ORDER BY id',
            $params
        );
        return array_map('intval', array_column($wiersze, 'id'));
    }

    /** Opis zakresu do odpowiedzi — żeby klient wiedział, co dostał. */
    public static function opisZakresu(array $zakres): string
    {
        if (!empty($zakres['match_id'])) {
            return 'match:' . (int) $zakres['match_id'];
        }
        if (!empty($zakres['season_id'])) {
            return 'season:' . (int) $zakres['season_id'];
        }
        if (!empty($zakres['club_id'])) {
            return 'club:' . (int) $zakres['club_id'];
        }
        return 'empty';
    }
}
