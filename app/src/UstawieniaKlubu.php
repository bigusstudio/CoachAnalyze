<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * USTAWIENIA KLUBU — logika ekranu (golden layout W3).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * TRZY ZAKŁADKI, JEDNO ŹRÓDŁO PRAWDY: aktualna wersja templatu klubu.
 *
 *   Układ raportu  — kolejność i widoczność zakładek raportu,
 *   Słownik klubu  — które tagi eksportu wchodzą do raportu, pod jaką nazwą
 *                    i w jakich sekcjach,
 *   Zaawansowane   — [op]: historia wersji, zmienne martwe, typ/barwa/kanon.
 *
 * Każdy zapis to NOWA WERSJA templatu (append-only, jak wszędzie) i partia
 * przeliczeń raportów klubu w tle — z chmurką po zakończeniu (run_job.php).
 *
 * ZMIENNA = SUROWA NAZWA Z EKSPORTU. Klub zmienia wyłącznie etykietę i sekcje;
 * surowej nazwy nie rusza nikt, bo po niej szablon liczy (pułapka 7 i regresja
 * v6 Pogoni). Zapis słownika dzieje się TYLKO tutaj — nigdy przy imporcie.
 * ═══════════════════════════════════════════════════════════════════════════
 */
final class UstawieniaKlubu
{
    /** Zakładka raportu, która stoi zawsze pierwsza i zawsze jest widoczna. */
    public const PRZEGLAD = 'przeglad';

    /** Kafel „Inne zdarzenia" — ma sens wyłącznie przy nierozpoznanych tagach. */
    public const INNE = 'siatka';

    /**
     * Przegląd na początek; brakujący — dopisany. Reszta w kolejności klubu.
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<array<string,mixed>>
     */
    public static function przegladPierwszy(array $sekcje): array
    {
        $przeglad = null;
        $reszta = [];
        foreach ($sekcje as $wpis) {
            if (in_array(self::PRZEGLAD, (array) ($wpis['widgets'] ?? []), true) && $przeglad === null) {
                $przeglad = $wpis;
                continue;
            }
            $reszta[] = $wpis;
        }
        $przeglad ??= ['size' => ReportLayout::ROZMIAR_DOMYSLNY, 'widgets' => [self::PRZEGLAD], 'title' => ''];
        return ReportLayout::normalizuj(array_merge([$przeglad], $reszta));
    }

    /** Czy pozycję wolno przesunąć lub ukryć (Przegląd — nie). */
    public static function ruchoma(array $wpis): bool
    {
        return !in_array(self::PRZEGLAD, (array) ($wpis['widgets'] ?? []), true);
    }

    /**
     * Operacja na układzie z ekranu. Przegląd nie rusza się z miejsca: pozycja 1
     * nie jedzie w górę ani w dół, a pozycja 2 nie wskakuje przed nią.
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<array<string,mixed>>
     */
    public static function operacja(array $sekcje, string $akcja, string $widget = ''): array
    {
        $sekcje = self::przegladPierwszy($sekcje);
        if (preg_match('/^(gora|dol|ukryj):(\d+)$/', $akcja, $m) === 1) {
            $i = (int) $m[2];
            if (!isset($sekcje[$i]) || !self::ruchoma($sekcje[$i])) {
                return $sekcje;
            }
            $sekcje = match ($m[1]) {
                'gora'  => $i <= 1 ? $sekcje : ReportLayout::przesun($sekcje, $i, -1),
                'dol'   => ReportLayout::przesun($sekcje, $i, 1),
                'ukryj' => ReportLayout::usun($sekcje, $i),
            };
        } elseif ($akcja === 'pokaz') {
            $sekcje = ReportLayout::dodaj($sekcje, $widget);
        }
        return self::przegladPierwszy($sekcje);
    }

    /**
     * Kafle do pokazania (ukryte). „Inne zdarzenia" tylko przy nierozpoznanych
     * tagach — bez nich byłaby pustą zakładką.
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<string>
     */
    public static function ukryte(array $sekcje, bool $saNierozpoznane): array
    {
        $out = [];
        foreach (array_keys(ReportLayout::WIDGETY) as $w) {
            if (in_array($w, ReportLayout::widgety($sekcje), true)) {
                continue;
            }
            if ($w === self::INNE && !$saNierozpoznane) {
                continue;
            }
            $out[] = $w;
        }
        return $out;
    }

    /**
     * Wliczane: zmienne templatu z indeksem, surową nazwą i aliasami.
     *
     * @param array<string,mixed> $config
     * @return list<array<string,mixed>>
     */
    public static function wliczane(array $config): array
    {
        $out = [];
        foreach (array_values((array) ($config['variables'] ?? [])) as $i => $z) {
            if (!is_array($z)) {
                continue;
            }
            $out[] = [
                'i'        => $i,
                'typ'      => (string) ($z['source']['type'] ?? Suggester::TAG),
                'raw'      => (string) ($z['source']['raw'] ?? ''),
                'label'    => (string) ($z['display_label'] ?? ''),
                'sections' => array_values(array_map('strval', (array) ($z['sections'] ?? []))),
                'aliases'  => array_values(array_map('strval', (array) ($z['aliases'] ?? []))),
            ];
        }
        return $out;
    }

