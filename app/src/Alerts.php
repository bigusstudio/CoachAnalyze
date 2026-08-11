<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Alerty operacyjne — stany, które wymagają reakcji człowieka.
 *
 * Wołane z panelu (widoczne od razu) i z crona (siatka bezpieczeństwa).
 * Każdy alert niesie POWÓD po polsku i wskazówkę, co z tym zrobić — sam fakt
 * „coś jest nie tak" nie skraca czasu naprawy.
 */
final class Alerts
{
    /** Po tylu minutach zadanie w stanie `running` uznajemy za zawieszone. */
    public const STUCK_MINUTES = 5;

    /** Poniżej tylu procent wolnego miejsca zgłaszamy problem. */
    public const DISK_WARN_PERCENT = 10;

    public const LEVEL_WARN = 'warn';
    public const LEVEL_ERROR = 'error';

    /**
     * Wszystkie alerty naraz.
     *
     * @return list<array{level:string, code:string, msg:string, hint:string, count:int}>
     */
    public static function all(): array
    {
        return array_merge(self::stuckJobs(), self::failedJobs(), self::diskSpace());
    }

    /**
     * Zadania wiszące w stanie `running`.
     *
     * Proces roboczy startuje odpięty (`nohup`), więc jego śmierć nie zmienia
     * statusu w bazie — zadanie zostaje `running` na zawsze i nikt go nie ponowi,
     * bo `retry()` przyjmuje tylko `failed` i `done`. To jest właśnie ten stan.
     *
     * @return list<array<string,mixed>>
     */
    public static function stuckJobs(): array
    {
        $limit = Stats::now('-' . self::STUCK_MINUTES . ' minutes');

        $rows = Db::all(
            "SELECT id, type, started_at FROM jobs
              WHERE status = 'running' AND (started_at IS NULL OR started_at < :limit)
              ORDER BY started_at",
            ['limit' => $limit]
        );

        if ($rows === []) {
            return [];
        }

        return [[
            'level' => self::LEVEL_ERROR,
            'code'  => 'JOB_STUCK',
            // Kolejność argumentów zgodna ze wzorcem: najpierw minuty, potem liczba.
            'msg'   => View::t('alert.job_stuck', self::STUCK_MINUTES, count($rows)),
            'hint'  => View::t('alert.job_stuck.hint'),
            'count' => count($rows),
            'ids'   => array_column($rows, 'id'),
        ]];
    }

    /** @return list<array<string,mixed>> */
    public static function failedJobs(): array
    {
        $rows = Db::all(
            "SELECT id FROM jobs WHERE status = 'failed' AND created_at >= :since",
            ['since' => Stats::now('-7 days')]
        );

        if ($rows === []) {
            return [];
        }

        return [[
            'level' => self::LEVEL_WARN,
            'code'  => 'JOB_FAILED',
            'msg'   => View::t('alert.job_failed', count($rows)),
            'hint'  => View::t('alert.job_failed.hint'),
            'count' => count($rows),
            'ids'   => array_column($rows, 'id'),
        ]];
    }

    /**
     * Miejsce na dysku.
     *
     * Brak miejsca objawia się jako „nie udało się zapisać pliku" przy uploadzie
     * albo jako raport ucięty w połowie — objawy, po których nikt nie zgaduje
     * przyczyny. Lepiej powiedzieć wprost, zanim to nastąpi.
     *
     * @return list<array<string,mixed>>
     */
    public static function diskSpace(): array
    {
        $path = Config::get('STORAGE_PATH');
        if ($path === null) {
            return [];
        }

        // Na niektórych hostingach funkcje dyskowe są wyłączone — wtedy po prostu
        // nie mamy tego alertu, zamiast wywracać cały panel.
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return [];
        }

        $percent = ($free / $total) * 100;
        if ($percent >= self::DISK_WARN_PERCENT) {
            return [];
        }

        return [[
            'level' => $percent < 3 ? self::LEVEL_ERROR : self::LEVEL_WARN,
            'code'  => 'DISK_LOW',
            'msg'   => View::t('alert.disk', round($percent, 1), self::formatBytes((float) $free)),
            'hint'  => View::t('alert.disk.hint'),
            'count' => 1,
        ]];
    }

    /**
     * Odblokowanie zawieszonego zadania: `running` → `failed`, żeby dało się je
     * ponowić. Wołane z crona; bez tego zadanie wisi w nieskończoność.
     */
    public static function releaseStuckJobs(): int
    {
        $limit = Stats::now('-' . self::STUCK_MINUTES . ' minutes');

        $count = Db::run(
            "UPDATE jobs
                SET status = 'failed', exit_code = 5, finished_at = :now,
                    error_text = :err
              WHERE status = 'running' AND (started_at IS NULL OR started_at < :limit)",
            [
                'now'   => Stats::now(),
                'err'   => View::t('alert.released'),
                'limit' => $limit,
            ]
        )->rowCount();

        if ($count > 0) {
            Audit::log('job.released', null, 'job', null, ['count' => $count]);
        }
        return $count;
    }

    private static function formatBytes(float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
