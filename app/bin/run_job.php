<?php
declare(strict_types=1);

/**
 * Proces roboczy jednego zadania. Uruchamiany w tle przez Engine::launchWorker()
 * zaraz po zatwierdzeniu raportu — nie czekamy na crona (docs/OGRANICZENIA_HOSTINGU.md).
 *
 *   php app/bin/run_job.php <job_id>
 *
 * Dlaczego CLI, a nie żądanie HTTP:
 *  - render trwa kilkadziesiąt sekund i padłby na limicie czasu FPM,
 *  - PHP CLI nie ma `open_basedir`, więc sięga do STORAGE_PATH poza katalogiem domeny.
 *
 * Podział odpowiedzialności bez zmian: to PHP pisze do bazy, silnik liczy.
 * Traceback trafia do `jobs.error_text` i do logu — nigdy do przeglądarki.
 */

use CoachAnalyze\Audit;
use CoachAnalyze\Db;
use CoachAnalyze\Engine;
use CoachAnalyze\Imports;
use CoachAnalyze\Stats;
use CoachAnalyze\Storage;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$jobId = (int) ($argv[1] ?? 0);
if ($jobId <= 0) {
    fwrite(STDERR, "Użycie: php app/bin/run_job.php <job_id>\n");
    exit(2);
}

/**
 * Przejęcie zadania. Warunek `status = 'queued'` jest w UPDATE, nie w PHP —
 * gdyby cron-siatka i proces z panelu ruszyły równocześnie, tylko jeden z nich
 * zmieni wiersz i tylko jeden policzy raport.
 */
$claimed = Db::run(
    "UPDATE jobs SET status = 'running', attempts = attempts + 1, started_at = :now,
                     finished_at = NULL, exit_code = NULL, error_text = NULL
      WHERE id = :id AND status = 'queued'",
    ['id' => $jobId, 'now' => Stats::now()]
)->rowCount();

if ($claimed !== 1) {
    fwrite(STDERR, "Zadanie {$jobId} nie jest w stanie 'queued' — pomijam.\n");
    exit(0);
}

$job = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
$payload = json_decode((string) ($job['payload_json'] ?? ''), true);
$importId = (int) ($payload['import_id'] ?? 0);
$import = $importId > 0 ? Imports::find($importId) : null;

if ($import === null) {
    finish($jobId, 4, 'Import ' . $importId . ' nie istnieje albo został usunięty.');
    exit(4);
}

$matchId = (int) $import['match_id'];

