<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Templaty raportów klubu — wersjonowane, dopisywane, nigdy nadpisywane.
 *
 * Templat definiuje, CO wchodzi do raportu klubu: zmienne (tag/etykieta →
 * pojęcie kanoniczne → etykieta wyświetlana → kolor → sekcje) i włączone sekcje.
 * Budowany raz w konfiguratorze z pierwszego importu, potem tylko edytowany.
 *
 * DWIE ZASADY, KTÓRE STOJĄ ZA KSZTAŁTEM TEJ KLASY:
 *
 * 1. Aktualny templat to MAX(version). Nie ma flagi „aktywny" — flaga pozwala
 *    mieć dwa aktywne wiersze albo zero, licznik nie pozwala na żadne z tych dwojga.
 *
 * 2. Zapis to ZAWSZE nowy wiersz. Poprzednie wersje zostają, ale nie są
 *    renderowalne: regeneracja raportu idzie pod wersję najnowszą, a numer na
 *    raporcie służy do wykrycia, że jest starszy niż templat klubu.
 *
 * Nazewnictwo klasy jak w reszcie projektu (`Clubs`, `Notes`, `IndexTerms`),
 * a nie `TemplateRepository` ze specyfikacji przebudowy — druga konwencja
 * nazewnicza w jednym katalogu kosztuje więcej, niż jest warta.
 */
final class ReportTemplates
{
    /**
     * Wersja struktury `config`. Podbijana, gdy zmienia się KSZTAŁT JSON-a,
     * a nie jego zawartość — silnik czyta po niej, jak interpretować plik.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Aktualny templat klubu albo null, gdy klub nie przeszedł konfiguratora.
     *
     * NULL jest normalnym stanem, nie awarią: klub założony przed chwilą nie ma
     * templatu i widok klubu ma pokazać CTA „Skonfiguruj raporty" (Sesja 2).
     *
     * @return array<string,mixed>|null
     */
    public static function current(int $clubId): ?array
    {
        return Db::one(
            'SELECT * FROM club_report_templates
              WHERE club_id = :club
              ORDER BY version DESC
              LIMIT 1',
            ['club' => $clubId]
        );
    }

    /** Numer aktualnej wersji albo 0, gdy klub nie ma jeszcze templatu. */
    public static function currentVersion(int $clubId): int
    {
        $row = Db::one(
            'SELECT MAX(version) AS v FROM club_report_templates WHERE club_id = :club',
            ['club' => $clubId]
        );
        return (int) ($row['v'] ?? 0);
    }

    /**
     * Konkretna wersja — do historii i do stempla na raporcie.
     *
     * @return array<string,mixed>|null
     */
    public static function version(int $clubId, int $version): ?array
    {
        return Db::one(
            'SELECT * FROM club_report_templates WHERE club_id = :club AND version = :v',
            ['club' => $clubId, 'v' => $version]
        );
    }

    /**
     * Historia wersji, od najnowszej. Bez `config` — lista wersji nie potrzebuje
     * treści, a te bywają duże.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $clubId): array
    {
        return Db::all(
            'SELECT t.id, t.version, t.created_at, t.created_by, u.display_name AS author
               FROM club_report_templates t
               LEFT JOIN users u ON u.id = t.created_by
              WHERE t.club_id = :club
              ORDER BY t.version DESC',
            ['club' => $clubId]
        );
    }

    /**
     * Zapis nowej wersji. Zwraca numer nadanej wersji.
     *
     * NUMER LICZONY W PĘTLI Z PONOWIENIEM, a nie raz przed INSERT-em: między
     * odczytem MAX(version) a zapisem może wejść drugi użytkownik. Baza pilnuje
     * tego kluczem UNIQUE(club_id, version) — kolizja oznacza, że ktoś nas
     * uprzedził, i wtedy liczymy numer jeszcze raz zamiast wywalać się na
     * użytkowniku. Trzy nieudane podejścia to już nie wyścig, tylko awaria.
     *
     * @param array<string,mixed> $config
     */
    public static function saveNewVersion(int $clubId, array $config, int $userId): int
    {
        $json = self::encodeConfig($config);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $version = self::currentVersion($clubId) + 1;
            try {
                Db::run(
                    'INSERT INTO club_report_templates (club_id, version, config, created_by, created_at)
                     VALUES (:club, :v, :config, :by, :now)',
                    [
                        'club'   => $clubId,
                        'v'      => $version,
                        'config' => $json,
                        'by'     => $userId,
                        'now'    => Stats::now(),
                    ]
                );
            } catch (\PDOException $e) {
                if (self::isDuplicate($e)) {
                    continue;
                }
                throw $e;
            }

            Audit::log('template.saved', $userId, 'club', $clubId, ['version' => $version]);
            return $version;
        }

        throw new \RuntimeException('Nie udało się nadać numeru wersji templatu klubu ' . $clubId . '.');
    }

    /**
     * Czy raport wygenerowany na wersji `$reportVersion` jest starszy niż templat.
     *
     * NULL po stronie raportu znaczy „sprzed ery templatów" i też jest
     * nieaktualny — ale tylko wtedy, gdy klub w ogóle ma już templat.
     */
    public static function isOutdated(int $clubId, ?int $reportVersion): bool
    {
        $current = self::currentVersion($clubId);
        if ($current === 0) {
            return false;
        }
        return $reportVersion === null || $reportVersion < $current;
    }

    /**
     * `config` jako tablica. Zwraca pustą tablicę przy treści nie do odczytania —
     * decyzję, co z tym zrobić, podejmuje wywołujący, bo w jednym miejscu
     * oznacza to „pokaż konfigurator od nowa", a w drugim „przerwij generowanie".
     *
     * @return array<string,mixed>
     */
    public static function decodeConfig(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Serializacja do kolumny. `JSON_UNESCAPED_UNICODE`, bo w etykietach siedzi
     * polska terminologia klienta i ma być czytelna także przy podglądzie wprost
     * z bazy — nie jako ciąg \uXXXX.
     *
     * Pełna walidacja struktury (unikalne `id`, sekcje z rejestru, kolory hex,
     * ograniczenie sekcji przy `canon: null`) należy do konfiguratora i powstaje
     * w Sesji 4. Tutaj jest wyłącznie to, bez czego wiersz w bazie byłby
     * bezużyteczny dla silnika.
     *
     * @param array<string,mixed> $config
     */
    public static function encodeConfig(array $config): string
    {
        $config['schema_version'] ??= self::SCHEMA_VERSION;

        if (!isset($config['variables']) || !is_array($config['variables'])) {
            throw new \InvalidArgumentException('Templat bez listy zmiennych — nie ma czego renderować.');
        }

        $json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Templat nie daje się zapisać jako JSON: ' . json_last_error_msg());
        }
        return $json;
    }

    /**
     * Naruszenie klucza UNIQUE — po SQLSTATE, nie po treści komunikatu.
     *
     * Komunikat różni się między MySQL a SQLite (testy chodzą na SQLite),
     * a kod 23000 jest ten sam w obu.
     */
    private static function isDuplicate(\PDOException $e): bool
    {
        return $e->getCode() === '23000';
    }
}
