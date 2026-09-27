<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Porządki w templacie klubu po imporcie bez tarcia (0.16.3).
 * Sterowane przez `app/repairs/napraw_auto_etykiety.php` — tu wyłącznie logika.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO ZASTALIŚMY NA POGONI (templat v5, 78 zmiennych, odbiór 2026-09-27):
 *
 *   - „Inne" (v_030) i „INNE" (v_078) — jedna etykieta, dwie zmienne. Import
 *     porównywał po normalizacji, ekran różnic dosłownie (`NazwaZmiennej`).
 *   - `SBZ PODAJĄCY` (v_060) i `III STREFA PODAJĄCY/OTRZYMUJĄCY` (v_062) jako
 *     osobne zmienne, choć `aliasy.json` silnika liczy je jako `ZDOBYCIE SBZ`
 *     i `III STREFA`. Szablon i tak przepina zdarzenia — zmienne były pustymi
 *     wierszami.
 *   - „Zdobycie Sbz", „Iii Strefa", „1X1 Off" — wielkość tytułowa z domyślnej
 *     wartości formularza ekranu różnic (wersja 3, RĘCZNA), nie z automatu.
 *   - zestaw startowy skopiowany z innego klubu („Posiadanie Stal", „K1P"…).
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ALIAS PRZEMIANOWUJE ZDARZENIA. To zdanie zabrakło w pierwszej wersji tej klasy
 * i kosztowało raport 28: szablon zamienia tag zdarzenia na surową nazwę zmiennej,
 * której alias pasuje, a silnik mapuje kanon wyłącznie po surowej nazwie. Scalenie
 * nie zmienia liczb TYLKO wtedy, gdy wchłaniana zmienna jest martwa (jej nazwa
 * nie wystąpiła w eksporcie) albo jest aliasem silnika, którego szablon i tak
 * przemianowuje. `sprawdzCiaglosc()` pilnuje tego po każdej naprawie, a skrypt
 * przy błędzie nie zapisuje niczego.
 */
final class NaprawaTemplatu
{
    public const NOTKA = 'naprawa: napraw_auto_etykiety';

