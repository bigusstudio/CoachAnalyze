<?php
declare(strict_types=1);

/**
 * Wspólna inicjalizacja: konfiguracja, autoloader, obsługa błędów.
 *
 * Zasady:
 *  - sekrety wyłącznie z .env, nigdy w kodzie
 *  - błędy nigdy nie trafiają do przeglądarki w środowisku produkcyjnym
 *  - żadnych obliczeń metryk piłkarskich w tej warstwie (CLAUDE.md §4)
 */

namespace CoachAnalyze;

/**
 * KOLEJNOŚĆ MA ZNACZENIE — te trzy linie muszą stać PRZED czymkolwiek,
 * co dotyka dysku.
 *
 * Wcześniej `display_errors` ustawiało się dopiero po `Config::load()`, więc
 * ostrzeżenia powstałe w trakcie szukania `.env` zdążyły już wylądować
 * w odpowiedzi HTTP — na ekranie logowania, nad formularzem. W produkcji żadne
 * ostrzeżenie PHP nie ma prawa trafić do przeglądarki (CLAUDE.md §5), więc
 * domyślnie wyłączamy je od pierwszej instrukcji, a nie od trzydziestej.
 *
 * Właściwy plik logu (LOG_PATH) podłączamy niżej, gdy konfiguracja jest już znana.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * CA_ROOT — katalog, w którym leży `app/`.
 *
 * `app/src/` nie zmienia położenia względem `app/` w żadnym układzie, więc dwa
 * poziomy w górę są tu deterministyczne. `app/public/index.php` ustala tę samą
 * stałą własną drogą, bo jako jedyny plik zmienia położenie przy wdrożeniu.
 */
if (!defined('CA_ROOT')) {
    define('CA_ROOT', dirname(__DIR__, 2));
}

require_once __DIR__ . '/Config.php';

/**
 * Autoloader dla przestrzeni CoachAnalyze\. Bez Composera — jedna reguła
 * odwzorowania nazwy klasy na plik wystarcza przy tej liczbie klas.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'CoachAnalyze\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

Config::load();

/**
 * Traceback nigdy nie trafia do przeglądarki (CLAUDE.md §5) — ta sama zasada,
 * co dla silnika Pythona. Do logu pełna treść, do użytkownika krótki komunikat.
 */
// Wyświetlanie błędów włączamy WYŁĄCZNIE poza produkcją i tylko na życzenie.
// Domyślnie jest już wyłączone (na górze pliku) — tutaj można je co najwyżej
// świadomie włączyć, nigdy odwrotnie.
$debug = Config::bool('APP_DEBUG', false) && !Config::isProduction();
if ($debug) {
    ini_set('display_errors', '1');
}

/**
 * Docelowy plik logu. Proces roboczy (app/bin/run_job.php) startuje odpięty,
 * ze strumieniami do /dev/null — bez tego ustawienia jego `error_log()` nie ma
 * dokąd pisać i pełny traceback silnika przepada. A ma trafiać do logu,
 * skoro nie wolno mu trafić do przeglądarki (CLAUDE.md §5).
 */
/**
 * Log MUSI gdzieś trafić.
 *
 * LOG_PATH wskazujący katalog spoza `open_basedir` sprawia, że `error_log()`
 * nie ma dokąd pisać — a wtedy awaria nie zostawia żadnego śladu. Dokładnie to
 * kosztowało godzinę zgadywania przy pierwszym wdrożeniu.
 *
 * Dlatego sprawdzamy zapisywalność PRÓBĄ ZAPISU (na tym hostingu `is_writable`
 * na ścieżce spoza open_basedir kłamie tak samo jak `is_file`), a przy
 * niepowodzeniu schodzimy do katalogu tymczasowego. Cicha diagnostyka
 * jest gorsza niż log w nieoczywistym miejscu.
 */
$logPath = Config::get('LOG_PATH');
$logSet = false;

if ($logPath !== null) {
    @mkdir(dirname($logPath), 0770, true);
    if (@file_put_contents($logPath, '', FILE_APPEND) !== false) {
        ini_set('error_log', $logPath);
        $logSet = true;
    }
}

if (!$logSet) {
    $fallback = sys_get_temp_dir() . '/coachanalyze.log';
    if (@file_put_contents($fallback, '', FILE_APPEND) !== false) {
        ini_set('error_log', $fallback);
        if ($logPath !== null) {
            error_log("Config: LOG_PATH ({$logPath}) jest niezapisywalny — log trafia do {$fallback}");
        }
    }
}

set_exception_handler(static function (\Throwable $e) use ($debug): void {
    error_log(sprintf(
        '[%s] %s w %s:%d%s%s',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), PHP_EOL, $e->getTraceAsString()
    ));

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Błąd: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    if ($debug) {
        echo '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>';
    } else {
        echo View::t('common.error');
    }
    exit(1);
});

// Nagłówki bezpieczeństwa dla całego panelu. Raport publiczny dokłada własne
// (X-Robots-Tag, Referrer-Policy) w Etapie 7.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}
