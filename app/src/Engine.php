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

    /**
     * Wersja silnika do stopki.
     *
     * WARSTWA ŻĄDAŃ CZYTA WYŁĄCZNIE ARTEFAKT zapisany przez CLI. PHP-FPM na lh.pl
     * ma `disable_functions` obejmujące `proc_open`, `exec`, `shell_exec` i resztę
     * — żadnego procesu nie da się stąd uruchomić. Poprzednia wersja wołała
     * `shell_exec` i dodatkowo sprawdzała `is_executable(PYTHON_BIN)` na ścieżce
     * spoza `open_basedir`, czyli robiła dwie rzeczy niemożliwe naraz.
     *
     * Plik odświeża CLI (app/bin/watchdog.php, app/bin/run_job.php).
     */
    public static function version(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cache = self::cachePath();
        if ($cache !== null) {
            $cached = trim((string) @file_get_contents($cache));
            if ($cached !== '' && preg_match('/^\d+\.\d+\.\d+/', $cached)) {
                return self::$memo = $cached;
            }
        }

        return self::$memo = View::t('common.unknown');
    }



    // ---------------------------------------------------------------- wywołania
    //
    // Kontrakt: docs/KONTRAKT_CLI.md. PHP przekazuje ścieżki i konfigurację,
    // odbiera pliki i JSON — nie interpretuje HTML-a i nie liczy metryk (CLAUDE.md §4).



    /*
     * `launchWorker()` USUNIĘTE.
     *
     * Uruchamiało proces roboczy przez `proc_open` zaraz po zatwierdzeniu raportu.
     * PHP-FPM na lh.pl ma `proc_open` na liście `disable_functions`, więc metoda
     * nie miała prawa zadziałać z przeglądarki — a to była jedyna ścieżka, z której
     * ją wołano. Zadania podnosi teraz cron (co minutę, app/bin/run_job.php).
     *
     * Po ewentualnym przejściu na Cloud Server ograniczenie znika i natychmiastowy
     * start można przywrócić — patrz docs/OGRANICZENIA_HOSTINGU.md.
     */




    private static function cachePath(): ?string
    {
        $storage = Config::get('STORAGE_PATH');
        if ($storage === null || !is_dir($storage) || !is_writable($storage)) {
            return null;
        }
        return rtrim($storage, '/') . '/.engine_version';
    }
}
