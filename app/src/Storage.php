<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Ścieżki do plików użytkownika. Wszystko poza katalogiem publicznym.
 *
 * Pliki NIGDY nie są serwowane bezpośrednio przez serwer WWW (CLAUDE.md §5).
 * Nazwa pliku jest losowa — nazwa oryginalna bywa nazwą klubu i przeciwnika,
 * a ścieżka bywa widoczna w logach i komunikatach błędów.
 *
 * UWAGA WDROŻENIOWA: `open_basedir` PHP-FPM jest ograniczony do katalogu domeny
 * (docs/OGRANICZENIA_HOSTINGU.md). Zapis uploadu dzieje się w FPM, więc
 * STORAGE_PATH musi być dla FPM osiągalny. CLI i Python nie mają tego
 * ograniczenia. Sprawdzamy to jawnie i mówimy wprost, zamiast pozwalać
 * `move_uploaded_file` zwrócić false bez wyjaśnienia.
 */
final class Storage
{
    public static function root(): string
    {
        return rtrim(Config::require('STORAGE_PATH'), '/');
    }

    /** Katalog uploadów z podziałem na rok i miesiąc — inaczej po sezonie jest tam 500 plików. */
    public static function uploadDir(): string
    {
        return self::ensure(self::root() . '/uploads/' . date('Y/m'));
    }

    public static function reportDir(): string
    {
        return self::ensure(self::root() . '/reports');
    }

    /** Artefakty robocze silnika (canon, metrics, meta) — nie są serwowane nigdy. */
    public static function jobDir(int $jobId): string
    {
        return self::ensure(self::root() . '/jobs/' . $jobId);
    }

    private static function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Nie mogę utworzyć katalogu: {$dir}");
        }
        // Zapisywalność sprawdzamy PRÓBĄ ZAPISU. `is_writable` na ścieżce spoza
        // open_basedir zwraca false także dla katalogu, do którego da się pisać —
        // i odwrotnie bywa mylące. Liczy się to, czy plik faktycznie powstanie.
        $probka = $dir . '/.probe';
        if (@file_put_contents($probka, '') === false) {
            throw new \RuntimeException(
                "Katalog nie jest zapisywalny: {$dir}. "
                . 'Sprawdź uprawnienia i open_basedir dla PHP-FPM.'
            );
        }
        @unlink($probka);
        return $dir;
    }

    /** Losowa nazwa pliku. 8 bajtów z CSPRNG — nazwa nie niesie żadnej informacji. */
    public static function randomName(string $extension): string
    {
        return bin2hex(random_bytes(8)) . '.' . ltrim($extension, '.');
    }

    /**
     * Ścieżka jest bezpieczna tylko wtedy, gdy po rozwinięciu leży wewnątrz
     * STORAGE_PATH. Bez tego wpis `../../` w bazie pozwoliłby odczytać dowolny
     * plik dostępny dla procesu.
     */
    public static function isInside(string $path): bool
    {
        $real = realpath($path);
        $root = realpath(self::root());
        return $real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR);
    }

    /** Sprawdzenie startowe — używane przez ekran uploadu, żeby powiedzieć prawdę od razu. */
    public static function writable(): bool
    {
        try {
            self::uploadDir();
            return true;
        } catch (\Throwable $e) {
            error_log('storage: ' . $e->getMessage());
            return false;
        }
    }
}
