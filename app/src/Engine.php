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

    private static function cachePath(): ?string
    {
        $storage = Config::get('STORAGE_PATH');
        if ($storage === null || !is_dir($storage) || !is_writable($storage)) {
            return null;
        }
        return rtrim($storage, '/') . '/.engine_version';
    }
}
