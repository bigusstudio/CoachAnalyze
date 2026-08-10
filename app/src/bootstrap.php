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
$debug = Config::bool('APP_DEBUG', false) && !Config::isProduction();
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

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