    /**
     * Wielkość tytułowa, jaką proponowało `Configurator::etykietaZNazwy` do 0.16.2.
     * Służy WYŁĄCZNIE rozpoznaniu etykiety, której nikt nie ustawił świadomie:
     * nazwa różna od tej propozycji to decyzja człowieka i jej nie ruszamy.
     */
    public static function tytulowaDo0162(string $raw): string
    {
        $czysta = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        return $czysta === '' ? $raw
            : mb_convert_case(mb_strtolower($czysta, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Zmienne, które weszły do templatu w wersji AUTOMATYCZNEJ: {typ|raw: true}.
     * Porównanie każdej wersji auto z poprzednią — notka jest na wersji, nie na zmiennej.
     *
     * @return array<string,bool>
     */
    public static function zWersjiAuto(int $clubId): array
    {
        $out = [];
        $poprzednie = [];
        $wiersze = Db::all(
            'SELECT version, config FROM club_report_templates WHERE club_id = :c ORDER BY version',
            ['c' => $clubId]
        );
        foreach ($wiersze as $w) {
            $config = ReportTemplates::decodeConfig($w['config']);
            $teraz = [];
            foreach ((array) ($config['variables'] ?? []) as $z) {
                $teraz[self::id($z)] = true;
            }
            if (ReportTemplates::isAuto($config)) {
                foreach (array_diff_key($teraz, $poprzednie) as $k => $_) {
                    $out[$k] = true;
                }
            }
            $poprzednie = $teraz;
        }
        return $out;
    }

    /**
     * Katalog tagów klubu: {typ: {DOSŁOWNA nazwa: true}}. `null`, gdy pusty.
     *
     * DOSŁOWNIE, NIE PO NORMALIZACJI — i to jest sedno regresji z v6 Pogoni.
     * Szablon przemianowuje zdarzenie na surową nazwę zmiennej, której alias
     * pasuje (`ALIAS[e.tag]`), a silnik mapuje kanon wyłącznie po surowej nazwie.
     * „Strzał" i „STRZAŁ" są dla nich DWIEMA nazwami; żywa jest ta, która stoi
     * w eksporcie.
     *
     * @return array{tag:array<string,bool>,label:array<string,bool>}|null
     */
    public static function katalog(int $clubId): ?array
    {
        $out = [Suggester::TAG => [], Suggester::ETYKIETA => []];
        $ile = 0;
        foreach (Db::all('SELECT kind, name FROM tag_catalog WHERE club_id = :c', ['c' => $clubId]) as $w) {
            if (isset($out[(string) $w['kind']])) {
                $out[(string) $w['kind']][(string) $w['name']] = true;
                $ile++;
            }
        }
        return $ile > 0 ? $out : null;
    }

    /**
     * Czy surowa nazwa zmiennej wystąpiła w imporcie klubu (dosłownie).
     *
     * @param array<string,mixed> $z
     * @param array{tag:array<string,bool>,label:array<string,bool>}|null $katalog
     */
    public static function zywa(array $z, ?array $katalog): bool
    {
        return $katalog !== null && isset($katalog[self::typ($z)][self::raw($z)]);
    }

    /**
     * Zmienna-alias silnika → alias zmiennej głównej (v_060 → ZDOBYCIE SBZ).
     *
     * JEDYNE DOPUSZCZONE WCHŁONIĘCIE ŻYWEJ ZMIENNEJ. Szablon przemianowuje
     * `SBZ PODAJĄCY` na `ZDOBYCIE SBZ` tak czy inaczej (aliasy domyślne), więc
     * scalenie niczego nie przestawia — POD WARUNKIEM, że zmienna docelowa nosi
     * DOKŁADNIE nazwę główną z `aliasy.json`. „Zdobycie SBZ" jako cel
     * przemianowałoby zdarzenia na nazwę, której szablon nie liczy.
     *
     * @param list<array<string,mixed>> $zmienne
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    public static function scalAliasySilnika(array $zmienne): array
    {
        $aliasy = NazwaZmiennej::aliasySilnika();
        $glowne = [];
        foreach ($zmienne as $i => $z) {
            if (self::typ($z) === Suggester::TAG) {
                $glowne[self::raw($z)] ??= $i;
            }
        }

        $zmiany = [];
        foreach ($zmienne as $i => $z) {
            if (self::typ($z) !== Suggester::TAG) {
                continue;
            }
            $cel = $aliasy[NazwaZmiennej::klucz(self::raw($z))] ?? null;
            $j = $cel !== null ? ($glowne[$cel] ?? null) : null;
            if ($j === null || $j === $i) {
                continue;
            }
            $zmienne[$j] = self::wchlon($zmienne[$j], $z);
            $zmiany[] = sprintf('scalono %s „%s" → alias %s „%s" (alias silnika)',
                $z['id'] ?? '?', self::raw($z), $zmienne[$j]['id'] ?? '?', self::raw($zmienne[$j]));
            unset($zmienne[$i]);
        }
        return [array_values($zmienne), $zmiany];
    }

    /**
     * Duplikaty po `NazwaZmiennej::klucz` w obrębie typu.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * ŻYWA ZMIENNA NIGDY NIE STAJE SIĘ ALIASEM (regresja v6 Pogoni, raport 28).
     *
     * Do tej poprawki zostawała zmienna o niższym `id`. Na Pogoni był nią martwy
     * „Strzał" z zestawu startowego innego klubu, a żywy „STRZAŁ" został jego
     * aliasem: szablon przemianował wszystkie strzały na „Strzał", Przegląd
     * szukał „STRZAŁ" i pokazał 0:0, xG 0,00 i gole bez strzału po stronie
     * tenanta; silnik stracił kanon `shot`, bo mapuje go po surowej nazwie.
     *
     * Kolejność wyboru zmiennej docelowej: ŻYWA (nazwa w katalogu), potem
     * z KANONEM, potem najstarsza. Dwie żywe w grupie NIE są scalane — każda
     * scalona byłaby przemianowaniem zdarzeń, czyli zmianą liczb; to decyzja
     * dla człowieka i idzie do wykazu. Bez katalogu nie wiemy, która jest żywa,
     * więc nie scalamy niczego.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * Wchłaniana (martwa) oddaje docelowej nazwę jako alias, sekcje, a kanon
     * wtedy, gdy docelowa go nie ma.
     *
     * @param list<array<string,mixed>> $zmienne
     * @param array{tag:array<string,bool>,label:array<string,bool>}|null $katalog
     * @return array{0:list<array<string,mixed>>,1:list<string>,2:list<string>} zmienne, zmiany, do decyzji
     */
    public static function scalDuplikaty(array $zmienne, ?array $katalog): array
    {
        $grupy = [];
        foreach ($zmienne as $i => $z) {
            $grupy[self::typ($z) . '|' . NazwaZmiennej::klucz(self::raw($z))][] = $i;
        }

        $zmiany = [];
        $doDecyzji = [];
        foreach ($grupy as $indeksy) {
            if (count($indeksy) < 2) {
                continue;
            }
            $opis = implode(', ', array_map(
                static fn(int $i): string => ($zmienne[$i]['id'] ?? '?') . ' „' . self::raw($zmienne[$i]) . '"',
                $indeksy
            ));
            if ($katalog === null) {
                $doDecyzji[] = "duplikat {$opis} — katalog tagów pusty, nie wiadomo, która jest żywa";
                continue;
            }
            $zywe = array_values(array_filter($indeksy, static fn(int $i): bool => self::zywa($zmienne[$i], $katalog)));
            if (count($zywe) > 1) {
                $doDecyzji[] = "duplikat {$opis} — obie nazwy występują w eksportach; scalenie przemianowałoby zdarzenia";
                continue;
            }

            $kolejnosc = $indeksy;
            usort($kolejnosc, static function (int $a, int $b) use ($zmienne, $katalog): int {
                $ocena = static fn(int $i): array => [
                    self::zywa($zmienne[$i], $katalog) ? 0 : 1,
                    ($zmienne[$i]['canon'] ?? null) !== null ? 0 : 1,
                    $i,
                ];
                return $ocena($a) <=> $ocena($b);
            });
            $j = array_shift($kolejnosc);

            foreach ($kolejnosc as $i) {
                $zmienne[$j] = self::wchlon($zmienne[$j], $zmienne[$i]);
                $zmiany[] = sprintf('scalono %s „%s" → alias %s „%s" (ta sama nazwa%s)',
                    $zmienne[$i]['id'] ?? '?', self::raw($zmienne[$i]),
                    $zmienne[$j]['id'] ?? '?', self::raw($zmienne[$j]),
                    self::zywa($zmienne[$j], $katalog) ? ', docelowa żywa' : ', obie martwe');
                unset($zmienne[$i]);
            }
        }
        return [array_values($zmienne), $zmiany, $doDecyzji];
    }

    /**
     * Kontrola po naprawie. Lista błędów; niepusta = PRZERWIJ BEZ ZAPISU.
     *
     *  1. każdy kanon obecny przed naprawą jest obecny po niej,
     *  2. każda ŻYWA zmienna zostaje zmienną (nie aliasem) tego samego typu
     *     i z tym samym kanonem — wyjątek: alias silnika, którego nazwa główna
     *     stoi po naprawie jako zmienna (szablon przemianowuje go i tak).
     *
     * @param list<array<string,mixed>> $przed
     * @param list<array<string,mixed>> $po
     * @param array{tag:array<string,bool>,label:array<string,bool>}|null $katalog
     * @return list<string>
     */
    public static function sprawdzCiaglosc(array $przed, array $po, ?array $katalog): array
    {
        $bledy = [];
        $kanony = static fn(array $zz): array => array_values(array_unique(array_filter(
            array_map(static fn(array $z): string => (string) ($z['canon'] ?? ''), $zz)
        )));
        foreach (array_diff($kanony($przed), $kanony($po)) as $k) {
            $bledy[] = "kanon „{$k}\" zniknął z templatu";
        }

        $poNazwie = [];
        foreach ($po as $z) {
            $poNazwie[self::typ($z) . '|' . self::raw($z)] = $z;
        }
        $aliasy = NazwaZmiennej::aliasySilnika();
        foreach ($przed as $z) {
            if (!self::zywa($z, $katalog)) {
                continue;
            }
            $po1 = $poNazwie[self::typ($z) . '|' . self::raw($z)] ?? null;
            if ($po1 === null) {
                $glowna = self::typ($z) === Suggester::TAG ? ($aliasy[NazwaZmiennej::klucz(self::raw($z))] ?? null) : null;
                if ($glowna === null || !isset($poNazwie[Suggester::TAG . '|' . $glowna])) {
                    $bledy[] = sprintf('żywa zmienna %s „%s" przestała być zmienną', $z['id'] ?? '?', self::raw($z));
                }
                continue;
            }
            if (($z['canon'] ?? null) !== null && ($po1['canon'] ?? null) !== $z['canon']) {
                $bledy[] = sprintf('żywa zmienna „%s" straciła kanon „%s"', self::raw($z), (string) $z['canon']);
            }
        }
        return $bledy;
    }

    /**
     * Etykieta wyświetlana = surowa nazwa, gdy obecna jest NIEZMIENIONĄ
     * propozycją wielkości tytułowej. Domyślnie tylko zmienne z wersji auto.
     *
     * @param list<array<string,mixed>> $zmienne
     * @param array<string,bool> $zAuto z `zWersjiAuto()`
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    public static function poprawEtykiety(array $zmienne, array $zAuto, bool $takzeReczne): array
    {
        $zmiany = [];
        foreach ($zmienne as $i => $z) {
            if (!$takzeReczne && !isset($zAuto[self::id($z)])) {
                continue;
            }
            $raw = self::raw($z);
            $cel = Configurator::etykietaZNazwy($raw);
            $jest = (string) ($z['display_label'] ?? '');
            if ($jest !== $cel && $jest === self::tytulowaDo0162($raw)) {
                $zmienne[$i]['display_label'] = $cel;
                $zmiany[] = sprintf('etykieta %s: „%s" → „%s"', $z['id'] ?? '?', $jest, $cel);
            }
        }
        return [$zmienne, $zmiany];
    }

    /**
     * Zmienne, których żadna nazwa (surowa, aliasy, aliasy silnika) nie
     * wystąpiła w katalogu tagów klubu. `null`, gdy katalog jest pusty —
     * wtedy „martwe" byłyby wszystkie, a to nie jest wiedza, tylko brak danych.
     *
     * @param list<array<string,mixed>> $zmienne
     * @return list<int>|null indeksy
     */
    public static function martwe(int $clubId, array $zmienne): ?array
    {
        $katalog = self::katalog($clubId);
        if ($katalog === null) {
            return null;
        }

        // DOSŁOWNIE, jak w `katalog()`: „Strzał" nie ożywa dlatego, że w eksporcie
        // jest „STRZAŁ". Alias silnika ożywia zmienną główną (SBZ PODAJĄCY →
        // ZDOBYCIE SBZ), bo szablon przemianowuje jego zdarzenia właśnie na nią.
        $doGlownej = [];
        foreach (array_keys($katalog[Suggester::TAG]) as $nazwa) {
            $glowna = NazwaZmiennej::aliasySilnika()[NazwaZmiennej::klucz($nazwa)] ?? null;
            if ($glowna !== null) {
                $doGlownej[$glowna] = true;
            }
        }

        $out = [];
        foreach ($zmienne as $i => $z) {
            $typ = self::typ($z);
            $nazwy = array_merge([self::raw($z)], array_map('strval', (array) ($z['aliases'] ?? [])));
            $zywa = $typ === Suggester::TAG && isset($doGlownej[self::raw($z)]);
            foreach ($nazwy as $n) {
                $zywa = $zywa || isset($katalog[$typ][$n]);
            }
            if (!$zywa) {
                $out[] = $i;
            }
        }
        return $out;
    }

    /**
     * Config gotowy do zapisu — układ, progi i markery z bieżącej wersji,
     * jak w `AutoImport`. `null` + błędy, gdy walidacja go nie przyjmie.
     *
     * @param array<string,mixed> $config
     * @param list<array<string,mixed>> $zmienne
     * @return array{0:?array<string,mixed>,1:list<string>}
     */
    public static function nowyConfig(array $config, array $zmienne): array
    {
        $nowy = Configurator::config(
            $zmienne,
            array_values((array) ($config['sections_enabled'] ?? Configurator::SEKCJE)),
            array_values((array) ($config['team_us_rule']['markers'] ?? ['NASZA', 'MASZA'])),
            ReportLayout::zConfigu($config),
            (array) ($config['thresholds'] ?? [])
        );
        $bledy = Configurator::bledyConfigu($nowy);
        return [$bledy === [] ? $nowy : null, $bledy];
    }

    /**
     * Zmienna `$cel` przejmuje nazwę, aliasy i sekcje zmiennej `$z`.
     * Sekcje sumujemy: zmienna-alias mogła być na mapie (v_060), a po scaleniu
     * jej zdarzenia mają dalej trafiać do tych samych sekcji.
     *
     * @param array<string,mixed> $cel
     * @param array<string,mixed> $z
     * @return array<string,mixed>
     */
    private static function wchlon(array $cel, array $z): array
    {
        $nazwy = array_merge(
            array_map('strval', (array) ($cel['aliases'] ?? [])),
            [self::raw($z)],
            array_map('strval', (array) ($z['aliases'] ?? []))
        );
        $cel['aliases'] = array_values(array_unique(array_filter(
            $nazwy,
            static fn(string $n): bool => trim($n) !== '' && $n !== self::raw($cel)
        )));
        // Kanon przechodzi, gdy docelowa go nie ma — wchłaniana mogła być
        // jedyną zmienną tego pojęcia w templacie.
        if (($cel['canon'] ?? null) === null && ($z['canon'] ?? null) !== null) {
            $cel['canon'] = $z['canon'];
        }
        $cel['sections'] = array_values(array_unique(array_merge(
            array_map('strval', (array) ($cel['sections'] ?? [])),
            array_map('strval', (array) ($z['sections'] ?? []))
        )));
        return $cel;
    }

    /** @param array<string,mixed> $z */
    private static function typ(array $z): string
    {
        return (string) ($z['source']['type'] ?? Suggester::TAG);
    }

    /** @param array<string,mixed> $z */
    private static function raw(array $z): string
    {
        return (string) ($z['source']['raw'] ?? '');
    }

    /** @param mixed $z */
    private static function id($z): string
    {
        return is_array($z) ? self::typ($z) . '|' . self::raw($z) : '';
    }
}
