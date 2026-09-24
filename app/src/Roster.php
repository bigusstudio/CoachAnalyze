<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Skład meczu — zawodnicy, numery, pozycje, minuty (Sesja 6 pivotu „viewer").
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PO CO OSOBNA TABELA, SKORO EKSPORT MA KOLUMNĘ ZAWODNIKA.
 *
 * Bo kolumna zawodnika w eksporcie bywa pusta (pułapka 4) i bo niesie WYŁĄCZNIE
 * tych, którzy dostali taga. Zawodnik, który rozegrał 90 minut i nie zrobił nic,
 * co analityk tagował, w eksporcie nie istnieje — a w składzie owszem. Minuty
 * i numery nie mają w eksporcie żadnego odpowiednika.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * DOPASOWANIE DO ZDARZEŃ IDZIE PO PEŁNEJ NAZWIE, PRZEZ RÓWNOŚĆ — pułapka 7
 * z CLAUDE.md. Fragment nazwiska łapałby dwóch różnych zawodników („Nowak"
 * wewnątrz „Nowakowski") i nikt by tego nie zauważył, bo obie liczby wyglądają
 * sensownie. Scalanie robi szablon raportu, tą samą regułą.
 */
final class Roster
{
    /**
     * Ile wierszy ma formularz składu.
     *
     * Osiemnaście, czyli jedenastu i siedmiu rezerwowych — tyle zgłasza się
     * do protokołu. Pusty wiersz nie jest zapisywany, więc liczba jest górnym
     * ograniczeniem formularza, a nie wymogiem wobec operatora.
     */
    public const WIERSZY = 18;

    /** Górna granica minut. Dogrywka to 120; wyżej zaczyna się literówka. */
    public const MAX_MINUT = 120;

    /**
     * Skład meczu dla klubu. Wyjściowa jedenastka przed rezerwowymi,
     * potem po numerze, a na końcu alfabetycznie — kolejność ma być stała,
     * żeby porównanie dwóch meczów nie wymagało szukania wzrokiem.
     *
     * @return list<array<string,mixed>>
     */
    public static function forMatch(int $matchId, int $clubId): array
    {
        return Db::all(
            'SELECT id, player, number, position, minutes, is_starter
               FROM match_players
              WHERE match_id = :m AND club_id = :c
              ORDER BY is_starter DESC,
                       CASE WHEN number IS NULL THEN 1 ELSE 0 END, number,
                       player',
            ['m' => $matchId, 'c' => $clubId]
        );
    }

    /**
     * Zapis całego składu JEDNYM ruchem: kasujemy i wstawiamy od nowa.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * TO JEDYNE MIEJSCE W PROJEKCIE, W KTÓRYM KASUJEMY WIERSZE — i ma powód.
     *
     * Formularz składu jest EDYTOWALNĄ TABELĄ: operator poprawia minuty,
     * wykreśla zawodnika, dopisuje innego i zapisuje całość. Dopisywanie bez
     * kasowania zostawiałoby w bazie zawodników, których operator właśnie usunął
     * z ekranu, a raport pokazywałby skład, którego nikt nie zatwierdził.
     *
     * Zakres kasowania jest ciasny: JEDEN mecz i JEDEN klub. Skład rywala,
     * inne mecze i wszystko poza tą tabelą zostaje nietknięte.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @param list<array<string,mixed>> $wiersze
     * @return int liczba zapisanych zawodników
     */
    public static function save(int $matchId, int $clubId, array $wiersze, ?int $userId = null): int
    {
        $czyste = self::normalizuj($wiersze);

        Db::run('DELETE FROM match_players WHERE match_id = :m AND club_id = :c',
            ['m' => $matchId, 'c' => $clubId]);

        foreach ($czyste as $z) {
            Db::run(
                'INSERT INTO match_players (match_id, club_id, player, number, position,
                                            minutes, is_starter, created_at)
                 VALUES (:m, :c, :p, :nr, :poz, :min, :start, :now)',
                [
                    'm' => $matchId, 'c' => $clubId, 'p' => $z['player'],
                    'nr' => $z['number'], 'poz' => $z['position'],
                    'min' => $z['minutes'], 'start' => $z['is_starter'],
                    'now' => Stats::now(),
                ]
            );
        }

        Audit::log('roster.saved', $userId, 'match', $matchId, ['count' => count($czyste)]);
        return count($czyste);
    }

    /**
     * Wiersze formularza sprowadzone do kształtu tabeli.
     *
     * WIERSZ BEZ NAZWISKA ZNIKA — formularz ma osiemnaście wierszy, a skład
     * rzadko tyle. Pusty wiersz z wpisanymi minutami też znika: minuty bez
     * zawodnika nie są niczyimi minutami.
     *
     * POWTÓRZONA NAZWA ZNIKA (zostaje pierwsza). Klucz `UNIQUE` i tak by na to
     * nie pozwolił, ale wywrócenie zapisu całego składu przez jedną literówkę
     * byłoby karą nieproporcjonalną; dopasowanie zdarzeń po nazwie i tak nie
     * umiałoby rozróżnić tych dwóch wierszy.
     *
     * @param list<array<string,mixed>> $wiersze
     * @return list<array{player:string,number:?int,position:?string,minutes:?int,is_starter:int}>
     */
    public static function normalizuj(array $wiersze): array
    {
        $out = [];
        $widziane = [];

        foreach ($wiersze as $w) {
            if (!is_array($w)) {
                continue;
            }
            $nazwa = trim((string) ($w['player'] ?? ''));
            if ($nazwa === '') {
                continue;
            }
            $klucz = Clubs::normalize($nazwa);
            if (isset($widziane[$klucz])) {
                continue;
            }
            $widziane[$klucz] = true;

            $out[] = [
                'player'     => mb_substr($nazwa, 0, 160),
                'number'     => self::liczba($w['number'] ?? null, 1, 999),
                'position'   => self::pozycja($w['position'] ?? null),
                'minutes'    => self::liczba($w['minutes'] ?? null, 0, self::MAX_MINUT),
                'is_starter' => !empty($w['is_starter']) ? 1 : 0,
            ];
            if (count($out) >= self::WIERSZY) {
                break;
            }
        }

        return $out;
    }

    /**
     * Skład jako propozycja z kolumny zawodnika eksportu.
     *
     * PROPOZYCJA, NIE ZAPIS. Eksport niesie wyłącznie tych, którzy dostali taga,
     * i nie wie nic o minutach ani numerach — zapisany bez pytania udawałby
     * skład, którym nie jest. Operator dostaje listę do zatwierdzenia.
     *
     * Kolejność: po liczbie zdarzeń malejąco, czyli od tych, o których eksport
     * mówi najwięcej. Alfabetycznie przy równej liczbie, żeby wynik był
     * powtarzalny między wywołaniami.
     *
     * @param array<string,mixed> $meta zdekodowany `coverage_json` importu
     * @return list<array{player:string,events:int}>
     */
    public static function zEksportu(array $meta): array
    {
        $licznik = [];
        foreach ((array) (($meta['dictionary'] ?? [])['players'] ?? []) as $poz) {
            $nazwa = trim((string) ($poz['player'] ?? $poz['name'] ?? ''));
            if ($nazwa !== '') {
                $licznik[$nazwa] = (int) ($poz['count'] ?? 0);
            }
        }

        if ($licznik === []) {
            return [];
        }

        uksort($licznik, static function (string $a, string $b) use ($licznik): int {
            return [$licznik[$b], $a] <=> [$licznik[$a], $b];
        });

        $out = [];
        foreach ($licznik as $nazwa => $ile) {
            $out[] = ['player' => $nazwa, 'events' => $ile];
        }
        return $out;
    }

    /**
     * Skład w kształcie, który silnik dostaje w `config.match.roster`.
     *
     * KONTRAKT JEST PŁASKI i nie niesie identyfikatorów z bazy: silnik ma dać
     * się uruchomić z palca na dowolnej maszynie (CLAUDE.md §4), a `id` wiersza
     * nie znaczy tam nic. Nazwa jest kluczem dopasowania i tyle wystarczy.
     *
     * @return list<array<string,mixed>>
     */
    public static function engineConfig(int $matchId, int $clubId): array
    {
        $out = [];
        foreach (self::forMatch($matchId, $clubId) as $z) {
            $out[] = [
                'player'     => (string) $z['player'],
                'number'     => $z['number'] !== null ? (int) $z['number'] : null,
                'position'   => $z['position'] !== null ? (string) $z['position'] : null,
                'minutes'    => $z['minutes'] !== null ? (int) $z['minutes'] : null,
                'is_starter' => (bool) $z['is_starter'],
            ];
        }
        return $out;
    }

    /** Liczba w zakresie albo `null`. Pusty napis to brak danych, nie zero. */
    private static function liczba(mixed $wartosc, int $min, int $max): ?int
    {
        if ($wartosc === null || trim((string) $wartosc) === '' || !is_numeric($wartosc)) {
            return null;
        }
        $n = (int) $wartosc;
        return ($n < $min || $n > $max) ? null : $n;
    }

    /** Pozycja: krótki napis bez znaków sterujących. */
    private static function pozycja(mixed $wartosc): ?string
    {
        $tekst = trim((string) ($wartosc ?? ''));
        if ($tekst === '') {
            return null;
        }
        return mb_substr(preg_replace('/\s+/u', ' ', $tekst) ?? $tekst, 0, 16);
    }
}
