<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Kolejka zadań — podgląd i ponowienie.
 *
 * PHP nie uruchamia silnika z żądania HTTP. Ponowienie ustawia zadanie z powrotem
 * w stan `queued`, a podnosi je proces roboczy (app/bin/worker.php). Inaczej
 * generowanie raportu blokowałoby żądanie na minuty i padało na limicie czasu.
 */
final class Jobs
{
    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT id, type, payload_json, status, attempts, exit_code, error_text,
                    created_at, started_at, finished_at
               FROM jobs WHERE id = :id',
            ['id' => $id]
        );
    }

    /**
     * Ponowienie: status na `queued`, licznik prób na zero, ślady poprzedniego
     * przebiegu wyczyszczone.
     *
     * Warunek `status IN ('failed','done')` jest w UPDATE, nie w PHP — inaczej dwa
     * kliknięcia w tej samej chwili mogłyby wrzucić do kolejki zadanie, które
     * właśnie ruszyło, i ten sam raport policzyłby się dwa razy.
     */
    public static function retry(int $id): bool
    {
        $stmt = Db::run(
            // `available_at` KASUJEMY. Zadanie wysyłki poczty po nieudanej próbie
            // czeka na swoje okno (nawet godzinę); operator, który klika „ponów",
            // prosi o próbę TERAZ, a nie o dołączenie się do kolejki za godzinę.
            "UPDATE jobs
                SET status = 'queued', attempts = 0, exit_code = NULL, error_text = NULL,
                    started_at = NULL, finished_at = NULL, available_at = NULL
              WHERE id = :id AND status IN ('failed', 'done')",
            ['id' => $id]
        );
        $changed = $stmt->rowCount() === 1;

        if ($changed) {
            Audit::log('job.retry', Session::userId(), 'job', $id);
        }
        return $changed;
    }

    /**
     * Zadanie wraca do kolejki z wyznaczonym terminem kolejnej próby.
     *
     * Odróżnienie od zakończenia błędem: zadanie NIE jest nieudane, tylko
     * odłożone. Kolumna `available_at` sprawia, że proces roboczy je pominie,
     * dopóki termin nie nadejdzie (patrz `app/bin/run_job.php`).
     *
     * Powód zapisujemy OD RAZU, mimo że zadanie dopiero czeka. Bez tego operator
     * widziałby pozycję w kolejce bez śladu, że cokolwiek poszło nie tak,
     * i dowiedziałby się dopiero po wyczerpaniu wszystkich prób.
     */
    public static function requeueLater(int $id, int $sekundy, string $powod): void
    {
        Db::run(
            "UPDATE jobs
                SET status = 'queued', available_at = :kiedy, finished_at = NULL,
                    exit_code = NULL, error_text = :powod
              WHERE id = :id",
            [
                'kiedy' => Stats::now('+' . max(1, $sekundy) . ' seconds'),
                'powod' => mb_substr($powod, 0, 8000),
                'id'    => $id,
            ]
        );
    }

    /**
     * Raport powstały z tego zadania — do odnośnika przy stanie `done`.
     *
     * @return array<string,mixed>|null
     */
    public static function reportFor(array $job): ?array
    {
        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $matchId = is_array($payload) ? ($payload['match_id'] ?? null) : null;
        if (!is_int($matchId) && !ctype_digit((string) $matchId)) {
            return null;
        }
        return Db::one(
            'SELECT id, generated_at, engine_version FROM reports
              WHERE match_id = :mid ORDER BY id DESC LIMIT 1',
            ['mid' => (int) $matchId]
        );
    }

    /**
     * Po tylu sekundach w kolejce mówimy „trwa dłużej niż zwykle".
     *
     * Cron chodzi co minutę, więc kilkadziesiąt sekund oczekiwania jest normą,
     * a nie awarią. Trzy minuty to już sygnał, że coś stoi — i wtedy uczciwiej
     * powiedzieć to wprost, niż dalej pokazywać kręcące się kółko.
     *
     * ŻADNYCH PROCENTÓW. Nie znamy postępu renderu i każda liczba byłaby
     * zmyślona — a pasek, który stoi na 87%, jest gorszy niż brak paska.
     */
    public const PROG_WOLNO = 180;

    /**
     * Etap dla wskaźnika pracy. Cztery, nie pięć stanów bazy: `draft` i inne
     * nietypowe wartości schodzą do „w kolejce", bo z punktu widzenia
     * czekającego znaczą to samo — jeszcze się nie zaczęło.
     */
    public static function stage(string $status): string
    {
        return match ($status) {
            'running' => 'processing',
            'done'    => 'done',
            'failed'  => 'failed',
            default   => 'queued',
        };
    }

    /**
     * Sekundy od wejścia zadania do kolejki.
     *
     * LICZONE PO STRONIE SERWERA i wysyłane jako liczba, a nie jako znacznik
     * czasu do odjęcia w przeglądarce. Zegar przeglądarki bywa przestawiony
     * o godziny; licznik „czas od startu" pokazywałby wtedy wartość ujemną
     * albo absurdalnie dużą. Klient dostaje punkt odniesienia i tyka sobie
     * lokalnie od niego.
     */
    public static function elapsed(array $job): int
    {
        $od = (string) ($job['created_at'] ?? '');
        if ($od === '') {
            return 0;
        }
        $start = strtotime($od);
        if ($start === false) {
            return 0;
        }
        return max(0, time() - $start);
    }

    /**
     * Dokąd prowadzi ukończone zadanie. `null`, gdy nie ma czego pokazać.
     *
     * Rozstrzyga TYP zadania, nie zgadywanie po ładunku: raport przeliczony
     * ma wracać pod SWÓJ adres (ten sam, który był przed przeliczeniem),
     * a nie pod „najnowszy raport meczu" — to bywa inny wiersz.
     */
    public static function resultUrl(array $job): ?string
    {
        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];

        switch ((string) $job['type']) {
            case Rebuilds::JOB_TYPE:
                $reportId = (int) ($payload['report_id'] ?? 0);
                return $reportId > 0 ? '/raport/' . $reportId : null;

            case 'build_report':
                $report = self::reportFor($job);
                return $report !== null ? '/raport/' . (int) $report['id'] : null;

            case 'inspect':
                $importId = (int) ($payload['import_id'] ?? 0);
                return $importId > 0 ? '/import/' . $importId : null;

            default:
                // Poczta i reset hasła nie mają ekranu wyniku — i nie udajemy,
                // że mają. Wskaźnik pokaże „Gotowe" bez przejścia dokądkolwiek.
                return null;
        }
    }

    /**
     * Stan zadania dla wskaźnika pracy — kształt oddawany przez punkt końcowy.
     *
     * WYŁĄCZNIE DANE, zero HTML-a. Skrypt buduje z tego elementy przez
     * `textContent`, tak samo jak przy chmurkach (CLAUDE.md §9).
     *
     * @return array<string,mixed>|null
     */
    public static function publicStatus(int $id): ?array
    {
        $job = self::find($id);
        if ($job === null) {
            return null;
        }

        $status = (string) $job['status'];
        $stage  = self::stage($status);
        $czas   = self::elapsed($job);

        return [
            'id'          => (int) $job['id'],
            'stage'       => $stage,
            'status'      => $status,
            'elapsed'     => $czas,
            // „Trwa dłużej niż zwykle" DOTYCZY WYŁĄCZNIE CZEKANIA W KOLEJCE.
            // Render sam w sobie bywa długi i nie ma w tym nic niepokojącego —
            // ostrzeżenie o tym, co działa normalnie, uczy ignorować ostrzeżenia.
            'slow'        => $stage === 'queued' && $czas > self::PROG_WOLNO,
            'started_at'  => $job['started_at'],
            'finished_at' => $job['finished_at'],
            // Pierwsza linia, nie cały traceback: chodzi o komunikat przy
            // wskaźniku, a pełny zapis i tak stoi na ekranie zadania.
            'error'       => self::errorLine($job['error_text'] ?? null),
            'result_url'  => $stage === 'done' ? self::resultUrl($job) : null,
            'can_retry'   => self::canRetry($status),
        ];
    }

    /** Pierwsza linia powodu awarii — pełny zapis zostaje w podglądzie zadania. */
    public static function errorLine(?string $tekst): ?string
    {
        return self::pierwszaLinia((string) $tekst);
    }

    private static function pierwszaLinia(string $tekst): ?string
    {
        $tekst = trim($tekst);
        if ($tekst === '') {
            return null;
        }
        $koniec = strpos($tekst, "\n");
        return mb_substr($koniec === false ? $tekst : substr($tekst, 0, $koniec), 0, 200);
    }

    /** Stany, dla których „Ponów" ma sens. */
    public static function canRetry(?string $status): bool
    {
        return in_array($status, ['failed', 'done'], true);
    }
}
