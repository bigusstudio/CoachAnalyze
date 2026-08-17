<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Indeks współczynników (M1) — słownik metodyczny, do którego odsyła raport.
 *
 * HASŁA DOMYŚLNE SĄ STAŁĄ W KODZIE, wersje klubowe w tabeli `index_terms`
 * (migracja 010). Hasło efektywne dla klubu = najnowsza wersja klubowa,
 * a gdy jej nie ma — domyślne. Klub edytuje treść pod własną metodykę;
 * edycja tworzy NOWĄ wersję, poprzednie zostają (jak w mapping_profiles).
 *
 * Hasła są przypięte do POJĘĆ KANONICZNYCH (`concept`), nie do nazw tagów —
 * słownik tagów zmienia się między eksportami, pojęcia nie. `slug` jest
 * trwałym identyfikatorem hasła w adresach i odsyłaczach z raportu.
 *
 * PHP niczego tu nie liczy — hasło OPISUJE wskaźnik, który policzył silnik
 * (CLAUDE.md §4). Wzory w treści są dokumentacją, nie kodem.
 */
final class IndexTerms
{
    /**
     * Zastrzeżenie o kalibracji modelu xG — wymagane przy każdej wartości
     * szacowanej. Jedna stała, trzy miejsca użycia (indeks, raport, dokument):
     * rozjazd treści między nimi robiłby z zastrzeżenia ozdobnik.
     */
    public const XG_ZASTRZEZENIE =
        'Wartość szacowana: gdy analityk nie poda xG, liczbę wyznacza model '
        . 'kalibrowany na danych z lig zachodnich (skuteczność ~10,8% strzałów), '
        . 'a nie na poziomie rozgrywkowym tej drużyny. Wartości należy czytać '
        . 'porównawczo (mecz do meczu, okres do okresu), nie bezwzględnie.';

