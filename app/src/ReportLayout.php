<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Układ raportu klubu — kolejność, szerokość i tytuły kafli (Sesja 5 pivotu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * DWIE RÓŻNE RZECZY, KTÓRE DO TEJ SESJI NAZYWAŁY SIĘ TAK SAMO.
 *
 *   `Configurator::SEKCJE`  — sekcje, do KTÓRYCH DA SIĘ PRZYPISAĆ ZMIENNĄ
 *                             („pokaż STRZAŁ na osi czasu").
 *   `ReportLayout::WIDGETY` — kafle, z których SKŁADA SIĘ RAPORT
 *                             („najpierw Przegląd, potem donuty obok okazji").
 *
 * Pierwsze pyta o zmienną, drugie o stronę. Pokrywają się tylko częściowo:
 * Przegląd, donuty, okazje, tabela zawodników i siatka ilości liczą z całego
 * eksportu, więc „przypisz do nich zmienną" nie miałoby czego włączyć.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Odwzorowanie rejestru z silnika (`coverage.ALL_SECTIONS`, `DOMYSLNE_SEKCJE`)
 * i z szablonu v21 (`WIDGETS`). Trzy listy tej samej rzeczy to ryzyko rozjazdu
 * i dlatego pilnuje go test: `app/tests/integracja/test_uklad.php`.
 */
final class ReportLayout
{
    /**
     * Kafle raportu w KOLEJNOŚCI PREZENTACJI szablonu.
     *
     * `domyslny` — czy kafelek wchodzi do układu, którego nikt nie ustawiał.
     * Kafle przeniesione z magazynu v2 (donuty, okazje, zawodnicy, siatka) są
     * DOSTĘPNE, ale nie domyślne: dokładają płótna do i tak długiego raportu
     * i mają wchodzić decyzją człowieka, a nie samym faktem istnienia.
     *
     * `wymaga_zawodnikow` — kafelek ma sens wyłącznie przy wypełnionej kolumnie
     * zawodnika (pułapka 4). Nie blokujemy go: pusty pokazuje POWÓD, a to jest
     * informacja, nie awaria. Ekran uprzedza o tym przy dodawaniu.
     *
     * @var array<string,array{domyslny:bool,wymaga_zawodnikow?:bool}>
     */
    public const WIDGETY = [
        'przeglad'  => ['domyslny' => true],
        'makro'     => ['domyslny' => true],
        'bilans'    => ['domyslny' => true],
        'mapy'      => ['domyslny' => true],
        'donuty'    => ['domyslny' => false],
        'okazje'    => ['domyslny' => false],
        'tl_sbz'    => ['domyslny' => true],
        'tl_iii'    => ['domyslny' => true],
        'tl_bilans' => ['domyslny' => true],
        'duels'     => ['domyslny' => true],
        'zawodnicy' => ['domyslny' => false, 'wymaga_zawodnikow' => true],
        'siatka'    => ['domyslny' => false],
        'noteam'    => ['domyslny' => true],
    ];

    /**
     * Szerokości kafla. Trzy, nie dowolny ułamek: każda dzieli sześciokolumnową
     * siatkę bez reszty, a mniej możliwości to mniej miejsc, w których da się
     * wpisać wartość bez sensu.
     */
    public const ROZMIARY = ['1', '1/2', '1/3'];
    public const ROZMIAR_DOMYSLNY = '1';

    /** Ile kafli wolno mieć w jednym układzie. Górny limit zdrowego rozsądku. */
    public const MAX_SEKCJI = 24;

    /** Klucz roboczego układu w sesji. Jak draft konfiguratora — per klub. */
    private const KLUCZ_DRAFT = 'uklad_draft';

    // ------------------------------------------------------------ stan domyślny

    /**
     * Układ dla templatu, który układu nie ma (schemat 1 albo świeży klub).
     *
     * SEKCJE WŁĄCZONE W TEMPLACIE + KAFLE DOMYŚLNE. Templat schematu 1 wymienia
     * wyłącznie sekcje sprzed sesji 4b, więc gdyby decydował sam, klub straciłby
     * Przegląd, którego dziś używa — brak na liście zapisanej wcześniej nie jest
     * niczyją decyzją. Ta sama reguła co `coverage.sekcje_z_templatu` w silniku.
     *
     * @param list<string> $sekcjeWlaczone
     * @return list<array{id:string,size:string,widgets:list<string>,title:string}>
     */
    public static function domyslny(array $sekcjeWlaczone = []): array
    {
        $wlaczone = array_flip(array_map('strval', $sekcjeWlaczone));
        $out = [];
        $nr = 0;

        foreach (self::WIDGETY as $widget => $opis) {
            if (!isset($wlaczone[$widget]) && !$opis['domyslny']) {
                continue;
            }
            $nr++;
            $out[] = [
                'id'      => 's' . $nr,
                'size'    => self::ROZMIAR_DOMYSLNY,
                'widgets' => [$widget],
                'title'   => '',
            ];
        }

        return $out;
    }

    /**
     * Układ z configu templatu; przy jego braku — domyślny.
     *
     * @param array<string,mixed> $config
     * @return list<array{id:string,size:string,widgets:list<string>,title:string}>
     */
    public static function zConfigu(array $config): array
    {
        $sekcje = $config['sections'] ?? null;
        if (!is_array($sekcje) || $sekcje === []) {
            return self::domyslny(array_map('strval', (array) ($config['sections_enabled'] ?? [])));
        }
        return self::normalizuj($sekcje);
    }

    /**
     * Wpisy sprowadzone do kształtu kontraktu. Pozycja bez znanego kafelka ZNIKA.
     *
     * Nie „naprawiamy" jej na pierwszy lepszy widget: układ z kafelkiem, którego
     * nikt nie wybierał, jest gorszy niż układ o jedną pozycję krótszy.
     *
     * @param array<int,mixed> $sekcje
     * @return list<array{id:string,size:string,widgets:list<string>,title:string}>
     */
    public static function normalizuj(array $sekcje): array
    {
        $out = [];
        $nr = 0;
        foreach ($sekcje as $wpis) {
            if (!is_array($wpis)) {
                continue;
            }
            $widgety = $wpis['widgets'] ?? [];
            if (is_string($widgety)) {
                $widgety = [$widgety];
            }
            $widgety = array_values(array_filter(
                array_map('strval', (array) $widgety),
                static fn(string $w): bool => isset(self::WIDGETY[$w])
            ));
            if ($widgety === []) {
                continue;
            }

            $rozmiar = (string) ($wpis['size'] ?? self::ROZMIAR_DOMYSLNY);
            $nr++;
            $out[] = [
                'id'      => 's' . $nr,
                'size'    => in_array($rozmiar, self::ROZMIARY, true) ? $rozmiar : self::ROZMIAR_DOMYSLNY,
                'widgets' => $widgety,
                'title'   => trim((string) ($wpis['title'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Kafle układu, bez powtórzeń, w kolejności wystąpienia.
     *
     * To jest lista, którą silnik czyta jako `sections_enabled` przy schemacie 2
     * — i dlatego NIE MA drugiego pola z tą samą treścią. Dwie listy mówiące
     * o tym samym rozjeżdżają się przy pierwszej edycji, która ruszy jedną.
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<string>
     */
    public static function widgety(array $sekcje): array
    {
        $out = [];
        foreach ($sekcje as $wpis) {
            foreach ((array) ($wpis['widgets'] ?? []) as $widget) {
                $widget = (string) $widget;
                if (isset(self::WIDGETY[$widget]) && !in_array($widget, $out, true)) {
                    $out[] = $widget;
                }
            }
        }
        return $out;
    }

    /**
     * Błędy układu jako KLUCZE komunikatów (tłumaczone w `pl.php`).
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<string>
     */
    public static function bledy(array $sekcje): array
    {
        $bledy = [];

        if ($sekcje === []) {
            return ['uklad.err.pusty'];
        }
        if (count($sekcje) > self::MAX_SEKCJI) {
            $bledy[] = 'uklad.err.za_duzo';
        }

        $widziane = [];
        foreach ($sekcje as $wpis) {
            $rozmiar = (string) ($wpis['size'] ?? '');
            if (!in_array($rozmiar, self::ROZMIARY, true)) {
                $bledy[] = 'uklad.err.rozmiar';
            }
            foreach ((array) ($wpis['widgets'] ?? []) as $widget) {
                $widget = (string) $widget;
                if (!isset(self::WIDGETY[$widget])) {
                    $bledy[] = 'uklad.err.nieznany_kafel';
                    continue;
                }
                // TEN SAM KAFELEK DWA RAZY to nie jest „dwie sztuki tego samego":
                // szablon ma po jednym elemencie `<section>` na kafelek, więc
                // drugie wystąpienie i tak nie miałoby czego pokazać.
                if (isset($widziane[$widget])) {
                    $bledy[] = 'uklad.err.powtorzony_kafel';
                }
                $widziane[$widget] = true;
            }
        }

        return array_values(array_unique($bledy));
    }

    // ------------------------------------------------------------ edycja bez JS

    /**
     * Przesunięcie pozycji o jedno miejsce. Poza zakresem — bez zmiany.
     *
     * Przyciski góra/dół zamiast przeciągania: panel ma jeden zatwierdzony plik
     * JavaScript i jest to wyjątek na chmurki powiadomień (CLAUDE.md §9).
     * Drag&drop znaczyłby drugi plik i osobne uzgodnienie.
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<array<string,mixed>>
     */
    public static function przesun(array $sekcje, int $indeks, int $kierunek): array
    {
        $cel = $indeks + ($kierunek < 0 ? -1 : 1);
        if (!isset($sekcje[$indeks], $sekcje[$cel])) {
            return $sekcje;
        }
        [$sekcje[$indeks], $sekcje[$cel]] = [$sekcje[$cel], $sekcje[$indeks]];
        return array_values($sekcje);
    }

    /**
     * @param list<array<string,mixed>> $sekcje
     * @return list<array<string,mixed>>
     */
    public static function usun(array $sekcje, int $indeks): array
    {
        unset($sekcje[$indeks]);
        return array_values($sekcje);
    }

    /**
     * Dopisanie kafelka na końcu. Kafelek już obecny w układzie NIE dubluje się.
     *
     * @param list<array<string,mixed>> $sekcje
     * @return list<array<string,mixed>>
     */
    public static function dodaj(array $sekcje, string $widget, string $rozmiar = self::ROZMIAR_DOMYSLNY): array
    {
        if (!isset(self::WIDGETY[$widget]) || in_array($widget, self::widgety($sekcje), true)) {
            return $sekcje;
        }
        $sekcje[] = [
            'id'      => 's' . (count($sekcje) + 1),
            'size'    => in_array($rozmiar, self::ROZMIARY, true) ? $rozmiar : self::ROZMIAR_DOMYSLNY,
            'widgets' => [$widget],
            'title'   => '',
        ];
        return self::normalizuj($sekcje);
    }

    /**
     * Rozmiary i tytuły z formularza, nałożone na istniejący układ.
     *
     * Z ŻĄDANIA PRZYJMUJEMY WYŁĄCZNIE TO, CO OPERATOR EDYTUJE — kolejność
     * i zestaw kafli biorą się ze stanu roboczego. Inaczej podmiana pola
     * ukrytego pozwoliłaby wstawić kafelek, którego na ekranie nie było.
     * Ta sama zasada, co w `configuratorVariablesFromPost`.
     *
     * @param list<array<string,mixed>> $sekcje
     * @param array<int|string,mixed>   $rozmiary
     * @param array<int|string,mixed>   $tytuly
     * @return list<array<string,mixed>>
     */
    public static function zFormularza(array $sekcje, array $rozmiary, array $tytuly): array
    {
        foreach ($sekcje as $i => $wpis) {
            $rozmiar = (string) ($rozmiary[$i] ?? '');
            if (in_array($rozmiar, self::ROZMIARY, true)) {
                $sekcje[$i]['size'] = $rozmiar;
            }
            if (array_key_exists($i, $tytuly)) {
                // Pusty tytuł ZOSTAWIA nagłówek szablonu, a nie kasuje go —
                // patrz `render._z_tytulem`.
                $sekcje[$i]['title'] = trim((string) $tytuly[$i]);
            }
        }
        return array_values($sekcje);
    }

    // ------------------------------------------------------------ stan roboczy

    /** @return list<array<string,mixed>>|null */
    public static function draft(int $clubId): ?array
    {
        $stan = Session::get(self::KLUCZ_DRAFT);
        if (!is_array($stan) || (int) ($stan['club_id'] ?? 0) !== $clubId) {
            return null;
        }
        return is_array($stan['sections'] ?? null) ? $stan['sections'] : null;
    }

    /** @param list<array<string,mixed>> $sekcje */
    public static function saveDraft(int $clubId, array $sekcje): void
    {
        Session::set(self::KLUCZ_DRAFT, ['club_id' => $clubId, 'sections' => $sekcje]);
    }

    public static function clearDraft(): void
    {
        Session::set(self::KLUCZ_DRAFT, null);
    }
}
