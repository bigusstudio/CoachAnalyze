<?php
declare(strict_types=1);

/**
 * Proces roboczy kolejki. Uruchamiany z CRONA, co minutę.
 *
 *   php app/bin/run_job.php            # jedno przejście po kolejce
 *   php app/bin/run_job.php <job_id>   # konkretne zadanie (diagnostyka)
 *
 * DLACZEGO CRON, A NIE START Z PANELU
 *
 * PHP-FPM na lh.pl ma `disable_functions` obejmujące `proc_open`, `exec`,
 * `shell_exec`, `popen`, `system` i resztę — z przeglądarki nie da się uruchomić
 * żadnego procesu. Wcześniejsza architektura odpalała silnik natychmiast po
 * wgraniu eksportu i wywalała się wyjątkiem PRZED zapisem zadania, przez co
 * tabela `jobs` zostawała pusta i nie było nawet czego ponowić.
 *
 * Cała warstwa żądań tylko KOLEJKUJE. Wykonanie idzie wyłącznie tędy.
 * Szczegóły i pełna lista wyłączonych funkcji: docs/OGRANICZENIA_HOSTINGU.md.
 *
 * PHP CLI nie ma `open_basedir`, więc sięga do STORAGE_PATH i do venv Pythona.
 *
 * Traceback trafia do `jobs.error_text` (skrócony) i do logu (pełny) —
 * nigdy do przeglądarki.
 */

use CoachAnalyze\Audit;
use CoachAnalyze\Clubs;
use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\EngineRunner;
use CoachAnalyze\Imports;
use CoachAnalyze\RedisClient;
use CoachAnalyze\Stats;
use CoachAnalyze\Storage;

require dirname(__DIR__) . '/src/bootstrap.php';

// Uruchamianie procesow lezy POZA drzewem autoloadera - patrz naglowek klasy.
require __DIR__ . '/EngineRunner.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Jedna linia w logu, która oszczędza godzinę: który plik konfiguracji wygrał.
// Warstwa żądań i cron startują z różnych katalogów i potrafią czytać różne `.env`.
error_log('run_job: konfiguracja z ' . (Config::loadedFrom() ?? 'BRAK — używam wartości domyślnych'));

$tylkoJedno = isset($argv[1]) ? (int) $argv[1] : 0;

// ---------------------------------------------------------------- blokada
//
// Cron chodzi co minutę, a render bywa dłuższy. Bez blokady kolejne uruchomienia
// nakładałyby się na siebie. Blokada jest w Redis, z czasem życia nieco dłuższym
// niż limit silnika, żeby padnięty proces nie zablokował kolejki na zawsze.
//
// To zabezpieczenie DODATKOWE. Właściwą wyłączność daje atomowe przejęcie
// zadania (UPDATE ... WHERE status='queued'), które działa także bez Redisa.
$blokada = null;
try {
    $blokada = RedisClient::fromConfig();
    $ttl = Config::int('ENGINE_TIMEOUT', 180) + 60;
    if (!$blokada->setNx('lock:worker', (string) getmypid(), $ttl)) {
        fwrite(STDOUT, "Inne przejście workera trwa — kończę.\n");
        exit(0);
    }
} catch (\Throwable $e) {
    // Brak Redisa nie może zatrzymać kolejki: atomowe przejęcie zadania wystarczy.
    error_log('run_job: blokada niedostępna (' . $e->getMessage() . ') — jadę bez niej');
    $blokada = null;
}

try {
    if ($tylkoJedno > 0) {
        przetworz($tylkoJedno);
    } else {
        // Ograniczona liczba zadań na przejście — cron wróci za minutę, a długa
        // pętla zwiększa tylko ryzyko, że proces zostanie ubity w połowie.
        foreach (Db::all(
            "SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 5"
        ) as $wiersz) {
            przetworz((int) $wiersz['id']);
        }
    }

    // Wersja silnika dla stopki panelu — FPM nie może o nią zapytać sam.
    EngineRunner::refreshVersion();
} finally {
    if ($blokada !== null) {
        try {
            $blokada->del('lock:worker');
        } catch (\Throwable) {
            // Blokada wygaśnie sama po TTL.
        }
    }
}

exit(0);

// --------------------------------------------------------------------------

