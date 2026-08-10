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
        $path ??= self::locate();
        if ($path !== null && is_readable($path)) {
            self::$values = self::parse((string) file_get_contents($path));
        }
        self::$loaded = true;
    }

    /**
     * .env leży poza katalogiem publicznym. Na serwerze układ katalogów jest inny
     * niż lokalnie (docs/OGRANICZENIA_HOSTINGU.md), więc szukamy w górę drzewa
     * zamiast zakładać jedną ścieżkę.
     */
    private static function locate(): ?string
    {
        $dir = dirname(__DIR__);
        for ($i = 0; $i < 4; $i++) {
            if (is_file($dir . '/.env')) {
                return $dir . '/.env';
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
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
