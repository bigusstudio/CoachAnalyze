<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Profil mapowań klubu: tagi z eksportu → pojęcia kanoniczne.
 *
 * PO CO TO ISTNIEJE: cztery eksporty od trzech klubów mają trzy różne słowniki,
 * a jeden klub dołożył dwa tagi między meczami. Bez kreatora każdy nowy klub
 * dostaje raport z pustymi sekcjami, a zmiana metodyki w trakcie sezonu po cichu
 * wycina zdarzenia z analizy — bez błędu, bez ostrzeżenia, po prostu mniejsze liczby.
 *
 * LISTA POJĘĆ JEST ZAMKNIĘTA (docs/MODEL_KANONICZNY.md). Operator przypisuje tag
 * do istniejącego pojęcia albo oznacza „nie analizuj". Nowego pojęcia nie tworzy:
 * rozrost tej listy oznacza, że model zaczyna kopiować format LiveTag zamiast go
 * tłumaczyć, a wtedy archiwum przestaje być porównywalne między sezonami.
 *
 * PHP NIE LICZY TU ŻADNEJ METRYKI PIŁKARSKIEJ (CLAUDE.md §4). Trzyma słownik
 * i pilnuje jego wersji; całe liczenie zostaje w silniku, który dostaje profil
 * w `config.json`.
 */
final class Mappings
{
    /**
     * Pojęcia bazowe — przepisane z `docs/MODEL_KANONICZNY.md`.
     *
     * Kolejność jak w dokumencie, żeby porównanie obu list było mechaniczne.
     * Zmiana tej listy WYMAGA zmiany dokumentu w tym samym commicie: rozjazd
     * między nimi znaczy, że jedno z dwóch kłamie, i nie wiadomo które.
     */
    public const POJECIA = [
        'shot', 'entry_sbz', 'entry_third', 'duel', 'loss', 'recovery',
        'press', 'transition', 'set_piece', 'foul', 'card', 'keeper_action',
    ];

    /**
     * Kwalifikatory — słownik silnika (`canon.DEFAULT_LABEL_RULES`).
     *
     * Etykiety mapujemy na kwalifikatory, nie na pojęcia: nowa etykieta
     * w eksporcie to uszczegółowienie zdarzenia, a nie nowy rodzaj zdarzenia.
     */
    public const KWALIFIKATORY = [
        'on_target', 'off_target', 'blocked', 'goal',
        'positional', 'counter', 'after_press', 'set_piece', 'possession',
        'with_shot', 'without_shot', 'shot_from_sbz', 'entry_sbz',
        'successful', 'unsuccessful', 'won', 'lost', 'reaction', 'no_reaction',
        'own_half', 'opp_half', 'second_contact',
        'first_contact', 'offensive', 'defensive', 'effective', 'ineffective',
        'loss', 'recovery', 'other',
    ];

    /** Wartość oznaczająca świadomą decyzję „to nas nie interesuje". */
    public const NIE_ANALIZUJ = '__ignoruj__';

    /** Wartość oznaczająca „żadne pojęcie nie pasuje, zgłaszam do przejrzenia". */
    public const ZGLOS = '__zglos__';

    // ---------------------------------------------------------------- odczyt

    /**
     * Bieżąca (najnowsza) wersja profilu klubu.
     *
     * @return array<string,mixed>|null
     */
    public static function current(?int $clubId): ?array
    {
        if ($clubId === null) {
            return null;
        }
        return Db::one(
            'SELECT * FROM mapping_profiles WHERE club_id = :id ORDER BY version DESC LIMIT 1',
            ['id' => $clubId]
        );
    }