    /**
     * Nierozpoznane: tagi z katalogu klubu, których templat nie zna (ani jako
     * zmiennej, ani aliasu, ani aliasu silnika). Z liczbą zdarzeń i meczów —
     * od najczęstszego, jak baner w raporcie.
     *
     * @param array<string,mixed> $config
     * @return list<array{name:string,events:int,matches:int,color:?string}>
     */
    public static function nierozpoznane(int $clubId, array $config): array
    {
        $indeks = Configurator::indeksNazw($config);
        // Tagi wbudowane szablonu (i ich aliasy silnika) liczą się same — jak
        // `render.niewliczone_tagi`: Słownik i baner mają mówić to samo.
        $wbudowane = array_flip(self::wbudowane());
        $out = [];
        foreach (TagCatalog::forClub($clubId) as $w) {
            if ((string) $w['kind'] !== Suggester::TAG) {
                continue;
            }
            $nazwa = (string) $w['name'];
            if (Configurator::dopasuj(Suggester::TAG, $nazwa, $indeks) !== null) {
                continue;
            }
            $glowna = NazwaZmiennej::aliasySilnika()[NazwaZmiennej::klucz($nazwa)] ?? null;
            if (isset($wbudowane[$nazwa]) || ($glowna !== null && isset($wbudowane[$glowna]))) {
                continue;
            }
            $out[] = [
                'name'    => $nazwa,
                'events'  => (int) $w['seen_events'],
                'matches' => (int) $w['seen_matches'],
                'color'   => $w['color'] !== null ? (string) $w['color'] : null,
            ];
        }
        usort($out, static fn(array $a, array $b): int
            => [$b['events'], $a['name']] <=> [$a['events'], $b['name']]);
        return $out;
    }

    /**
     * Tagi, które szablon raportu liczy sam (klucze `VARS` szablonu v21).
     * Kopia z `render.wbudowane_tagi` — panel nie czyta plików silnika.
     *
     * @return list<string>
     */
    public static function wbudowane(): array
    {
        $dane = json_decode((string) @file_get_contents(__DIR__ . '/data/tagi_wbudowane.json'), true);
        return is_array($dane['tagi'] ?? null) ? array_values(array_map('strval', $dane['tagi'])) : [];
    }

    /**
     * Zmienne po zapisie Słownika klubu.
     *
     * Z FORMULARZA PRZYJMUJEMY WYŁĄCZNIE etykietę i sekcje istniejących zmiennych
     * oraz decyzję „wlicz" dla nazw z listy nierozpoznanych — surowej nazwy,
     * typu, barwy i kanonu tu nie zmienia nikt (to [op], Zaawansowane).
     * Nazwa spoza listy nierozpoznanych jest ignorowana: podmiana pola nie
     * wstawi do templatu tagu, którego na ekranie nie było.
     *
     * @param list<array<string,mixed>> $zmienne     `variables` bieżącej wersji
     * @param array<string,?string>     $nierozpoznane {nazwa: barwa z katalogu} z `nierozpoznane()`
     * @param array<int|string,mixed>   $etykiety    [indeks => etykieta]
     * @param array<int|string,mixed>   $sekcje      [indeks => [sekcja, …]]
     * @param array<int|string,mixed>   $wlicz       [nr => 'nowa' | 'z:<indeks>' | '']
     * @param array<int|string,mixed>   $nazwyWlicz  [nr => surowa nazwa]
     * @param array<int|string,mixed>   $etykietyNowych [nr => etykieta]
     * @param list<string>              $barwyKlubu
     * @return list<array<string,mixed>>
     */
    public static function zastosujSlownik(
        array $zmienne,
        array $nierozpoznane,
        array $etykiety,
        array $sekcje,
        array $wlicz,
        array $nazwyWlicz,
        array $etykietyNowych,
        array $barwyKlubu = []
    ): array {
        $zmienne = array_values($zmienne);
        foreach ($zmienne as $i => $z) {
            if (array_key_exists($i, $etykiety)) {
                $e = trim((string) $etykiety[$i]);
                $zmienne[$i]['display_label'] = $e !== '' ? mb_substr($e, 0, 80) : (string) ($z['source']['raw'] ?? '');
            }
            if (array_key_exists($i, $sekcje) || array_key_exists($i, $etykiety)) {
                $zmienne[$i]['sections'] = array_values(array_intersect(
                    Configurator::SEKCJE,
                    array_map('strval', (array) ($sekcje[$i] ?? []))
                ));
            }
        }

        $nr = 0;
        foreach ($zmienne as $z) {
            if (preg_match('/^v_(\d+)$/', (string) ($z['id'] ?? ''), $m) === 1) {
                $nr = max($nr, (int) $m[1]);
            }
        }

        $kolejka = 0;
        foreach ($wlicz as $k => $decyzja) {
            $decyzja = (string) $decyzja;
            $nazwa = (string) ($nazwyWlicz[$k] ?? '');
            if ($decyzja === '' || !array_key_exists($nazwa, $nierozpoznane)) {
                continue;
            }
            if (preg_match('/^z:(\d+)$/', $decyzja, $m) === 1 && isset($zmienne[(int) $m[1]])) {
                // Kontynuacja zmiennej X: ta sama seria, inna nazwa w eksporcie.
                $cel = (int) $m[1];
                $aliasy = array_map('strval', (array) ($zmienne[$cel]['aliases'] ?? []));
                $aliasy[] = $nazwa;
                $zmienne[$cel]['aliases'] = array_values(array_unique($aliasy));
                continue;
            }
            if ($decyzja !== 'nowa') {
                continue;
            }
            $e = trim((string) ($etykietyNowych[$k] ?? ''));
            $zmienne[] = [
                'id'            => sprintf('v_%03d', ++$nr),
                'source'        => ['type' => Suggester::TAG, 'raw' => $nazwa],
                'canon'         => null,
                'display_label' => $e !== '' ? mb_substr($e, 0, 80) : $nazwa,
                'color'         => Configurator::barwaZapasowa($barwyKlubu, $kolejka++, $nierozpoznane[$nazwa]),
                'sections'      => Configurator::SEKCJE_GENERYCZNE,
                'visible'       => true,
                'aliases'       => [],
            ];
        }
        return $zmienne;
    }
}
