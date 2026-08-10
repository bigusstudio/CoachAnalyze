<?php
declare(strict_types=1);

/**
 * Wspólna inicjalizacja: konfiguracja, baza, Redis, sesja.
 * Do implementacji w Etapie 1–3.
 *
 * Zasady:
 *  - sekrety wyłącznie z .env, nigdy w kodzie
 *  - błędy nigdy nie trafiają do przeglądarki w środowisku produkcyjnym
 *  - żadnych obliczeń metryk piłkarskich w tej warstwie (CLAUDE.md §4)
 */
