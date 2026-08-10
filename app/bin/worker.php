<?php
declare(strict_types=1);

/**
 * Worker kolejki. Uruchamiany z crona co 60 s (deploy/crontab.example).
 *
 * Cron zamiast demona, bo hosting współdzielony nie gwarantuje długo żyjących procesów.
 * Blokada w Redis zapobiega nakładaniu się uruchomień.
 *
 * Przebieg:
 *   1. Blokada ca:lock:worker (TTL nieco dłuższy niż ENGINE_TIMEOUT)
 *   2. Pobranie zadania z listy ca:jobs
 *   3. proc_open na PYTHON_BIN -m coachanalyze build ... z limitem czasu
 *   4. Odczyt meta.json, zapis coverage i warnings, aktualizacja statusu
 *   5. Zwolnienie blokady
 *
 * Kody wyjścia silnika: docs/KONTRAKT_CLI.md
 * Traceback trafia do logu, nigdy do bazy ani do przeglądarki.
 */