/**
 * Przejęcie i wykonanie jednego zadania.
 *
 * Warunek `status = 'queued'` jest w UPDATE, nie w PHP — gdyby cron i ręczne
 * uruchomienie ruszyły równocześnie, tylko jedno przejmie wiersz.
 */
function przetworz(int $jobId): void
{
    $przejete = Db::run(
        "UPDATE jobs SET status = 'running', attempts = attempts + 1, started_at = :now,
                         finished_at = NULL, exit_code = NULL, error_text = NULL
          WHERE id = :id AND status = 'queued'",
        ['id' => $jobId, 'now' => Stats::now()]
    )->rowCount();

    if ($przejete !== 1) {
        return;
    }

    $job = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
    $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
    $importId = (int) ($payload['import_id'] ?? 0);
    $import = $importId > 0 ? Imports::find($importId) : null;

    if ($import === null) {
        zakoncz($jobId, 4, 'Import ' . $importId . ' nie istnieje albo został usunięty.');
        return;
    }

    try {
        match ((string) $job['type']) {
            'inspect'      => wykonajInspekcje($jobId, $importId, $import),
            'build_report' => wykonajRender($jobId, $importId, $import),
            default        => zakoncz($jobId, 4, 'Nieznany typ zadania: ' . $job['type']),
        };
    } catch (\Throwable $e) {
        error_log("run_job {$jobId}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        Db::run('UPDATE matches SET status = :s WHERE id = :id',
            ['s' => 'failed', 'id' => (int) $import['match_id']]);
        zakoncz($jobId, 4, $e->getMessage());
    }
}

/** Raport pokrycia — bez renderu. Wynik ląduje w bazie i zasila ekran pokrycia. */
function wykonajInspekcje(int $jobId, int $importId, array $import): void
{
    $wynik = EngineRunner::inspect((string) $import['csv_path'], $import['json_path'] ?: null);

    if (!$wynik['ok']) {
        // Ten sam opis co przy renderze — inaczej te same awarie tłumaczyłyby się
        // operatorowi inaczej w zależności od tego, którym wejściem przyszły.
        zakoncz($jobId, $wynik['exit'], opiszAwarie($wynik, (array) ($wynik['meta'] ?? [])));
        return;
    }

    Imports::saveInspection($importId, (array) $wynik['meta']);
    zakoncz($jobId, 0, null);
    Audit::log('inspect.done', null, 'import', $importId, ['job_id' => $jobId]);
}

