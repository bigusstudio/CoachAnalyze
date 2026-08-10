<?php
declare(strict_types=1);

namespace CoachAnalyze;

use PDO;

/**
 * Połączenie z bazą. Bez ORM-a — skala projektu tego nie wymaga (CLAUDE.md §8).
 *
 * Zapytania wyłącznie przez przygotowane polecenia z parametrami. Sklejanie SQL
 * z danych użytkownika jest w tym projekcie błędem, nie stylem.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            Config::get('DB_HOST', 'localhost'),
            Config::require('DB_NAME')
        );

        self::$pdo = new PDO($dsn, Config::require('DB_USER'), Config::get('DB_PASS', ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Prawdziwe przygotowane polecenia — emulacja składa zapytanie po stronie
            // sterownika i psuje typy w porównaniach.
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Wyłącznie na potrzeby testów i skryptów CLI. */
    public static function setPdo(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }
}
