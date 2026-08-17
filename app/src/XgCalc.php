<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Kalkulator xG (M3) — warstwa ODCZYTU, nie liczenia.
 *
 * PHP nie liczy żadnej metryki piłkarskiej (CLAUDE.md §4), a warstwa żądań
 * nie może uruchomić Pythona (disable_functions). Interaktywne boisko działa
 * więc na SIATCE policzonej przez silnik: `python -m coachanalyze xg-grid`
 * zapisuje app/src/data/xg_grid.json, a ta klasa robi wyłącznie odczyt
 * najbliższej komórki — dokładnie tak, jak ekran pokrycia czyta coverage_json.
 *
 * Boisko w panelu jest formularzem BEZ skryptu: `<input type="image">` wysyła
 * współrzędne kliknięcia w pikselach obrazka razem z resztą pól — czysty HTML,
 * zasada „panel bez JS" (CLAUDE.md §9) pozostaje nietknięta.
 *
 * Zastrzeżenie o kalibracji modelu: IndexTerms::XG_ZASTRZEZENIE.
 */
final class XgCalc
{
    /** Skala obrazka boiska: 5 px = 1 m (SVG 525×340 dla boiska 105×68). */
    public const PX_NA_METR = 5;

    /** @var array<string,mixed>|null */
    private static ?array $siatka = null;

    public static function gridPath(): string
    {
        return dirname(__DIR__) . '/src/data/xg_grid.json';
    }

    /** @return array<string,mixed>|null null = brak/uszkodzony artefakt siatki */
    public static function grid(): ?array
    {
        if (self::$siatka !== null) {
            return self::$siatka;
        }
        $sciezka = self::gridPath();
        if (!is_file($sciezka)) {
            error_log('xg: brak artefaktu siatki ' . $sciezka
                . ' — wygeneruj: python -m coachanalyze xg-grid --out ' . $sciezka);
            return null;
        }
        $dane = json_decode((string) file_get_contents($sciezka), true);
        if (!is_array($dane) || !isset($dane['models'], $dane['step'])) {
            error_log('xg: uszkodzony artefakt siatki ' . $sciezka);
            return null;
        }
        return self::$siatka = $dane;
    }

    /**
     * Wartość xG dla pozycji w metrach — odczyt najbliższej komórki siatki.
     *
     * @param string $bodyPart  foot | head
     * @param string $situation open | free_kick | penalty
     * @return array{xg: float, engine_version: ?string}|null
     */
    public static function lookup(float $x, float $y, string $bodyPart, string $situation): ?array
    {
        $siatka = self::grid();
        if ($siatka === null) {
            return null;
        }

        if ($situation === 'penalty') {
            // Karny to stała — pozycja kliknięcia jest nieistotna z definicji.
            return [
                'xg'             => (float) $siatka['penalty'],
                'engine_version' => $siatka['engine_version'] ?? null,
            ];
        }

        $model = $situation === 'free_kick'
            ? 'free_kick'
            : ($bodyPart === 'head' ? 'open_head' : 'open_foot');

        $rzedy = $siatka['models'][$model] ?? null;
        if (!is_array($rzedy)) {
            return null;
        }

        $krok = (float) $siatka['step'];
        $iy = (int) min(max(floor($y / $krok), 0), count($rzedy) - 1);
        $ix = (int) min(max(floor($x / $krok), 0), count($rzedy[$iy]) - 1);

        return [
            'xg'             => (float) $rzedy[$iy][$ix],
            'engine_version' => $siatka['engine_version'] ?? null,
        ];
    }

    /** Kliknięcie w obrazek boiska (piksele) → metry. */
    public static function pxToMeters(int $px, int $py): array
    {
        return [
            round($px / self::PX_NA_METR, 1),
            round($py / self::PX_NA_METR, 1),
        ];
    }