    /**
     * Punkt wyjścia: hasła dla wskaźników, które silnik już liczy.
     * Kolejność jest kolejnością prezentacji na liście i w raporcie.
     *
     * @var array<string, array<string,string|null>>
     */
    public const DOMYSLNE = [
        'xg' => [
            'concept'        => 'shot',
            'name'           => 'xG (gole oczekiwane)',
            'definition'     => 'Suma prawdopodobieństw, że poszczególne strzały zakończą się golem. '
                . 'Mierzy jakość wypracowanych sytuacji, a nie skuteczność ich wykończenia.',
            'formula'        => 'xG meczu = suma xG wszystkich strzałów drużyny',
            'example'        => 'Trzy strzały: 0,81 + 0,09 + 0,14 = 1,04 xG.',
            'interpretation' => 'Drużyna z 1,0 xG wypracowała sytuacje „na jednego gola". Wynik 0:1 przy '
                . 'przewadze xG oznacza problem ze skutecznością albo przypadek — nie z grą.',
            'source'         => 'Wartość od analityka (liczba w komentarzu taga strzału), '
                . 'np. „X 0,81". W przyszłości uzupełniana modelem.',
            'estimated_note' => self::XG_ZASTRZEZENIE,
        ],
        'xg-na-strzal' => [
            'concept'        => 'shot',
            'name'           => 'xG na strzał',
            'definition'     => 'Średnia wartość xG pojedynczego strzału — jakość przeciętnej sytuacji strzeleckiej.',
            'formula'        => 'xG na strzał = suma xG / liczba strzałów z policzonym xG',
            'example'        => '1,04 xG z 3 strzałów = 0,35 xG na strzał.',
            'interpretation' => 'Wysoka wartość = strzały z dobrych pozycji (SBZ, mały kąt obrony). '
                . 'Niska przy dużej liczbie strzałów = oddawanie strzałów z dystansu zamiast dogrania.',
            'source'         => 'Pochodna xG i liczby strzałów z eksportu.',
            'estimated_note' => self::XG_ZASTRZEZENIE,
        ],
        'celnosc' => [
            'concept'        => 'shot',
            'name'           => 'Celność strzałów',
            'definition'     => 'Udział strzałów celnych we wszystkich strzałach drużyny. '
                . 'Kształt znacznika w eksporcie koduje wynik: ● celny, ○ niecelny, ◆ zablokowany.',
            'formula'        => 'celność = strzały celne / (celne + niecelne + zablokowane)',
            'example'        => '4 celne z 10 strzałów = 40%.',
            'interpretation' => 'Niska celność przy wysokim xG na strzał wskazuje na problem techniczny '
                . 'w wykończeniu; niska celność przy niskim xG — na złą selekcję strzałów.',
            'source'         => 'Etykiety CELNY / NIECELNY / ZABLOKOWANY przy tagach strzałów.',
            'estimated_note' => null,
        ],
        'sbz' => [
            'concept'        => 'entry_sbz',
            'name'           => 'Zdobycia SBZ',
            'definition'     => 'Wejścia w strefę bezpośredniego zagrożenia (SBZ) — obszar, z którego pada '
                . 'większość goli. Liczone dla obu drużyn, z podziałem na zakończone strzałem i bez strzału.',
            'formula'        => 'SBZ = liczba zdarzeń „zdobycie SBZ"; skuteczność SBZ = wejścia ze strzałem / wszystkie wejścia',
            'example'        => '12 wejść w SBZ, 5 ze strzałem = 42% skuteczności strefy.',
            'interpretation' => 'Dużo wejść bez strzału = drużyna dociera pod pole karne, ale nie zamienia '
                . 'tego na sytuacje — problem ostatniego podania lub ustawienia w polu.',
            'source'         => 'Tagi zdobycia SBZ z eksportu, z wektorem wejścia gdy dostępny.',
            'estimated_note' => null,
        ],
        'iii-strefa' => [
            'concept'        => 'entry_third',
            'name'           => 'Wejścia w III strefę',
            'definition'     => 'Przeniesienia gry na ostatnią tercję boiska. Miara zdolności wyjścia '
                . 'spod pressingu i progresji piłki.',
            'formula'        => 'III strefa = liczba zdarzeń wejścia w III strefę',
            'example'        => '28 wejść w III strefę, w tym 12 w SBZ.',
            'interpretation' => 'Stosunek wejść w III strefę do wejść w SBZ pokazuje, gdzie zatrzymuje się '
                . 'budowanie akcji: dużo III strefy bez SBZ = gra zatrzymuje się przed polem karnym.',
            'source'         => 'Tagi III STREFY. Część eksportów nie niesie współrzędnych tych zdarzeń — '
                . 'wtedy sekcja pozycyjna raportu jest wyszarzona, a liczby pozostają.',
            'estimated_note' => null,
        ],
        'pojedynki' => [
            'concept'        => 'duel',
            'name'           => 'Pojedynki',
            'definition'     => 'Pojedynki indywidualne (1x1 w obronie i w ataku, pierwszy kontakt), '
                . 'z podziałem na wygrane i przegrane.',
            'formula'        => 'skuteczność pojedynków = wygrane / (wygrane + przegrane)',
            'example'        => '18 wygranych z 30 pojedynków = 60%.',
            'interpretation' => 'Poniżej 50% w pojedynkach defensywnych oznacza, że obrona wymaga asekuracji '
                . 'i ustawienia niżej; wysoka skuteczność 1x1 w ataku uzasadnia grę skrzydłami.',
            'source'         => 'Tagi pojedynków (1x1 OFF, 1x1 DEF., PIERWSZY KONTAKT) z etykietami WYGRANY/PRZEGRANY.',
            'estimated_note' => null,
        ],
        'straty' => [
            'concept'        => 'loss',
            'name'           => 'Straty',
            'definition'     => 'Utraty posiadania piłki, z rozróżnieniem strefy (własna/obca połowa), '
                . 'gdy eksport ją niesie.',
            'formula'        => 'straty = liczba zdarzeń straty; straty na własnej połowie liczone osobno',
            'example'        => '22 straty, w tym 8 na własnej połowie.',
            'interpretation' => 'Strata na własnej połowie jest groźniejsza — ocenę liczby strat zawsze '
                . 'zestawiać ze strefą i z reakcją po stracie.',
            'source'         => 'Tagi strat z eksportu, etykiety strefy (NASZA/OBCA POŁOWA).',
            'estimated_note' => null,
        ],
        'odbiory' => [
            'concept'        => 'recovery',
            'name'           => 'Odbiory',
            'definition'     => 'Odzyskania piłki. Wraz ze strefą odbioru pokazują wysokość i agresywność '
                . 'gry obronnej.',
            'formula'        => 'odbiory = liczba zdarzeń odbioru',
            'example'        => '31 odbiorów, w tym 11 na połowie przeciwnika.',
            'interpretation' => 'Odbiory wysoko na połowie rywala to najkrótsza droga do sytuacji — '
                . 'porównanie z liczbą wejść w SBZ po odbiorze mówi, czy drużyna to wykorzystuje.',
            'source'         => 'Tagi odbiorów z eksportu.',
            'estimated_note' => null,
        ],
        'reakcja-po-stracie' => [
            'concept'        => 'loss',
            'name'           => 'Reakcja po stracie',
            'definition'     => 'Czy po stracie nastąpiła natychmiastowa próba odzyskania piłki '
                . '(doskok, faul taktyczny, odbiór), czy jej brak.',
            'formula'        => 'reakcja = straty z reakcją / wszystkie straty z oznaczoną reakcją',
            'example'        => 'Reakcja przy 14 z 22 strat = 64%.',
            'interpretation' => 'Niski odsetek reakcji oznacza, że po stracie drużyna cofa się zamiast '
                . 'doskoczyć — przeciwnik dostaje czas na rozegranie. Wartość oceniać razem z założeniami: '
                . 'nie każda metodyka zakłada doskok po każdej stracie.',
            'source'         => 'Etykiety REAKCJA / BRAK REAKCJI przy tagach strat.',
            'estimated_note' => null,
        ],
        'pressing' => [
            'concept'        => 'press',
            'name'           => 'Pressing',
            'definition'     => 'Akcje pressingu z oceną skuteczności — czy doprowadziły do odzyskania '
                . 'piłki albo wymuszenia zagrania w tył/autu.',
            'formula'        => 'skuteczność pressingu = pressing skuteczny / wszystkie akcje pressingu',
            'example'        => '9 skutecznych z 15 akcji pressingu = 60%.',
            'interpretation' => 'Skuteczność poniżej założeń przy wysokiej liczbie prób oznacza pressing '
                . 'rozbijany rozegraniem — do korekty wyzwalacze doskoku, nie intensywność.',
            'source'         => 'Tagi pressingu z etykietami SKUTECZNY/NIESKUTECZNY.',
            'estimated_note' => null,
        ],
        'transformacja' => [
            'concept'        => 'transition',
            'name'           => 'Transformacja',
            'definition'     => 'Fazy przejściowe: z obrony do ataku po odbiorze i z ataku do obrony '
                . 'po stracie. Liczone jako osobne zdarzenia z kierunkiem.',
            'formula'        => 'transformacje = liczba zdarzeń transformacji, z podziałem na kierunek',
            'example'        => '17 transformacji do ataku, 21 do obrony.',
            'interpretation' => 'Przewaga transformacji do obrony oznacza, że mecz toczy się na warunkach '
                . 'przeciwnika. Transformacje do ataku zestawiać z wejściami w SBZ — mówią, czy kontra dociera.',
            'source'         => 'Tagi transformacji z eksportu.',
            'estimated_note' => null,
        ],
    ];