    /**
     * Reguły bieżącego profilu w kształcie z `docs/MODEL_KANONICZNY.md`.
     *
     * @return array{version:int, rules:list<array<string,mixed>>, unmapped:list<string>}
     */
    public static function rules(?int $clubId): array
    {
        $profil = self::current($clubId);
        if ($profil === null) {
            return ['version' => 0, 'rules' => [], 'unmapped' => []];
        }

        $dane = json_decode((string) $profil['rules_json'], true);
        if (!is_array($dane)) {
            // Uszkodzony JSON traktujemy jak brak reguł, ale głośno: cichy powrót
            // do domyślnych zmieniłby liczby w raporcie bez śladu.
            error_log('mapowania: nieczytelny rules_json profilu ' . $profil['id']);
            $dane = [];
        }

        return [
            'version'  => (int) $profil['version'],
            'rules'    => array_values((array) ($dane['rules'] ?? [])),
            'unmapped' => array_values(array_map('strval', (array) ($dane['unmapped'] ?? []))),
        ];
    }

    /**
     * Profil w kształcie oczekiwanym przez silnik (`config.json`).
     *
     * @return array{version:int, rules:list<array<string,mixed>>}
     */
    public static function forEngine(?int $clubId): array
    {
        $profil = self::rules($clubId);
        return ['version' => $profil['version'], 'rules' => $profil['rules']];
    }

    /**
     * Tagi, o które kreator NIE zapyta ponownie.
     *
     * Obejmuje i te przypisane do pojęcia, i te oznaczone „nie analizuj" —
     * jedno i drugie jest decyzją człowieka i nie ma wracać przy każdym imporcie.
     *
     * @return array<string,string> tag => pojęcie albo NIE_ANALIZUJ
     */
    public static function decidedTags(?int $clubId): array
    {
        $out = [];
        foreach (self::rules($clubId)['rules'] as $rule) {
            $tag = (string) ($rule['match']['tag'] ?? '');
            if ($tag === '') {
                continue;
            }
            // `concept: null` to właśnie „nie analizuj": silnik zna wtedy tag
            // (więc nie zgłosi go jako nierozpoznanego), a zdarzenie trafia do
            // archiwum z pustym pojęciem — jest, ale nie wchodzi do metryk.
            $out[$tag] = $rule['concept'] === null ? self::NIE_ANALIZUJ : (string) $rule['concept'];
        }
        return $out;
    }

