<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Uruchamianie silnika Pythona. WYŁĄCZNIE WARSTWA CLI.
 *
 * DLACZEGO TEN PLIK LEŻY W app/bin/, A NIE W app/src/
 *
 * PHP-FPM na lh.pl ma `disable_functions` obejmujące `proc_open`, `exec`,
 * `shell_exec`, `popen`, `system` i resztę. Kod w `app/src/` jest ładowany
 * autoloaderem przy obsłudze żądania, więc trzymanie tam wywołań tych funkcji
 * opierało się wyłącznie na sprawdzeniu `PHP_SAPI` w czasie wykonania.
 *
 * Sprawdzenie w czasie wykonania da się obejść przez nieuwagę: wystarczy jedno
 * nowe wywołanie z kontrolera i błąd wraca. Plik POZA drzewem autoloadera nie
 * daje się zawołać z warstwy żądań w ogóle — granica jest fizyczna, nie umowna.
 * Pilnuje tego app/tests/test_disabled_functions.php.
 *
 * Wciągany jawnie przez app/bin/run_job.php i app/bin/watchdog.php.
 *
 * Po ewentualnym przejściu na Cloud Server ograniczenie znika i klasę można
 * wrócić do app/src/ — patrz docs/OGRANICZENIA_HOSTINGU.md.
 */
final class EngineRunner
{
    /**
     * Odczyt wersji z silnika i zapis artefaktu. WYŁĄCZNIE Z CLI.
     * Wołane przez proces roboczy i nadzorcę, żeby stopka panelu miała co pokazać.
     */
    public static function refreshVersion(): string
    {
                $result = self::runPython(['-m', 'coachanalyze', '--version'], 30);
        $version = trim($result['stdout']);

        if ($version === '' || !preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return View::t('common.unknown');
        }

        $cache = self::cachePath();
        if ($cache !== null) {
            @file_put_contents($cache, $version, LOCK_EX);
        }
        return $version;
    }

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
            // Templat raportu klubu (Sesja 5). Pusty = klub przed
            // konfiguratorem; silnik zachowuje sie wtedy jak przed ta sesja.
            '--template'    => $paths['template'] ?? null,
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

    /** Ścieżka artefaktu z wersją — współdzielona z Engine (warstwa żądań go czyta). */
    private static function cachePath(): ?string
    {
        $storage = Config::get('STORAGE_PATH');
        if ($storage === null || !is_dir($storage)) {
            return null;
        }
        return rtrim($storage, '/') . '/.engine_version';
    }
}