/** Pełny render HTML wraz z artefaktami. */
function wykonajRender(int $jobId, int $importId, array $import): void
{
    $matchId = (int) $import['match_id'];
    $dir = Storage::jobDir($jobId);
    $reportPath = Storage::reportDir() . '/' . Storage::randomName('html');

    // Nazwy i barwy klubów z bazy — silnik ich nie odgaduje (docs/KONTRAKT_CLI.md).
    $match = Db::one('SELECT club_home_id, club_away_id FROM matches WHERE id = :id', ['id' => $matchId]);
    $teams = Clubs::engineConfig(
        $match['club_home_id'] !== null ? (int) $match['club_home_id'] : null,
        $match['club_away_id'] !== null ? (int) $match['club_away_id'] : null
    );

    $configPath = $dir . '/config.json';
    file_put_contents($configPath, json_encode([
        'match_id'        => $matchId,
        'season_label'    => null,
        'teams'           => $teams === [] ? new stdClass() : $teams,
        'mapping_profile' => ['version' => 1, 'rules' => []],
        // Korekta kontrastu barw klubu należy do silnika: PHP nie może uruchomić
        // Pythona z warstwy żądań, a arytmetyki koloru nie przepisujemy.
        'options'         => ['contrast_fix' => true, 'engine_locale' => 'pl_PL'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $wynik = EngineRunner::build([
        'csv'         => (string) $import['csv_path'],
        'json'        => $import['json_path'] ?: null,
        'config'      => $configPath,
        'out_html'    => $reportPath,
        'out_meta'    => $dir . '/meta.json',
        'out_canon'   => $dir . '/canon.json',
        'out_metrics' => $dir . '/metrics.json',
    ]);

    $meta = wczytajMeta($dir . '/meta.json');

    if ($wynik['exit'] === 0 && is_file($reportPath)) {
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

        // Pokrycie z pełnego przebiegu jest dokładniejsze niż z `inspect`
        // (tam nie było konfiguracji klubów) — nadpisujemy je.
        if ($meta !== []) {
            Imports::saveInspection($importId, $meta);
        }

        Db::run('UPDATE matches SET status = :s, half_split_ms = :hs WHERE id = :id', [
            's'  => 'done',
            'hs' => $meta['half_split_ms'] ?? $import['half_split_ms'],
            'id' => $matchId,
        ]);

        zakoncz($jobId, 0, null);
        Audit::log('report.generated', null, 'match', $matchId, ['job_id' => $jobId]);
        return;
    }

    Db::run('UPDATE matches SET status = :s WHERE id = :id', ['s' => 'failed', 'id' => $matchId]);
    zakoncz($jobId, $wynik['exit'], opiszAwarie($wynik, $meta));
}

function zakoncz(int $jobId, int $exitCode, ?string $error): void
{
    Db::run(
        'UPDATE jobs SET status = :status, exit_code = :code, error_text = :err, finished_at = :now
          WHERE id = :id',
        [
            'status' => $exitCode === 0 ? 'done' : 'failed',
            'code'   => $exitCode,
            // Kolumna to TEXT, ale wielomegabajtowy traceback w bazie nie pomaga
            // nikomu — pełna treść jest w logu.
            'err'    => $error === null ? null : mb_substr($error, 0, 8000),
            'now'    => Stats::now(),
            'id'     => $jobId,
        ]
    );
}

/** @return array<string,mixed> */
function wczytajMeta(string $path): array
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
 * @param array{exit:int, stdout:string, stderr:string, timed_out:bool} $wynik
 * @param array<string,mixed> $meta
 */
function opiszAwarie(array $wynik, array $meta): string
{
    if (($meta['ok'] ?? null) === false && !empty($meta['msg'])) {
        $msg = (string) $meta['msg'];
        if (!empty($meta['missing_columns'])) {
            $msg .= "\n\nBrakujące kolumny: " . implode(', ', (array) $meta['missing_columns']);
        }
        return $msg;
    }

    if ($wynik['timed_out']) {
        return 'Przekroczony limit czasu silnika (' . Config::int('ENGINE_TIMEOUT', 180) . ' s).';
    }

    $stderr = trim($wynik['stderr']);

    // Awaria ŚRODOWISKA, nie danych. `docs/KONTRAKT_CLI.md` zna kody 0, 2, 3, 4 i 5;
    // wszystko inne znaczy, że silnik w ogóle nie ruszył — brak paczki w venv,
    // zła ścieżka PYTHON_BIN, zepsute środowisko po wdrożeniu.
    //
    // Rozróżnienie jest istotne, bo komunikat „Silnik nie odczytał pliku" kazałby
    // operatorowi szukać winy w eksporcie, którego nikt nawet nie otworzył.
    if (!in_array($wynik['exit'], [0, 2, 3, 4, 5], true)) {
        if ($stderr !== '') {
            error_log("engine — awaria środowiska:\n" . $stderr);
        }
        return 'Silnik nie uruchomił się w ogóle — to awaria konfiguracji serwera, nie problem z eksportem. '
            . 'Plik jest zapisany, wystarczy ponowić zadanie po naprawie. '
            . 'Sprawdź PYTHON_BIN i instalację pakietu w venv; szczegóły w logu.';
    }
    if ($stderr === '') {
        return 'Silnik zakończył się kodem ' . $wynik['exit'] . ' bez komunikatu.';
    }

    // Pełny traceback WYŁĄCZNIE do logu — nigdy do bazy ani do przeglądarki
    // (CLAUDE.md §5). Operatorowi pokazujemy ostatnią linię, czyli typ i treść wyjątku.
    error_log("engine stderr:\n" . $stderr);

    $linie = array_values(array_filter(array_map('trim', explode("\n", $stderr)), fn($l) => $l !== ''));
    $ostatnia = $linie === [] ? '' : (string) end($linie);

    return 'Silnik zakończył się kodem ' . $wynik['exit'] . '.'
        . ($ostatnia === '' ? '' : "\n\n" . $ostatnia)
        . "\n\nPełny ślad błędu znajduje się w logu serwera.";
}
