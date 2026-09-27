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
     * Plik zapisuje `deploy.sh` przy każdym wdrożeniu (golden layout W1).
     * Cron go NIE nadpisuje — tylko sprawdza, czy zainstalowany silnik mówi to
     * samo (`EngineRunner::refreshVersion`).
     */
    public static function version(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        /*
         * PAMIĘCI SESJI JUŻ NIE MA (golden layout W1). Wersja zapamiętana
         * w sesji przeżywała wdrożenie: produkcja miała 0.16.4, a stopka
         * zalogowanego pokazywała 0.16.1 — tę, którą odczytał przy logowaniu.
         * Odczyt małego pliku przy renderze kosztuje mniej niż stopka kłamiąca
         * o wersji, na której stoją raporty (CLAUDE.md §7).
         */

        // 1. ARTEFAKT WDROŻENIA — ścieżka produkcyjna. Zapisuje go `deploy.sh`
        //    z `engine/coachanalyze/__init__.py` wdrażanej rewizji.
        $cache = self::cachePath();
        if ($cache !== null) {
            $cached = trim((string) @file_get_contents($cache));
            if ($cached !== '' && preg_match('/^\d+\.\d+\.\d+/', $cached) === 1) {
                return self::$memo = $cached;
            }
        }

        /*
         * 2. PLIK ŹRÓDŁOWY SILNIKA — wariant zapasowy, DZIAŁA POZA PRODUKCJĄ.
         *
         * `engine/` leży POZA katalogiem domeny (deploy.sh synchronizuje tam
         * wyłącznie `app/`), a `open_basedir` sprawdza ścieżkę po rozwinięciu —
         * więc na lh.pl PHP-FPM tego pliku NIE PRZECZYTA i odczyt cicho zwróci
         * `false`. To jest oczekiwane: tam rozstrzyga artefakt z punktu 1.
         *
         * Wariant istnieje dla środowisk, w których artefaktu nie ma, a repozytorium
         * jest pod ręką: testy, podgląd, praca lokalna. Bez niego stopka pokazuje
         * „nieznana" zawsze, dopóki ktoś nie uruchomi crona — czyli wygląda
         * na awarię przy poprawnie działającym panelu.
         *
         * `@` jest tu konieczne, nie wygodne: sprawdzenie `is_file()` na ścieżce
         * spoza `open_basedir` samo wypisuje ostrzeżenie (docs/OGRANICZENIA_HOSTINGU.md,
         * „is_file() zwraca false dla działającego zasobu"). Próbujemy odczytać
         * i obsługujemy niepowodzenie, zamiast pytać, czy się da.
         */
        $zrodlo = @file_get_contents(dirname(__DIR__, 2) . '/engine/coachanalyze/__init__.py');
        if (is_string($zrodlo)
            && preg_match('/^__version__\s*=\s*[\'"]([\d.]+)[\'"]/m', $zrodlo, $m) === 1
        ) {
            return self::$memo = $m[1];
        }

        // 3. Nie udało się nic odczytać — i tylko wtedy „nieznana".
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
