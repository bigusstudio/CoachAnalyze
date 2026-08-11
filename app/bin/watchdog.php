<?php
declare(strict_types=1);

/**
 * Siatka bezpieczeństwa kolejki. Uruchamiany z crona (deploy/crontab.example).
 *
 *   php app/bin/watchdog.php
 *
 * Ścieżka podstawowa NIE czeka na crona — panel startuje silnik natychmiast
 * przez proc_open (docs/OGRANICZENIA_HOSTINGU.md). Ten skrypt sprząta po
 * sytuacjach, w których tamta ścieżka zawiodła:
 *
 *   1. zadanie wisi w `running`, bo proces roboczy padł, a proces odpięty
 *      nie ma jak zmienić statusu — zwalniamy je do `failed`, żeby dało się ponowić,
 *   2. zadanie czeka w `queued`, bo proc_open się nie powiodło — uruchamiamy je,
 *   3. wypisujemy alerty, żeby cron mógł je wysłać mailem.
 */

use CoachAnalyze\Alerts;
use CoachAnalyze\Db;
use CoachAnalyze\Engine;

require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$zwolnione = Alerts::releaseStuckJobs();
if ($zwolnione > 0) {
    fwrite(STDOUT, "Zwolniono zawieszonych zadań: {$zwolnione}\n");
}

// Zadania, które nigdy nie wystartowały — panel zgłasza to operatorowi,
// ale cron ma je po prostu podnieść.
$czekajace = Db::all("SELECT id FROM jobs WHERE status = 'queued' ORDER BY id LIMIT 5");
foreach ($czekajace as $job) {
    if (Engine::launchWorker((int) $job['id'])) {
        fwrite(STDOUT, "Uruchomiono zadanie #{$job['id']}\n");
    }
}

$wygasle = \CoachAnalyze\Remember::purgeExpired();
if ($wygasle > 0) {
    fwrite(STDOUT, "Usunięto wygasłych tokenów trwałych: {$wygasle}\n");
}

$alerty = Alerts::all();
foreach ($alerty as $alert) {
    fwrite(STDOUT, sprintf("[%s] %s — %s\n", strtoupper($alert['level']), $alert['msg'], $alert['hint']));
}

// Kod wyjścia niezerowy przy błędach, żeby cron mógł wysłać powiadomienie.
exit(array_filter($alerty, static fn($a) => $a['level'] === Alerts::LEVEL_ERROR) === [] ? 0 : 1);
