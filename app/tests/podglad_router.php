<?php
declare(strict_types=1);

/**
 * Router wbudowanego serwera PHP — WYŁĄCZNIE do podglądu i zrzutów ekranu.
 *
 * PO CO ISTNIEJE. `php -S … app/public/index.php` kieruje do routera KAŻDE
 * żądanie, także `/assets/app.css` — a router odpowiada na nie przekierowaniem
 * na `/login`. Strona renderuje się wtedy zupełnie bez arkusza stylów i wygląda
 * na zepsutą, choć zepsuty jest wyłącznie sposób uruchomienia.
 *
 * Kosztowało to jedną turę zrzutów ekranu przy sesji 3,5: wszystkie cztery
 * wyszły identyczne, bo były tą samą stroną logowania bez stylów.
 *
 * Produkcja stoi na Apache i tego pliku nie widzi. Przeloty HTTP w testach też
 * go nie potrzebują — sprawdzają HTML, nie wygląd.
 *
 * Uruchomienie:
 *   CA_ENV_PATH=/tmp/.env.podglad \
 *     php -S 127.0.0.1:8977 -t app/public app/tests/podglad_router.php
 */
$sciezka = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Plik, który istnieje w katalogu publicznym, serwuje sam serwer — dokładnie
// tak, jak robi to Apache w produkcji.
if ($sciezka !== '/' && is_file(dirname(__DIR__) . '/public' . $sciezka)) {
    return false;
}

require dirname(__DIR__) . '/public/index.php';
