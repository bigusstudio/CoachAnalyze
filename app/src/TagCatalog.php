<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Katalog tagów klubu (migracja 015). Zapełniany z `meta.json` przy imporcie.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PO CO: ŻEBY ODRÓŻNIĆ „POLICZONO ZERO" OD „NIE MA CZEGO LICZYĆ".
 *
 * Metryka liczy po surowej nazwie tagu. Klub, który nie taguje wejść w SBZ,
 * dostawał dotąd „0 wejść w SBZ" — zdanie nieprawdziwe i nie do odróżnienia
 * od meczu, w którym drużyna faktycznie ani razu nie weszła w pole karne.
 *
 * Katalog mówi, co klub W OGÓLE TAGUJE. Brak tagu w katalogu daje metryce
 * wartość `null` i wpis w „Wymaga uwagi" (CLAUDE.md §8).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ŹRÓDŁEM SĄ DANE, KTÓRE JUŻ MAMY. `meta.dictionary` (silnik 0.10.0) niesie
 * histogram tagów i etykiet, `meta.palette` (0.11.0) — barwy z pliku projektu.
 * Ta klasa niczego nie liczy od nowa; przepisuje gotowe liczby do tabeli.
 */
final class TagCatalog
{
    /**
     * UPSERT katalogu z `meta.json` jednego importu.
     *
     * KASOWANIA NIE MA I NIE BĘDZIE. Tag, który zniknął z eksportów trzy
     * miesiące temu, zostaje w katalogu — i właśnie to ma być widoczne, bo
     * znaczy, że klub zmienił metodykę. Katalog jest historią tagowania,
     * nie zdjęciem ostatniego importu.
     *
     * @return int liczba dotkniętych pozycji
     */
    public static function upsertFromMeta(int $clubId, int $importId, array $meta): int
    {
        if ($clubId <= 0) {
            return 0;
        }

        $slownik = $meta['dictionary'] ?? null;
        if (!is_array($slownik)) {
            return 0;
        }

        $paleta = is_array($meta['palette'] ?? null) ? $meta['palette'] : [];
        $teraz  = Stats::now();
        $dotkniete = 0;

        foreach ([['tags', 'tag', 'tag'], ['labels', 'label', 'label']] as [$klucz, $pole, $kind]) {
            foreach ((array) ($slownik[$klucz] ?? []) as $pozycja) {
                $nazwa = trim((string) ($pozycja[$pole] ?? $pozycja['name'] ?? ''));
                if ($nazwa === '') {
                    continue;
                }
                $liczba = (int) ($pozycja['count'] ?? 0);
                // Barwa z palety pliku projektu. NULL, gdy import szedł bez
                // `--json` — poprawny stan, nie brak do załatania.
                $barwa = $paleta[$klucz][$nazwa] ?? null;

                self::upsert($clubId, $kind, $nazwa, is_string($barwa) ? $barwa : null,
                             $importId, $liczba, $teraz);
                $dotkniete++;
            }
        }

        return $dotkniete;
    }

    /** Katalog klubu do ekranów. Tagi przed etykietami, alfabetycznie. */
    public static function forClub(int $clubId): array
    {
        return Db::all(
            "SELECT kind, name, color, seen_matches, seen_events, updated_at
               FROM tag_catalog
              WHERE club_id = :c
              ORDER BY CASE kind WHEN 'tag' THEN 0 ELSE 1 END, name",
            ['c' => $clubId]
        );
    }

    /**
     * Jedna pozycja katalogu.
     *
     * ROZGAŁĘZIENIE ZAMIAST `ON DUPLICATE KEY UPDATE`: ta składnia jest
     * MySQL-owa, a testy chodzą na SQLite (tam `ON CONFLICT`). Dwa warianty
     * jednego zapytania to dwie ścieżki, z których w testach działa jedna —
     * a to jest dokładnie ten rodzaj różnicy, który wychodzi dopiero
     * na produkcji. Odczyt plus zapis działa tak samo wszędzie.
     *
     * Wyścig dwóch importów tego samego klubu w tej samej sekundzie dałby tu
     * błąd klucza unikalnego. Nie zabezpieczamy się przed nim: kolejka cron
     * przetwarza zadania POJEDYNCZO (docs/OGRANICZENIA_HOSTINGU.md), więc
     * sytuacja nie występuje, a blokada kosztowałaby transakcję na każdy tag.
     */
    private static function upsert(
        int $clubId, string $kind, string $nazwa, ?string $barwa,
        int $importId, int $liczba, string $teraz
    ): void {
        $istnieje = Db::one(
            'SELECT id, seen_matches, seen_events, color FROM tag_catalog
              WHERE club_id = :c AND kind = :k AND name = :n',
            ['c' => $clubId, 'k' => $kind, 'n' => $nazwa]
        );

        if ($istnieje === null) {
            Db::run(
                'INSERT INTO tag_catalog
                   (club_id, kind, name, color, first_seen_import_id, last_seen_import_id,
                    seen_matches, seen_events, updated_at)
                 VALUES (:c, :k, :n, :col, :imp_f, :imp_l, 1, :ev, :now)',
                ['c' => $clubId, 'k' => $kind, 'n' => $nazwa, 'col' => $barwa,
                 'imp_f' => $importId, 'imp_l' => $importId, 'ev' => $liczba, 'now' => $teraz]
            );
            return;
        }

        Db::run(
            'UPDATE tag_catalog
                SET last_seen_import_id = :imp,
                    seen_matches = seen_matches + 1,
                    seen_events  = seen_events + :ev,
                    -- Barwa z NOWSZEGO importu wygrywa, ale pusta nie kasuje
                    -- starej: import bez pliku projektu nie ma jej skąd wziąć.
                    color = COALESCE(:col, color),
                    updated_at = :now
              WHERE id = :id',
            ['imp' => $importId, 'ev' => $liczba, 'col' => $barwa,
             'now' => $teraz, 'id' => (int) $istnieje['id']]
        );
    }
}