    // ---------------------------------------------------------------- odczyt

    /**
     * Hasło efektywne: najnowsza wersja klubowa, a bez niej — domyślne.
     *
     * @return array<string,mixed>|null null, gdy slug nie istnieje w ogóle
     */
    public static function find(?int $clubId, string $slug): ?array
    {
        $wlasne = $clubId !== null ? Db::one(
            'SELECT * FROM index_terms WHERE club_id = :club AND slug = :slug
              ORDER BY version DESC LIMIT 1',
            ['club' => $clubId, 'slug' => $slug]
        ) : null;

        if ($wlasne !== null) {
            $wlasne['is_default'] = false;
            return $wlasne;
        }

        $domyslne = self::DOMYSLNE[$slug] ?? null;
        if ($domyslne === null) {
            return null;
        }

        return $domyslne + [
            'slug'       => $slug,
            'club_id'    => null,
            'version'    => 0,
            'created_by' => null,
            'created_at' => null,
            'is_default' => true,
        ];
    }

    /**
     * Wszystkie hasła efektywne klubu, w kolejności prezentacji.
     *
     * @return list<array<string,mixed>>
     */
    public static function all(?int $clubId): array
    {
        $out = [];
        foreach (array_keys(self::DOMYSLNE) as $slug) {
            $haslo = self::find($clubId, $slug);
            if ($haslo !== null) {
                $out[] = $haslo;
            }
        }
        return $out;
    }

