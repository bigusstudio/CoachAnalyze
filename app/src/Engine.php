<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Odczyt wersji silnika do stopki panelu.
 *
 * Wersja pochodzi z `coachanalyze --version`, a nie z pliku w repozytorium:
 * na serwerze liczy się to, co faktycznie jest zainstalowane, a nie to, co
 * ktoś wdrożył ostatnim razem. Pytanie „dlaczego raport z marca pokazuje inną
 * liczbę" ma mieć odpowiedź (CLAUDE.md §7).
 *
 * Uruchomienie procesu przy każdym renderze byłoby marnotrawstwem, więc wynik
 * leży w pliku podręcznym przez godzinę.
 */
final class Engine
{
    private const CACHE_TTL = 3600;
    private static ?string $memo = null;

    public static function version(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cache = self::cachePath();
        if ($cache !== null && is_file($cache) && (time() - (int) filemtime($cache)) < self::CACHE_TTL) {
            $cached = trim((string) file_get_contents($cache));
            if ($cached !== '') {
                return self::$memo = $cached;
            }
        }

        $version = self::read();
        if ($cache !== null) {
            @file_put_contents($cache, $version, LOCK_EX);
        }
        return self::$memo = $version;
    }

    private static function read(): string
    {
        $python = Config::get('PYTHON_BIN');
        if ($python === null || !is_executable($python)) {
            return View::t('common.unknown');
        }

        $cmd = escapeshellcmd($python) . ' -m coachanalyze --version 2>/dev/null';
        $out = @shell_exec($cmd);
        $version = trim((string) $out);

        // Brak odpowiedzi nie może wywrócić strony — stopka pokazuje wtedy „nieznana".
        if ($version === '' || !preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return View::t('common.unknown');
        }
        return $version;
    }

    // ---------------------------------------------------------------- wywołania
    //
    // Kontrakt: docs/KONTRAKT_CLI.md. PHP przekazuje ścieżki i konfigurację,
    // odbiera pliki i JSON — nie interpretuje HTML-a i nie liczy metryk (CLAUDE.md §4).

    /**
     * `inspect` — raport pokrycia PRZED renderem. Wywołanie synchroniczne:
     * samo parsowanie 294 zdarzeń trwa ułamek sekundy, a operator czeka na wynik
     * na ekranie. Renderu tu NIE MA — to jest właśnie ten ekran „co jest w pliku".
     *
     * @return array{ok:bool, exit:int, meta:array<string,mixed>|null, stderr:string}
     */
    public static function inspect(string $csvPath, ?string $jsonPath = null): array
    {
        $args = ['inspect', '--csv', $csvPath];
        if ($jsonPath !== null && $jsonPath !== '') {
            $args[] = '--json';
            $args[] = $jsonPath;
        }

        $result = self::run($args, Config::int('INSPECT_TIMEOUT', 60));
        $meta = json_decode($result['stdout'], true);

        return [
            'ok'     => $result['exit'] === 0 && is_array($meta) && ($meta['ok'] ?? false) === true,
            'exit'   => $result['exit'],
            'meta'   => is_array($meta) ? $meta : null,
            'stderr' => $result['stderr'],
        ];
    }

    /**
     * `build` — pełne przetworzenie. Wywoływane WYŁĄCZNIE z procesu CLI
     * (app/bin/run_job.php), nigdy wprost z żądania HTTP: render trwa
     * kilkadziesiąt sekund i zdążyłby paść na limicie czasu FPM.
     *
     * @param array<string,string|null> $paths csv, json, config, out_html, out_meta, out_canon, out_metrics
     * @return array{exit:int, stdout:string, stderr:string, timed_out:bool}
     */
    public static function build(array $paths): array
    {
        $args = ['build', '--csv', (string) $paths['csv']];

        foreach ([
            '--json'        => $paths['json'] ?? null,
            '--config'      => $paths['config'] ?? null,
            '--out-html'    => $paths['out_html'] ?? null,
            '--out-meta'    => $paths['out_meta'] ?? null,
            '--out-canon'   => $paths['out_canon'] ?? null,
            '--out-metrics' => $paths['out_metrics'] ?? null,
        ] as $flag => $value) {
            if ($value !== null && $value !== '') {
                $args[] = $flag;
                $args[] = $value;
            }
        }

        return self::run($args, Config::int('ENGINE_TIMEOUT', 180));
    }

