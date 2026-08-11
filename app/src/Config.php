<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Konfiguracja z pliku .env. Sekrety nigdy nie trafiają do kodu ani do repozytorium.
 *
 * Czytamy własnym parserem, a nie `parse_ini_file`, bo INI ma swoje zasady cytowania
 * i potrafi zjeść znaki, które w haśle bazy są całkowicie legalne.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(?string $path = null): void
    {
        if (self::$loaded) {
            return;
        }

        // PRÓBUJEMY ODCZYTAĆ, zamiast najpierw pytać, czy plik istnieje.
        //
        // Na lh.pl `is_file()` na ścieżce spoza `open_basedir` zwraca false także
        // wtedy, gdy zasób jest osiągalny — sprawdzenie pliku podlega ograniczeniu,
        // a samo otwarcie już nie. Wstępne sprawdzenie kłamało więc dokładnie tam,
        // gdzie miało pomóc: `.env` nie był wczytywany, brakowało REDIS_SOCKET,
        // a ekran logowania mówił „chwilowo niedostępne".
        //
        // Ta sama zasada dotyczy gniazda Redis (RedisClient::connect) —
        // szczegóły w docs/OGRANICZENIA_HOSTINGU.md.
        $path ??= self::envPath();
        $raw = @file_get_contents($path);

        if ($raw !== false) {
            self::$values = self::parse($raw);
        } else {
            // Bez `.env` aplikacja nie ma haseł do bazy ani ścieżki do silnika.
            // Do logu, nigdy do przeglądarki.
            error_log('Config: nie udało się odczytać pliku konfiguracji: ' . $path);
        }

        self::$loaded = true;
    }

    /**
     * Położenie `.env` — JEDNA jawna ścieżka, bez przeszukiwania drzewa w górę.
     *
     * Poprzednia wersja sprawdzała po kolei cztery katalogi nadrzędne. W produkcji
     * dwa ostatnie leżą poza `open_basedir`, więc każde sprawdzenie kończyło się
     * ostrzeżeniem PHP — trzema na jednym żądaniu, wypisanymi nad formularzem
     * logowania.
     */
    public static function envPath(): string
    {
        // Nadpisanie na potrzeby nietypowego wdrożenia albo testu.
        $override = getenv('CA_ENV_PATH');
        if (is_string($override) && $override !== '') {
            return $override;
        }

        // CA_ROOT ustala bootstrap (i niezależnie app/public/index.php).
        // Zapasowo liczymy je stąd: `app/src/` nie zmienia położenia względem `app/`.
        $root = defined('CA_ROOT') ? CA_ROOT : dirname(__DIR__, 2);

        return $root . '/.env';
    }

    /** @return array<string,string> */
    private static function parse(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            // Cudzysłowy zdejmujemy tylko wtedy, gdy otaczają całą wartość.
            if (strlen($value) >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::load();
        $value = self::$values[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return (string) $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            // Komunikat trafia do logu, nie do przeglądarki — patrz bootstrap.php.
            throw new \RuntimeException("Brak wymaganej pozycji w .env: {$key}");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /** Wyłącznie na potrzeby testów — produkcja czyta plik raz. */
    public static function reset(array $values = []): void
    {
        self::$values = $values;
        self::$loaded = true;
    }
}
