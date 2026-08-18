<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Tagi i etykiety zignorowane NA STAŁE dla klubu.
 *
 * Import przynosi pozycje, których w templacie nie ma. Dla każdej user ma trzy
 * wyjścia (Sesja 6): dodać do templatu, pominąć w tym imporcie, albo zignorować
 * na stałe — i to ostatnie zapisuje się właśnie tutaj.
 *
 * DLACZEGO OSOBNA TABELA, A NIE POLE W TEMPLACIE: „nie pytaj mnie więcej o ten
 * tag" nie jest zmianą definicji raportu. Gdyby siedziało w `config`, każde
 * odhaczenie śmieciowej pozycji podbijałoby wersję templatu i oznaczało
 * WSZYSTKIE dotychczasowe raporty klubu jako nieaktualne — z propozycją
 * przeliczenia ich wszystkich, bez jednej zmiany w treści.
 *
 * ZIGNOROWANE NIE ZNACZY UKRYTE. Pozycje z tej tabeli są wyliczone z nazwy
 * w raporcie pokrycia (CLAUDE.md §8: brak danych ma być widoczny, nie zamaskowany).
 */
final class IgnoredTags
{
    public const TAG = 'tag';
    public const LABEL = 'label';

    /**
     * Wszystkie pozycje klubu, w kolejności do wyświetlenia.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(int $clubId): array
    {
        return Db::all(
            'SELECT i.*, u.display_name AS author
               FROM club_ignored_tags i
               LEFT JOIN users u ON u.id = i.created_by
              WHERE i.club_id = :club
              ORDER BY i.source_type, i.raw_name',
            ['club' => $clubId]
        );
    }

    /**
     * Zbiór nazw do szybkiego sprawdzania przy imporcie.
     *
     * Zwraca tablicę `['tag' => [nazwa => true, …], 'label' => […]]`. Kształt
     * ze stałymi kluczami, a nie płaska lista, bo tag i etykieta o tej samej
     * nazwie to DWIE różne rzeczy i pomylenie ich cicho zmieniłoby liczby.
     *
     * @return array{tag: array<string,bool>, label: array<string,bool>}
     */
    public static function lookup(int $clubId): array
    {
        $out = [self::TAG => [], self::LABEL => []];
        foreach (self::all($clubId) as $row) {
            $type = (string) $row['source_type'];
            if (isset($out[$type])) {
                $out[$type][(string) $row['raw_name']] = true;
            }
        }
        return $out;
    }

    /**
     * Czy pozycja jest zignorowana.
     *
     * PORÓWNANIE PRZEZ RÓWNOŚĆ PEŁNEJ NAZWY, nigdy przez zawieranie —
     * to ta sama zasada, która pilnuje dopasowania etykiet w silniku
     * (CLAUDE.md §3, pułapka 7: `substring` łapie `CELNY` wewnątrz `NIECELNY`).
     */
    public static function has(int $clubId, string $sourceType, string $rawName): bool
    {
        return Db::one(
            'SELECT id FROM club_ignored_tags
              WHERE club_id = :club AND source_type = :type AND raw_name = :name',
            ['club' => $clubId, 'type' => self::normalizeType($sourceType), 'name' => $rawName]
        ) !== null;
    }

    /**
     * Zapis pozycji. Idempotentny: powtórne zignorowanie tej samej nazwy nie
     * jest błędem użytkownika i nie ma prawa wywalić importu.
     *
     * Zwraca true, gdy wpis faktycznie powstał; false, gdy już był.
     */
    public static function add(int $clubId, string $sourceType, string $rawName, int $userId): bool
    {
        $rawName = trim($rawName);
        if ($rawName === '') {
            return false;
        }

        try {
            Db::run(
                'INSERT INTO club_ignored_tags (club_id, source_type, raw_name, created_by, created_at)
                 VALUES (:club, :type, :name, :by, :now)',
                [
                    'club' => $clubId,
                    'type' => self::normalizeType($sourceType),
                    'name' => $rawName,
                    'by'   => $userId,
                    'now'  => Stats::now(),
                ]
            );
        } catch (\PDOException $e) {
            // 23000 = naruszenie klucza UNIQUE. Ten sam kod w MySQL i SQLite,
            // w przeciwieństwie do treści komunikatu.
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }

        Audit::log('club.tag_ignored', $userId, 'club', $clubId, [
            'source_type' => $sourceType,
            'raw_name'    => $rawName,
        ]);
        return true;
    }

    /**
     * Cofnięcie decyzji.
     *
     * Istnieje, bo „na stałe" bez odwrotu jest pułapką: jedno kliknięcie
     * w pośpiechu przy imporcie wycinałoby tag z analiz klubu na zawsze,
     * a jedynym ratunkiem byłby SQL na produkcji.
     */
    public static function forget(int $clubId, int $id, int $userId): void
    {
        Db::run(
            'DELETE FROM club_ignored_tags WHERE id = :id AND club_id = :club',
            ['id' => $id, 'club' => $clubId]
        );
        Audit::log('club.tag_unignored', $userId, 'club', $clubId, ['id' => $id]);
    }

    /** Nieznany typ traktujemy jak tag — kolumna jest ENUM i odrzuciłaby śmieć. */
    private static function normalizeType(string $sourceType): string
    {
        return $sourceType === self::LABEL ? self::LABEL : self::TAG;
    }
}