    /**
     * Uruchomienie procesu roboczego W TLE i natychmiastowy powrót.
     *
     * Nie czekamy na cron: `proc_open` jest dostępne, a minimalny interwał crona
     * na lh.pl bywa dłuższy niż minuta (docs/OGRANICZENIA_HOSTINGU.md). Cron
     * zostaje wyłącznie jako siatka bezpieczeństwa dla zadań zawieszonych.
     *
     * Proces odpinamy przez `nohup ... &` — inaczej zakończenie żądania HTTP
     * zabiłoby potomka w połowie renderu.
     */
    public static function launchWorker(int $jobId): bool
    {
        $php = Config::get('PHP_BIN', PHP_BINARY);
        $script = dirname(__DIR__) . '/bin/run_job.php';

        if (!is_file($script)) {
            error_log("engine: brak skryptu roboczego {$script}");
            return false;
        }

        $cmd = sprintf(
            'nohup %s %s %d > /dev/null 2>&1 &',
            escapeshellarg((string) $php),
            escapeshellarg($script),
            $jobId
        );

        $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            error_log("engine: proc_open nie powiodło się dla zadania {$jobId}");
            return false;
        }
        proc_close($process);
        return true;
    }

    /**
     * Uruchomienie silnika z limitem czasu.
     *
     * Limit pilnujemy sami, w pętli po strumieniach — `proc_open` go nie ma.
     * Bez tego zapętlony render trzymałby zadanie w stanie `running` w nieskończoność,
     * a operator nie miałby czego ponowić.
     *
     * @param list<string> $args
     * @return array{exit:int, stdout:string, stderr:string, timed_out:bool}
     */
    private static function run(array $args, int $timeout): array
    {
        return self::runPython(array_merge(['-m', 'coachanalyze'], $args), $timeout);
    }

    /**
     * Korekta kontrastu barwy klubu pod ciemne tło raportu.
     *
     * Arytmetyki koloru NIE przepisujemy do PHP. `round()` w Pythonie jest
     * bankierskie, a w PHP połówki idą w górę: `round(0.5)` to 0 kontra 1,
     * `round(2.5)` to 2 kontra 3. Przy skali 0–255 daje to barwę różniącą się
     * o jeden stopień — niewidoczną na oko i rozjeżdżającą raport z paletą,
     * którą silnik liczy z pliku projektu tą samą funkcją.
     *
     * PHP robi tu wyłącznie rozbiór zapisu `#RRGGBB` na trzy liczby.
     *
     * @return string|null `#RRGGBB` po korekcie albo null, gdy silnik nie odpowiedział
     */
    public static function contrastColor(string $hex): ?string
    {
        if (preg_match('/^#?([0-9A-Fa-f]{6})$/', trim($hex), $m) !== 1) {
            return null;
        }
        [$r, $g, $b] = str_split($m[1], 2);

        // Format wejściowy `to_hex`: trzy liczby 0–1 rozdzielone spacją,
        // taki sam jak w pliku projektu LiveTag.
        $rgb = sprintf(
            '%.6F %.6F %.6F',
            hexdec($r) / 255,
            hexdec($g) / 255,
            hexdec($b) / 255
        );

        // `-c`, bo silnik nie wystawia podkomendy do koloru, a katalogu engine/
        // nie ruszamy. Barwa idzie osobnym argumentem, nie w treści skryptu.
        $result = self::runPython([
            '-c',
            'import sys; from coachanalyze.sources.livetag.parse import to_hex; print(to_hex(sys.argv[1]))',
            $rgb,
        ], Config::int('INSPECT_TIMEOUT', 60));

        $out = trim($result['stdout']);
        return preg_match('/^#[0-9A-F]{6}$/', $out) === 1 ? $out : null;
    }

    /**
     * @param list<string> $args
     * @return array{exit:int, stdout:string, stderr:string, timed_out:bool}
     */
    private static function runPython(array $args, int $timeout): array
    {
        $python = Config::get('PYTHON_BIN');
        if ($python === null) {
            return ['exit' => 4, 'stdout' => '', 'stderr' => 'Brak PYTHON_BIN w .env', 'timed_out' => false];
        }

        $cmd = escapeshellarg($python);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['exit' => 4, 'stdout' => '', 'stderr' => 'proc_open nie powiodło się', 'timed_out' => false];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        $timedOut = false;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(50000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [
            // Kod 5 = przekroczony limit czasu (docs/KONTRAKT_CLI.md).
            'exit'      => $timedOut ? 5 : $exit,
            'stdout'    => $stdout,
            'stderr'    => $stderr,
            'timed_out' => $timedOut,
        ];
    }

    private static function cachePath(): ?string
    {
        $storage = Config::get('STORAGE_PATH');
        if ($storage === null || !is_dir($storage) || !is_writable($storage)) {
            return null;
        }
        return rtrim($storage, '/') . '/.engine_version';
    }
}
