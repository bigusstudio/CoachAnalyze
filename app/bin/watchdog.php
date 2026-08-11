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
use CoachAnalyze\EngineRunner;
use CoachAnalyze\Db;

require dirname(__DIR__) . '/src/bootstrap.php';

// Uruchamianie procesow lezy POZA drzewem autoloadera - patrz naglowek klasy.
require __DIR__ . '/EngineRunner.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$zwolnione = Alerts::releaseStuckJobs();
if ($zwolnione > 0) {
    fwrite(STDOUT, "Zwolniono zawieszonych zadań: {$zwolnione}\n");
}

// Zadania z kolejki podnosi app/bin/run_job.php, uruchamiany osobnym wpisem
// crona co minutę. Nadzorca ich nie startuje — dublowanie tej roli dałoby dwa
// procesy walczące o te same wiersze.
$czekajace = (int) Db::one("SELECT COUNT(*) c FROM jobs WHERE status = 'queued'")['c'];
if ($czekajace > 0) {
    fwrite(STDOUT, "Zadań czekających w kolejce: {$czekajace}\n");
}

$wygasle = \CoachAnalyze\Remember::purgeExpired();
if ($wygasle > 0) {
    fwrite(STDOUT, "Usunięto wygasłych tokenów trwałych: {$wygasle}\n");
}

EngineRunner::refreshVersion();

$alerty = Alerts::all();
foreach ($alerty as $alert) {
    fwrite(STDOUT, sprintf("[%s] %s — %s\n", strtoupper($alert['level']), $alert['msg'], $alert['hint']));
}

// Kod wyjścia niezerowy przy błędach, żeby cron mógł wysłać powiadomienie.
exit(array_filter($alerty, static fn($a) => $a['level'] === Alerts::LEVEL_ERROR) === [] ? 0 : 1);