    /**
     * Wyszukiwanie po nazwie i treści hasła.
     *
     * Przeszukujemy hasła EFEKTYWNE w PHP, nie w SQL — lista ma jedenaście
     * pozycji, a wersja klubowa i domyślna muszą być przeszukane tą samą
     * logiką. SQL wchodzi dopiero, gdy haseł będą setki.
     *
     * @return list<array<string,mixed>>
     */
    public static function search(?int $clubId, string $query): array
    {
        $q = mb_strtolower(trim($query), 'UTF-8');
        if ($q === '') {
            return self::all($clubId);
        }

        return array_values(array_filter(self::all($clubId), static function (array $haslo) use ($q): bool {
            foreach (['name', 'definition', 'formula', 'example', 'interpretation', 'source'] as $pole) {
                if (str_contains(mb_strtolower((string) ($haslo[$pole] ?? ''), 'UTF-8'), $q)) {
                    return true;
                }
            }
            return false;
        }));
    }

    // ---------------------------------------------------------------- zapis

    /**
     * Nowa wersja klubowa hasła. Poprzednie zostają — historia metodyki
     * jest odpowiedzią na pytanie, dlaczego raport z marca opisywał
     * wskaźnik inaczej.
     *
     * Pojęcie kanoniczne NIE podlega edycji: hasło przypięte do pojęcia
     * nie może „odpłynąć" do innego, bo odsyłacze z archiwalnych raportów
     * zaczęłyby prowadzić do treści o czym innym.
     *
     * @param array<string,string|null> $pola name, definition, formula, example,
     *                                        interpretation, source, estimated_note
     */
    public static function saveVersion(int $clubId, string $slug, array $pola, int $userId): int
    {
        $biezace = self::find($clubId, $slug);
        if ($biezace === null) {
            throw new \RuntimeException("Hasło „{$slug}” nie istnieje.");
        }

        $nazwa = trim((string) ($pola['name'] ?? ''));
        $definicja = trim((string) ($pola['definition'] ?? ''));
        if ($nazwa === '' || $definicja === '') {
            throw new \RuntimeException('Hasło musi mieć nazwę i definicję.');
        }

        $tekst = static fn(string $klucz): ?string =>
            trim((string) ($pola[$klucz] ?? '')) !== '' ? trim((string) $pola[$klucz]) : null;

        Db::run(
            'INSERT INTO index_terms
                (club_id, slug, concept, name, definition, formula, example,
                 interpretation, source, estimated_note, version, created_by, created_at)
             VALUES (:club, :slug, :concept, :name, :def, :formula, :example,
                     :interp, :source, :estnote, :ver, :by, :now)',
            [
                'club'    => $clubId,
                'slug'    => $slug,
                'concept' => (string) $biezace['concept'],
                'name'    => mb_substr($nazwa, 0, 120),
                'def'     => $definicja,
                'formula' => $tekst('formula'),
                'example' => $tekst('example'),
                'interp'  => $tekst('interpretation'),
                'source'  => $tekst('source'),
                'estnote' => $tekst('estimated_note'),
                'ver'     => (int) $biezace['version'] + 1,
                'by'      => $userId,
                'now'     => Stats::now(),
            ]
        );

        $id = (int) Db::pdo()->lastInsertId();
        Audit::log('index.term_saved', $userId, 'club', $clubId, [
            'slug'    => $slug,
            'version' => (int) $biezace['version'] + 1,
        ]);
        return $id;
    }

    // ---------------------------------------------------------------- render

    /**
     * Odsyłacze dla renderu (`config.options.index_links`).
     *
     * Render NIE zna bazy ani słownika — dostaje gotową listę i adres bazowy
     * (docs/KONTRAKT_CLI.md). `estimated` = przy wskaźniku pojawi się w raporcie
     * adnotacja o wartości szacowanej.
     *
     * @return list<array{slug:string, label:string, estimated:bool}>
     */
    public static function linksFor(?int $clubId): array
    {
        $out = [];
        foreach (self::all($clubId) as $haslo) {
            $out[] = [
                'slug'      => (string) $haslo['slug'],
                'label'     => (string) $haslo['name'],
                'estimated' => ($haslo['estimated_note'] ?? null) !== null,
            ];
        }
        return $out;
    }
}
