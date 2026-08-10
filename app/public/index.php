<?php
declare(strict_types=1);

/**
 * Jedyny plik wystawiony przez nginx. Reszta aplikacji leży poza katalogiem publicznym.
 *
 * Trasy publiczne:  /r/{club_key}/{token}  — raport read-only, bez sesji
 * Trasy panelu:     /                       — wymagają zalogowania
 *
 * UWAGA: przy niepoprawnym club_key i przy niepoprawnym tokenie odpowiedź musi być
 * IDENTYCZNA (404, ten sam czas odpowiedzi). Inaczej klucz klubu da się wysondować.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

// Router do implementacji w Etapie 4a.
http_response_code(503);
echo 'CoachAnalyze — aplikacja w budowie.';
