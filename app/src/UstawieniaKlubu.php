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
     * Kafle do pokazania (ukryte). W5: „Inne zdarzenia" jak każdy kafel —
     * pokazuje WSZYSTKIE tagi pliku, więc nigdy nie jest pusta, a o jej
     * obecności w raporcie decyduje wyłącznie Układ (zasada nadrzędna).
     * `$saNierozpoznane` zostaje w sygnaturze dla zgodności wywołań.
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
     * „Wliczane" Słownika klubu (golden layout W4): BEZ ZMIENNYCH MARTWYCH,
     * z liczbą wystąpień w sezonie, malejąco po tej liczbie.
     *
     * Martwa = żadna nazwa zmiennej nie wystąpiła w żadnym imporcie klubu
     * (`NaprawaTemplatu::martwe`, ta sama definicja co skrypt). Na Pogoni było
     * ich 31 („Posiadanie Stal", „Wiązownica", P2/PK/K1P…) — zestaw startowy
     * z cudzego klubu, który przykrywał 20 prawdziwych tagów. Widać je tylko
     * w Zaawansowanych [op], z przyciskiem „Usuń martwe". Pusty katalog tagów
     * to BRAK WIEDZY, nie „wszystkie martwe" — wtedy nie ukrywamy niczego.
     *
     * Formularz Słownika niesie wyłącznie pokazane indeksy, a
     * `zastosujSlownik()` nie rusza zmiennych, których indeksu nie dostał —
     * ukrycie martwych niczego im nie kasuje.
     *
     * Liczba wystąpień: zdarzenia meczów klubu w sezonie (`events`) pod surową
     * nazwą zmiennej albo jej aliasem; zmienna-etykieta liczy zdarzenia z tą
     * etykietą. Bez sezonu — wszystkie mecze klubu.
     *
     * @param array<string,mixed> $config
     * @return list<array<string,mixed>> jak `wliczane()` + `sezon` (int)
     */
    public static function wliczaneSlownika(int $clubId, array $config, ?int $seasonId): array
    {
        $zmienne = array_values((array) ($config['variables'] ?? []));
        $martwe = array_flip(NaprawaTemplatu::martwe($clubId, $zmienne) ?? []);

        $warunek = $seasonId !== null ? ' AND m.season_id = :sid' : '';
        $tagi = [];
        $etykiety = [];
        foreach (Db::all(
            'SELECT e.tag_name, e.labels_json FROM events e
               JOIN matches m ON m.id = e.match_id
              WHERE m.club_id = :club' . $warunek,
            ['club' => $clubId] + ($seasonId !== null ? ['sid' => $seasonId] : [])
        ) as $e) {
            $tag = (string) $e['tag_name'];
            $tagi[$tag] = ($tagi[$tag] ?? 0) + 1;
            foreach ((array) json_decode((string) ($e['labels_json'] ?? ''), true) as $l) {
                if (is_string($l)) {
                    $etykiety[$l] = ($etykiety[$l] ?? 0) + 1;
                }
            }
        }

        $out = [];
        foreach (self::wliczane($config) as $w) {
            if (isset($martwe[$w['i']])) {
                continue;
            }
            $zrodlo = $w['typ'] === Suggester::TAG ? $tagi : $etykiety;
            $w['sezon'] = 0;
            foreach (array_unique(array_merge([$w['raw']], $w['aliases'])) as $n) {
                $w['sezon'] += $zrodlo[$n] ?? 0;
            }
            $out[] = $w;
        }

        usort($out, static fn(array $a, array $b): int
            => [$b['sezon'], $a['raw']] <=> [$a['sezon'], $b['raw']]);
        return $out;
    }

    /**
     * Sekcje budowane z pojęć wbudowanych szablonu — kopia
     * `render.SEKCJE_ZNACZENIOWE`. Zmienna bez kanonu, niebędąca tagiem
     * wbudowanym, daje tam „–" (przypadek z W1).
     */
    public const SEKCJE_ZNACZENIOWE = ['tl_sbz', 'tl_iii', 'duels'];

    public const POMINIETY = 'pominiety';
    public const BEZ_ZNACZENIA = 'bez_znaczenia';

    /**
     * NIEROZPOZNANE (W5) = zmienne bez kanonu w sekcji znaczeniowej (oś SBZ,
     *   oś III strefy, pojedynki), niebędące tagiem wbudowanym, z wystąpieniami
     *   w katalogu klubu — tam raport pokazuje „–". Stan „pominięty" zniknął.
     *
     * NOWE TAGI TU NIE TRAFIAJĄ: import dopisuje je do templatu sam (sesja 8),
     * a raport mówi o nich banerem informacyjnym. Ta sama definicja co baner
     * ostrzegawczy w silniku (`render.bez_znaczenia`).
     *
     * @param array<string,mixed> $config
     * @return list<array<string,mixed>>
     */
    public static function nierozpoznane(int $clubId, array $config): array
    {
        $katalog = [];
        foreach (TagCatalog::forClub($clubId) as $w) {
            if ((string) $w['kind'] === Suggester::TAG) {
                $katalog[(string) $w['name']] = $w;
            }
        }
        $wbud = self::zAliasamiSilnika(self::wbudowane());
        $indeks = Configurator::indeksNazw($config);
        $wiersz = static fn(string $n, array $extra) => $extra + [
            'name'    => $n,
            'events'  => (int) ($katalog[$n]['seen_events'] ?? 0),
            'matches' => (int) ($katalog[$n]['seen_matches'] ?? 0),
            'color'   => isset($katalog[$n]['color']) ? (string) $katalog[$n]['color'] : null,
        ];

        /*
         * W5: „POMINIĘTY" NIE JEST JUŻ STANEM TAGU. `club_ignored_tags` wycisza
         * wyłącznie pytania w panelu — tag jest w raporcie (tabela makro), więc
         * nie ma czego „wliczać". Nierozpoznane = wyłącznie zmienne bez kanonu
         * w sekcjach, które go wymagają.
         */
        $out = [];

        foreach (array_values((array) ($config['variables'] ?? [])) as $i => $z) {
            if (!is_array($z) || (string) ($z['source']['type'] ?? Suggester::TAG) !== Suggester::TAG
                || ($z['canon'] ?? null) !== null) {
                continue;
            }
            $nazwy = array_merge([(string) ($z['source']['raw'] ?? '')], array_map('strval', (array) ($z['aliases'] ?? [])));
            if (array_intersect($nazwy, $wbud) !== [] || array_intersect($nazwy, array_keys($katalog)) === []) {
                continue;
            }
            $sekcje = array_values(array_intersect(self::SEKCJE_ZNACZENIOWE, (array) ($z['sections'] ?? [])));
            if ($sekcje !== []) {
                $out[] = $wiersz($nazwy[0], ['powod' => self::BEZ_ZNACZENIA, 'sekcje' => $sekcje, 'i' => $i]);
            }
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
     * Nazwy + aliasy silnika prowadzące do którejś z nich — jak `_z_aliasami_silnika`.
     *
     * @param list<string> $nazwy
     * @return list<string>
     */
    private static function zAliasamiSilnika(array $nazwy): array
    {
        $dane = json_decode((string) @file_get_contents(NazwaZmiennej::plikAliasow()), true);
        $out = $nazwy;
        foreach ($nazwy as $n) {
            foreach (is_array($dane[$n] ?? null) ? $dane[$n] : [] as $a) {
                $out[] = trim((string) $a);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Zmienne po zapisie Słownika klubu.
     *
     * Z FORMULARZA PRZYJMUJEMY WYŁĄCZNIE etykietę i sekcje istniejących zmiennych
     * oraz decyzje dla pozycji z listy nierozpoznanych — surowej nazwy, typu,
     * barwy i kanonu tu nie zmienia nikt (to [op], Zaawansowane). Nazwa spoza
     * listy jest ignorowana: podmiana pola nie wstawi do templatu tagu, którego
     * na ekranie nie było.
     *
     * Decyzje:
     *   pominięty      — 'nowa' (nowa zmienna) | 'z:<i>' (kontynuacja zmiennej i),
     *   bez znaczenia  — 'bilans' (zdejmij z sekcji znaczeniowych)
     *                    | 'z:<j>' (to ta sama seria co zmienna j — scal).
     *
     * @param list<array<string,mixed>> $zmienne     `variables` bieżącej wersji
     * @param list<array<string,mixed>> $nierozpoznane z `nierozpoznane()`
     * @param array<int|string,mixed>   $etykiety    [indeks => etykieta]
     * @param array<int|string,mixed>   $sekcje      [indeks => [sekcja, …]]
     * @param array<int|string,mixed>   $wlicz       [nr => decyzja]
     * @param array<int|string,mixed>   $nazwyWlicz  [nr => surowa nazwa]
     * @param array<int|string,mixed>   $etykietyNowych [nr => etykieta]
     * @param list<string>              $barwyKlubu
     * @return array{0:list<array<string,mixed>>, 1:list<string>} zmienne, nazwy do zdjęcia z „pominiętych"
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

        $poNazwie = [];
        foreach ($nierozpoznane as $n) {
            $poNazwie[(string) $n['name']] = $n;
        }

        $nr = 0;
        foreach ($zmienne as $z) {
            if (preg_match('/^v_(\d+)$/', (string) ($z['id'] ?? ''), $m) === 1) {
                $nr = max($nr, (int) $m[1]);
            }
        }

        $kolejka = 0;
        $doUsuniecia = [];
        $odpomin = [];
        foreach ($wlicz as $k => $decyzja) {
            $decyzja = (string) $decyzja;
            $nazwa = (string) ($nazwyWlicz[$k] ?? '');
            $poz = $poNazwie[$nazwa] ?? null;
            if ($decyzja === '' || $poz === null) {
                continue;
            }
            $cel = preg_match('/^z:(\d+)$/', $decyzja, $m) === 1 && isset($zmienne[(int) $m[1]]) ? (int) $m[1] : null;

            if ($poz['powod'] === self::BEZ_ZNACZENIA) {
                $i = (int) $poz['i'];
                if (!isset($zmienne[$i])) {
                    continue;
                }
                if ($decyzja === 'bilans') {
                    $zmienne[$i]['sections'] = array_values(array_diff(
                        (array) ($zmienne[$i]['sections'] ?? []), self::SEKCJE_ZNACZENIOWE
                    ));
                } elseif ($cel !== null && $cel !== $i) {
                    // Ta sama seria pod inną nazwą: nazwy zmiennej i przechodzą
                    // do aliasów zmiennej docelowej, a zmienna i znika.
                    $aliasy = array_merge(
                        array_map('strval', (array) ($zmienne[$cel]['aliases'] ?? [])),
                        [(string) ($zmienne[$i]['source']['raw'] ?? '')],
                        array_map('strval', (array) ($zmienne[$i]['aliases'] ?? []))
                    );
                    $zmienne[$cel]['aliases'] = array_values(array_unique(array_filter($aliasy)));
                    $doUsuniecia[$i] = true;
                }
                continue;
            }

            // Pominięty: wchodzi do analizy — jako kontynuacja albo nowa zmienna.
            if ($cel !== null) {
                $aliasy = array_map('strval', (array) ($zmienne[$cel]['aliases'] ?? []));
                $aliasy[] = $nazwa;
                $zmienne[$cel]['aliases'] = array_values(array_unique($aliasy));
                $odpomin[] = $nazwa;
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
                'color'         => Configurator::barwaZapasowa($barwyKlubu, $kolejka++, $poz['color'] ?? null),
                'sections'      => Configurator::SEKCJE_GENERYCZNE,
                'visible'       => true,
                'aliases'       => [],
            ];
            $odpomin[] = $nazwa;
        }

        foreach (array_keys($doUsuniecia) as $i) {
            unset($zmienne[$i]);
        }
        return [array_values($zmienne), $odpomin];
    }
}