    /**
     * Ocena jakości sytuacji — OPIS wartości, nie nowa metryka.
     * Progi są prezentacją tej samej liczby, jak formatowanie procentów.
     */
    public static function quality(float $xg): string
    {
        return match (true) {
            $xg >= 0.30 => 'xg.quality.top',
            $xg >= 0.15 => 'xg.quality.good',
            $xg >= 0.05 => 'xg.quality.avg',
            default     => 'xg.quality.low',
        };
    }

    // ---------------------------------------------------------------- lista

    /** @return list<array<string,mixed>> */
    public static function shots(int $userId): array
    {
        return Db::all(
            'SELECT s.*, m.played_at
               FROM xg_manual_shots s
               LEFT JOIN matches m ON m.id = s.match_id
              WHERE s.user_id = :uid
              ORDER BY s.id DESC',
            ['uid' => $userId]
        );
    }

    public static function sum(int $userId): float
    {
        $suma = 0.0;
        foreach (self::shots($userId) as $strzal) {
            $suma += (float) $strzal['xg'];
        }
        return round($suma, 2);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id, int $userId): ?array
    {
        $wiersz = Db::one('SELECT * FROM xg_manual_shots WHERE id = :id', ['id' => $id]);
        // Filtr właściciela w porównaniu, nie w komunikacie — cudzy wpis
        // wygląda jak nieistniejący.
        return $wiersz !== null && (int) $wiersz['user_id'] === $userId ? $wiersz : null;
    }

    /**
     * Dodanie strzału. Wartość xG odczytana z siatki TERAZ i zapisana —
     * lista nie może zmieniać się wstecznie po rekalibracji modelu.
     *
     * @return int|null null = poza boiskiem albo brak siatki
     */
    public static function add(
        int $userId,
        float $x,
        float $y,
        string $bodyPart,
        string $situation,
        ?int $matchId = null
    ): ?int {
        if ($x < 0 || $x > 105 || $y < 0 || $y > 68) {
            return null;
        }
        $bodyPart = $bodyPart === 'head' ? 'head' : 'foot';
        $situation = in_array($situation, ['open', 'free_kick', 'penalty'], true) ? $situation : 'open';

        $wynik = self::lookup($x, $y, $bodyPart, $situation);
        if ($wynik === null) {
            return null;
        }

        Db::run(
            'INSERT INTO xg_manual_shots (user_id, match_id, x, y, body_part, situation, xg, created_at)
             VALUES (:uid, :mid, :x, :y, :body, :sit, :xg, :now)',
            [
                'uid'  => $userId,
                'mid'  => $matchId,
                'x'    => $x,
                'y'    => $y,
                'body' => $bodyPart,
                'sit'  => $situation,
                'xg'   => $wynik['xg'],
                'now'  => Stats::now(),
            ]
        );
        return (int) Db::pdo()->lastInsertId();
    }

    /** Edycja = ponowny odczyt siatki dla nowych parametrów. */
    public static function update(
        int $id,
        int $userId,
        float $x,
        float $y,
        string $bodyPart,
        string $situation
    ): bool {
        if (self::find($id, $userId) === null) {
            return false;
        }
        if ($x < 0 || $x > 105 || $y < 0 || $y > 68) {
            return false;
        }
        $bodyPart = $bodyPart === 'head' ? 'head' : 'foot';
        $situation = in_array($situation, ['open', 'free_kick', 'penalty'], true) ? $situation : 'open';

        $wynik = self::lookup($x, $y, $bodyPart, $situation);
        if ($wynik === null) {
            return false;
        }

        Db::run(
            'UPDATE xg_manual_shots
                SET x = :x, y = :y, body_part = :body, situation = :sit, xg = :xg
              WHERE id = :id',
            ['x' => $x, 'y' => $y, 'body' => $bodyPart, 'sit' => $situation,
             'xg' => $wynik['xg'], 'id' => $id]
        );
        return true;
    }

    public static function delete(int $id, int $userId): bool
    {
        if (self::find($id, $userId) === null) {
            return false;
        }
        Db::run('DELETE FROM xg_manual_shots WHERE id = :id', ['id' => $id]);
        return true;
    }
}