    /** @return array<string,string> etykieta => kwalifikator albo NIE_ANALIZUJ */
    public static function decidedLabels(?int $clubId): array
    {
        $out = [];
        foreach (self::rules($clubId)['rules'] as $rule) {
            $label = (string) ($rule['match']['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $out[$label] = $rule['qualifier'] === null
                ? self::NIE_ANALIZUJ
                : (string) $rule['qualifier'];
        }
        return $out;
    }

    // ---------------------------------------------------------------- porównanie

    /**
     * Tagi i etykiety z eksportu, o których profil klubu jeszcze nie wie.
     *
     * ODCZYT ODPORNY NA DWA KSZTAŁTY WEJŚCIA. Silnik zwraca dziś same nazwy
     * (`["AKCJA DEFENSYWNA", ...]`). Kształt wzbogacony
     * (`[{"tag": "...", "count": 7, "sample_labels": [...]}]`) niesie liczbę
     * wystąpień i etykiety towarzyszące — bez nich operator decyduje w ciemno,
     * bo „tag wystąpił 2 razy" i „tag wystąpił 140 razy" to zupełnie inne decyzje.
     *
     * Obsługujemy oba, żeby ekran działał już teraz i wzbogacił się sam,
     * gdy silnik zacznie podawać liczby. Szczegóły: docs/KONTRAKT_CLI.md.
     *
     * @param array<string,mixed> $meta wynik `inspect`
     * @return array{
     *     tags: list<array{name:string, count:?int, labels:list<string>}>,
     *     labels: list<array{name:string, count:?int}>
     * }
     */
    public static function unknown(array $meta, ?int $clubId): array
    {
        $znaneTagi     = self::decidedTags($clubId);
        $znaneEtykiety = self::decidedLabels($clubId);

        $tagi = [];
        foreach (self::normalizeList($meta['unmapped_tags'] ?? [], 'tag') as $poz) {
            if (isset($znaneTagi[$poz['name']])) {
                continue;
            }
            $tagi[] = $poz;
        }

        $etykiety = [];
        foreach (self::normalizeList($meta['unmapped_labels'] ?? [], 'label') as $poz) {
            if (isset($znaneEtykiety[$poz['name']])) {
                continue;
            }
            $etykiety[] = ['name' => $poz['name'], 'count' => $poz['count']];
        }

        return ['tags' => $tagi, 'labels' => $etykiety];
    }

    /**
     * Sprowadzenie obu kształtów do jednego.
     *
     * @return list<array{name:string, count:?int, labels:list<string>}>
     */
    private static function normalizeList(mixed $surowe, string $klucz): array
    {
        $out = [];
        foreach ((array) $surowe as $poz) {
            if (is_string($poz)) {
                // Kształt dzisiejszy: sama nazwa. `count: null` znaczy
                // „nie wiemy", a nie „zero" — i tak ma być pokazane.
                $out[] = ['name' => $poz, 'count' => null, 'labels' => []];
                continue;
            }
            if (!is_array($poz)) {
                continue;
            }
            $nazwa = (string) ($poz[$klucz] ?? $poz['name'] ?? '');
            if ($nazwa === '') {
                continue;
            }
            $out[] = [
                'name'   => $nazwa,
                'count'  => isset($poz['count']) ? (int) $poz['count'] : null,
                'labels' => array_values(array_map('strval', (array) ($poz['sample_labels'] ?? []))),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- podpowiedzi

    /**
     * Podpowiedź pojęcia dla nierozpoznanego tagu — SUGESTIA, NIE AUTOMAT.
     *
     * Zwraca propozycję z uzasadnieniem, a formularz przedstawia ją jako
     * wstępnie zaznaczoną opcję, którą człowiek zatwierdza. Automatyczne
     * przypisanie byłoby zgadywaniem pojęcia, a to zmienia liczby w raporcie —
     * dokładnie ta klasa błędu, dla której istnieje test złoty.
     *
     * Dwa źródła podobieństwa, w tej kolejności:
     *   1. bliska nazwa znanego tagu (`1x1 DEF.` przy `1x1 DEF`) — literówka,
     *      kropka, spacja; wtedy podpowiadamy pojęcie tamtego tagu,
     *   2. wspólny człon z nazwą pojęcia (`SBZ PODAJĄCY` przy `entry_sbz`).
     *
     * @param array<string,string> $znaneTagi tag => pojęcie
     * @return array{concept:?string, reason:?string, similar:?string}
     */
    public static function suggest(string $tag, array $znaneTagi): array
    {
        $kandydat = null;
        $najlepszy = 0.0;

        foreach ($znaneTagi as $znany => $pojecie) {
            if ($pojecie === self::NIE_ANALIZUJ) {
                continue;
            }
            $podobienstwo = self::similarity($tag, $znany);
            if ($podobienstwo > $najlepszy) {
                $najlepszy = $podobienstwo;
                $kandydat = ['tag' => $znany, 'concept' => $pojecie];
            }
        }

        // Próg dobrany tak, żeby łapać `1x1 DEF` ↔ `1x1 DEF.` i odrzucać
        // przypadkowe zbieżności. Niżej zaczynają się podpowiedzi, które
        // operator musi odklikiwać — a podpowiedź, której się nie ufa,
        // jest gorsza niż jej brak.
        if ($kandydat !== null && $najlepszy >= 0.82) {
            return [
                'concept' => $kandydat['concept'],
                'reason'  => 'podobne do „' . $kandydat['tag'] . '”',
                'similar' => $kandydat['tag'],
            ];
        }

        // Drugie źródło: człon nazwy pojęcia obecny w nazwie tagu.
        $poNazwie = self::conceptByKeyword($tag);
        if ($poNazwie !== null) {
            return [
                'concept' => $poNazwie['concept'],
                'reason'  => 'nazwa zawiera „' . $poNazwie['keyword'] . '”',
                'similar' => null,
            ];
        }

        return ['concept' => null, 'reason' => null, 'similar' => null];
    }

    /**
     * Podobieństwo dwóch nazw, 0..1.
     *
     * Porównujemy po sprowadzeniu do wspólnej postaci (bez wielkości liter,
     * bez znaków przestankowych, bez nadmiarowych spacji) — bo różnice właśnie
     * tam siedzą. Odległość edycyjna liczona na tym, co zostanie.
     */
    public static function similarity(string $a, string $b): float
    {
        $x = self::skrot($a);
        $y = self::skrot($b);

        if ($x === '' || $y === '') {
            return 0.0;
        }
        if ($x === $y) {
            return 1.0;
        }

        // `levenshtein` liczy na bajtach, a nazwy mają polskie znaki. Na czas
        // porównania zamieniamy je na odpowiedniki jednobajtowe — inaczej
        // „ą" liczy się jako dwie różnice zamiast jednej.
        $x = self::bezOgonkow($x);
        $y = self::bezOgonkow($y);

        $dystans = levenshtein($x, $y);
        $dluzszy = max(strlen($x), strlen($y));

        return $dluzszy === 0 ? 0.0 : 1.0 - ($dystans / $dluzszy);
    }

    /** Nazwa do porównania: bez wielkości liter, przestankowania i podwójnych spacji. */
    private static function skrot(string $value): string
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        $v = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $v) ?? $v;
        return trim(preg_replace('/\s+/u', ' ', $v) ?? $v);
    }

    private static function bezOgonkow(string $value): string
    {
        return strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);
    }

    /**
     * Człony nazw wiążące tag z pojęciem.
     *
     * Świadomie krótka i konserwatywna lista: podpowiedź ma trafiać, a nie
     * zgadywać. `SBZ` w nazwie tagu praktycznie zawsze znaczy strefę
     * bezpośredniego zagrożenia — to terminologia klienta, nie zbieg okoliczności.
     *
     * @return array{concept:string, keyword:string}|null
     */
    private static function conceptByKeyword(string $tag): ?array
    {
        $t = self::skrot($tag);

        $slowa = [
            'sbz'          => 'entry_sbz',
            'iii strefa'   => 'entry_third',
            'strzal'       => 'shot',
            'strzał'       => 'shot',
            'strata'       => 'loss',
            'odbior'       => 'recovery',
            'odbiór'       => 'recovery',
            '1x1'          => 'duel',
            'pojedynek'    => 'duel',
            'pressing'     => 'press',
            'press'        => 'press',
            'transformacj' => 'transition',
            'stały fragment' => 'set_piece',
            'sfg'          => 'set_piece',
            'faul'         => 'foul',
            'kartka'       => 'card',
            'bramkarz'     => 'keeper_action',
        ];

        foreach ($slowa as $slowo => $pojecie) {
            if (str_contains($t, self::bezOgonkow(self::skrot($slowo)))
                || str_contains(self::bezOgonkow($t), self::bezOgonkow(self::skrot($slowo)))) {
                return ['concept' => $pojecie, 'keyword' => $slowo];
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- zapis

    /**
     * Nowa wersja profilu. POPRZEDNIE ZOSTAJĄ NIETKNIĘTE.
     *
     * Nadpisanie wersji w miejscu skasowałoby odpowiedź na pytanie „czemu raport
     * z marca pokazywał inną liczbę" — a mapowanie decyduje o tym, które
     * zdarzenia w ogóle wchodzą do metryk, więc jest zmianą tej samej wagi
     * co zmiana kodu silnika (CLAUDE.md §7).
     *
     * @param array<string,string> $tagi     tag => pojęcie | NIE_ANALIZUJ
     * @param array<string,string> $etykiety etykieta => kwalifikator | NIE_ANALIZUJ
     * @return int identyfikator nowej wersji
     */
    public static function saveVersion(
        int $clubId,
        array $tagi,
        array $etykiety,
        int $userId,
        ?string $note = null
    ): int {
        $biezace = self::rules($clubId);

        // Zaczynamy od reguł, które już są: kreator dokłada decyzje, a nie
        // zastępuje profil. Inaczej każdy import kasowałby wcześniejsze ustalenia.
        $reguly = $biezace['rules'];
        $nieanalizowane = $biezace['unmapped'];

        foreach ($tagi as $tag => $wybor) {
            $tag = (string) $tag;
            if ($tag === '') {
                continue;
            }

            $reguly = array_values(array_filter(
                $reguly,
                static fn(array $r) => (string) ($r['match']['tag'] ?? '') !== $tag
            ));

            if ($wybor === self::NIE_ANALIZUJ) {
                // `concept: null` — silnik zna tag i przestaje go zgłaszać,
                // a zdarzenie trafia do archiwum bez pojęcia. Widoczne, nie zgubione.
                $reguly[] = ['match' => ['tag' => $tag], 'concept' => null];
                if (!in_array($tag, $nieanalizowane, true)) {
                    $nieanalizowane[] = $tag;
                }
                continue;
            }

            if (!in_array($wybor, self::POJECIA, true)) {
                throw new \RuntimeException("Nieznane pojęcie kanoniczne: {$wybor}");
            }

            $reguly[] = ['match' => ['tag' => $tag], 'concept' => $wybor];
            $nieanalizowane = array_values(array_filter(
                $nieanalizowane,
                static fn($n) => (string) $n !== $tag
            ));
        }

        foreach ($etykiety as $etykieta => $wybor) {
            $etykieta = (string) $etykieta;
            if ($etykieta === '') {
                continue;
            }

            $reguly = array_values(array_filter(
                $reguly,
                static fn(array $r) => (string) ($r['match']['label'] ?? '') !== $etykieta
            ));

            if ($wybor === self::NIE_ANALIZUJ) {
                $reguly[] = ['match' => ['label' => $etykieta], 'qualifier' => null];
                continue;
            }

            if (!in_array($wybor, self::KWALIFIKATORY, true)) {
                throw new \RuntimeException("Nieznany kwalifikator: {$wybor}");
            }

            $reguly[] = ['match' => ['label' => $etykieta], 'qualifier' => $wybor];
        }

        $nowaWersja = $biezace['version'] + 1;

        Db::run(
            'INSERT INTO mapping_profiles (club_id, version, rules_json, source, created_at, created_by, note)
             VALUES (:club, :ver, :rules, :src, :now, :by, :note)',
            [
                'club'  => $clubId,
                'ver'   => $nowaWersja,
                'rules' => json_encode(
                    ['rules' => array_values($reguly), 'unmapped' => array_values($nieanalizowane)],
                    JSON_UNESCAPED_UNICODE
                ),
                'src'   => 'manual',
                'now'   => Stats::now(),
                'by'    => $userId,
                'note'  => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
            ]
        );

        $id = (int) Db::pdo()->lastInsertId();
        Audit::log('mapping.saved', $userId, 'club', $clubId, [
            'version' => $nowaWersja,
            'tags'    => count($tagi),
            'labels'  => count($etykiety),
        ]);

        return $id;
    }

    /**
     * Tagi, które silnik zna Z DOMYŚLNEGO SŁOWNIKA (`canon.DEFAULT_TAG_RULES`).
     *
     * Potrzebne WYŁĄCZNIE do podpowiedzi: `1x1 DEF` ma zostać skojarzone
     * z istniejącym `1x1 DEF.`, także wtedy, gdy klub nie ma jeszcze żadnej
     * własnej reguły. Nie jest to kopia słownika do liczenia — liczy silnik.
     *
     * Rozjazd z listą w silniku psuje tylko trafność podpowiedzi, nie liczby
     * w raporcie. Mimo to trzymamy komentarz przy obu miejscach.
     *
     * @return array<string,string>
     */
    public static function domyslneTagi(): array
    {
        return [
            'STRZAŁ'           => 'shot',
            'ZDOBYCIE SBZ'     => 'entry_sbz',
            'III STREFA'       => 'entry_third',
            'STRATA'           => 'loss',
            'ODBIÓR'           => 'recovery',
            'PIERWSZY KONTAKT' => 'duel',
            '1x1 OFF'          => 'duel',
            '1x1 DEF.'         => 'duel',
            'SKUTECZNY'        => 'press',
            'NISKUTECZNY'      => 'press',
        ];
    }

    /**
     * Zgłoszenie brakującego pojęcia kanonicznego.
     *
     * Lista pojęć jest zamknięta, ale „nie ma pasującego" bywa prawdą i musi
     * mieć ujście inne niż cisza. Bez tego operator ma do wyboru: wcisnąć tag
     * w niepasujące pojęcie — co po cichu skrzywi liczby — albo zostawić go
     * nieanalizowanym i zapomnieć.
     *
     * Tag idzie TYMCZASOWO jako nieanalizowany: widoczny w raporcie pokrycia,
     * policzony wśród zdarzeń poza analizą, nie zgubiony.
     */
    public static function requestConcept(
        ?int $clubId,
        ?int $importId,
        string $tag,
        string $rationale,
        int $userId
    ): void {
        try {
            Db::run(
                'INSERT INTO concept_requests
                    (club_id, import_id, tag, rationale, requested_by, created_at)
                 VALUES (:club, :import, :tag, :why, :by, :now)',
                [
                    'club'   => $clubId,
                    'import' => $importId,
                    'tag'    => mb_substr($tag, 0, 190),
                    'why'    => trim($rationale) !== '' ? mb_substr(trim($rationale), 0, 1000) : null,
                    'by'     => $userId,
                    'now'    => Stats::now(),
                ]
            );
            Audit::log('mapping.concept_requested', $userId, 'club', $clubId, ['tag' => $tag]);
        } catch (\Throwable $e) {
            // Zgłoszenie jest dodatkiem do decyzji, a nie warunkiem jej zapisu.
            // Profil został już zapisany i nie wolno go teraz przewrócić.
            error_log('mapowania: nie udało się zapisać zgłoszenia pojęcia — ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public static function requestsFor(?int $clubId): array
    {
        if ($clubId === null) {
            return [];
        }
        return Db::all(
            'SELECT r.*, u.display_name AS autor
               FROM concept_requests r
               LEFT JOIN users u ON u.id = r.requested_by
              WHERE r.club_id = :id
              ORDER BY r.id DESC',
            ['id' => $clubId]
        );
    }

    /** Zgłoszenia do przejrzenia — wszystkie kluby. */
    public static function openRequests(): array
    {
        return Db::all(
            "SELECT r.*, c.name AS klub, u.display_name AS autor
               FROM concept_requests r
               LEFT JOIN clubs c ON c.id = r.club_id
               LEFT JOIN users u ON u.id = r.requested_by
              WHERE r.status = 'new'
              ORDER BY r.id DESC"
        );
    }

    /**
     * Historia wersji profilu — kto i kiedy zmienił mapowanie.
     *
     * @return list<array<string,mixed>>
     */
    public static function history(int $clubId): array
    {
        $wiersze = Db::all(
            'SELECT p.id, p.version, p.source, p.created_at, p.note,
                    u.display_name AS autor, p.rules_json
               FROM mapping_profiles p
               LEFT JOIN users u ON u.id = p.created_by
              WHERE p.club_id = :id
              ORDER BY p.version DESC',
            ['id' => $clubId]
        );

        foreach ($wiersze as &$w) {
            $dane = json_decode((string) $w['rules_json'], true);
            $w['liczba_regul'] = is_array($dane) ? count((array) ($dane['rules'] ?? [])) : 0;
            unset($w['rules_json']);
        }
        return $wiersze;
    }

    /**
     * Tagi oznaczone „nie analizuj" — do ustawień klubu, z możliwością zmiany decyzji.
     *
     * @return list<string>
     */
    public static function ignoredTags(int $clubId): array
    {
        $out = [];
        foreach (self::decidedTags($clubId) as $tag => $wybor) {
            if ($wybor === self::NIE_ANALIZUJ) {
                $out[] = $tag;
            }
        }
        sort($out);
        return $out;
    }

    /** Pojęcie przypisane tagowi — do podglądu w ustawieniach klubu. */
    public static function assignedTags(int $clubId): array
    {
        $out = [];
        foreach (self::decidedTags($clubId) as $tag => $wybor) {
            if ($wybor !== self::NIE_ANALIZUJ) {
                $out[$tag] = $wybor;
            }
        }
        ksort($out);
        return $out;
    }
}
