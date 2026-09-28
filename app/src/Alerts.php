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

    /*
     * PRÓG W GIGABAJTACH, NIE W PROCENTACH (golden layout W6).
     *
     * Dysk hostingu jest WSPÓŁDZIELONY: 7,1% wolnego to na lh.pl 162 GB, więc
     * alert procentowy krzyczał „mało miejsca" przy zapasie na lata raportów.
     * Liczy się, ile bajtów zostało nam — raport to kilkaset kB, eksport kilka MB.
     */
    /** Poniżej tylu GB wolnego miejsca — ostrzeżenie. */
    public const DISK_WARN_GB = 5.0;
    /** Poniżej tylu GB — błąd: upload i render mogą za chwilę paść. */
    public const DISK_ERROR_GB = 1.0;

    public const LEVEL_WARN = 'warn';
    public const LEVEL_ERROR = 'error';

    /**
     * Wszystkie alerty naraz.
     *
     * @return list<array{level:string, code:string, msg:string, hint:string, count:int}>
     */
    public static function all(): array
    {
        return array_merge(self::stuckJobs(), self::failedJobs(), self::diskSpace(),
            // W7 H: raport niezgodny z plikiem (naruszony niezmiennik).
            KontrolaImportu::alerty());
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

        if ($free === false) {
            return [];
        }

        $poziom = self::poziomMiejsca((float) $free);
        if ($poziom === null) {
            return [];
        }

        return [[
            'level' => $poziom,
            'code'  => 'DISK_LOW',
            'msg'   => View::t('alert.disk', self::formatBytes((float) $free)),
            'hint'  => View::t('alert.disk.hint'),
            'count' => 1,
        ]];
    }

    /** Poziom alertu dla wolnego miejsca w bajtach, `null` = w porządku. */
    public static function poziomMiejsca(float $wolneBajty): ?string
    {
        $gb = $wolneBajty / 1024 / 1024 / 1024;
        if ($gb >= self::DISK_WARN_GB) {
            return null;
        }
        return $gb < self::DISK_ERROR_GB ? self::LEVEL_ERROR : self::LEVEL_WARN;
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