try {
    $dir = Storage::jobDir($jobId);
    $reportPath = Storage::reportDir() . '/' . Storage::randomName('html');

    // Konfiguracja dla silnika. Kluby dochodzą w Etapie 4b — dopóki ich nie ma,
    // NIE zmyślamy nazw: silnik zwróci wykryte w danych w `coverage.teams`,
    // a wszystkie zdarzenia trafią do „bez przypisania drużyny" (pułapka 5).
    $configPath = $dir . '/config.json';
    file_put_contents($configPath, json_encode([
        'match_id'        => $matchId,
        'season_label'    => null,
        'teams'           => new stdClass(),
        'mapping_profile' => ['version' => 1, 'rules' => []],
        'options'         => ['contrast_fix' => true, 'engine_locale' => 'pl_PL'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $result = Engine::build([
        'csv'         => (string) $import['csv_path'],
        'json'        => $import['json_path'] ?: null,
        'config'      => $configPath,
        'out_html'    => $reportPath,
        'out_meta'    => $dir . '/meta.json',
        'out_canon'   => $dir . '/canon.json',
        'out_metrics' => $dir . '/metrics.json',
    ]);

    $meta = read_meta($dir . '/meta.json');

    if ($result['exit'] === 0 && is_file($reportPath)) {
        Db::run(
            'INSERT INTO reports (match_id, html_path, params_json, engine_version, generated_at)
             VALUES (:mid, :path, :params, :ver, :now)',
            [
                'mid'    => $matchId,
                'path'   => $reportPath,
                'params' => json_encode([
                    'sections' => $meta['sections_available'] ?? [],
                    'job_id'   => $jobId,
                ], JSON_UNESCAPED_UNICODE),
                'ver'    => $meta['engine_version'] ?? null,
                'now'    => Stats::now(),
            ]
        );

        // Pokrycie z pełnego przebiegu jest dokładniejsze niż to z `inspect`
        // (tam nie było konfiguracji) — nadpisujemy je w imporcie.
        Db::run(
            'UPDATE imports SET coverage_json = :cov, warnings_json = :warn, engine_version = :ver
              WHERE id = :id',
            [
                'cov'  => isset($meta['coverage']) ? json_encode($meta['coverage'], JSON_UNESCAPED_UNICODE) : null,
                'warn' => isset($meta['warnings']) ? json_encode($meta['warnings'], JSON_UNESCAPED_UNICODE) : null,
                'ver'  => $meta['engine_version'] ?? null,
                'id'   => $importId,
            ]
        );

        Db::run('UPDATE matches SET status = :s, half_split_ms = :hs WHERE id = :id', [
            's'  => 'done',
            'hs' => $meta['half_split_ms'] ?? $import['half_split_ms'],
            'id' => $matchId,
        ]);

        finish($jobId, 0, null);
        Audit::log('report.generated', null, 'match', $matchId, ['job_id' => $jobId]);
        exit(0);
    }

    Db::run('UPDATE matches SET status = :s WHERE id = :id', ['s' => 'failed', 'id' => $matchId]);
    finish($jobId, $result['exit'], describe_failure($result, $meta));
    exit($result['exit']);
} catch (\Throwable $e) {
    error_log("run_job {$jobId}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    Db::run('UPDATE matches SET status = :s WHERE id = :id', ['s' => 'failed', 'id' => $matchId]);
    finish($jobId, 4, $e->getMessage());
    exit(4);
}

// --------------------------------------------------------------------------

function finish(int $jobId, int $exitCode, ?string $error): void
{
    Db::run(
        'UPDATE jobs SET status = :status, exit_code = :code, error_text = :err, finished_at = :now
          WHERE id = :id',
        [
            'status' => $exitCode === 0 ? 'done' : 'failed',
            'code'   => $exitCode,
            // Ograniczamy długość: kolumna to TEXT, ale wielomegabajtowy traceback
            // w bazie nie pomaga nikomu, a pełna treść jest w logu.
            'err'    => $error === null ? null : mb_substr($error, 0, 8000),
            'now'    => Stats::now(),
            'id'     => $jobId,
        ]
    );
}

/** @return array<string,mixed> */
function read_meta(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Komunikat dla operatora. Kolejność źródeł jest celowa: najpierw to, co silnik
 * powiedział o sobie sam w `meta.json` (kody 2 i 3), potem stderr, na końcu
 * ogólny opis kodu wyjścia.
 *
 * @param array{exit:int, stdout:string, stderr:string, timed_out:bool} $result
 * @param array<string,mixed> $meta
 */
function describe_failure(array $result, array $meta): string
{
    if (($meta['ok'] ?? null) === false && !empty($meta['msg'])) {
        $msg = (string) $meta['msg'];
        if (!empty($meta['missing_columns'])) {
            $msg .= "\n\nBrakujące kolumny: " . implode(', ', (array) $meta['missing_columns']);
        }
        return $msg;
    }

    if ($result['timed_out']) {
        return 'Przekroczony limit czasu silnika (' . \CoachAnalyze\Config::int('ENGINE_TIMEOUT', 180) . ' s).';
    }

    $stderr = trim($result['stderr']);
    if ($stderr === '') {
        return 'Silnik zakończył się kodem ' . $result['exit'] . ' bez komunikatu.';
    }

    // Pełny traceback WYŁĄCZNIE do logu — nigdy do bazy ani do przeglądarki
    // (CLAUDE.md §5). Operatorowi pokazujemy ostatnią linię, czyli typ i treść
    // wyjątku: tyle wystarczy, żeby wiedzieć, czy ponawiać, czy zgłaszać.
    error_log("engine stderr:\n" . $stderr);

    $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr)), fn($l) => $l !== ''));
    $last = $lines === [] ? '' : (string) end($lines);

    return 'Silnik zakończył się kodem ' . $result['exit'] . '.'
        . ($last === '' ? '' : "\n\n" . $last)
        . "\n\nPełny ślad błędu znajduje się w logu serwera.";
}
