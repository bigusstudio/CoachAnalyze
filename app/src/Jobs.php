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

    /** Stany, dla których „Ponów" ma sens. */
    public static function canRetry(?string $status): bool
    {
        return in_array($status, ['failed', 'done'], true);
    }
}
