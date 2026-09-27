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
 * ŻADNA OPERACJA NIE ZMIENIA LICZB. Scalenie dopisuje nazwę jako alias zmiennej,
 * która zostaje — zdarzenia liczą się pod nią tak, jak liczyły się dotąd przez
 * alias silnika albo wcale (etykiety nie wchodzą do `VARS` szablonu). Usunięcie
 * dotyczy wyłącznie zmiennych, których nazwa nie wystąpiła w żadnym eksporcie.
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
     * Zmienna-alias silnika → alias zmiennej głównej (v_060 → ZDOBYCIE SBZ).
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
                $glowne[NazwaZmiennej::klucz(self::raw($z))] ??= $i;
            }
        }

        $zmiany = [];
        foreach ($zmienne as $i => $z) {
            if (self::typ($z) !== Suggester::TAG) {
                continue;
            }
            $cel = $aliasy[NazwaZmiennej::klucz(self::raw($z))] ?? null;
            $j = $cel !== null ? ($glowne[NazwaZmiennej::klucz($cel)] ?? null) : null;
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
     * Duplikaty po `NazwaZmiennej::klucz` w obrębie typu. Zostaje PIERWSZA
     * (najstarsza) zmienna, bo na nią wskazują raporty i ustawienia; kolejne
     * dopisują się do niej jako aliasy.
     *
     * @param list<array<string,mixed>> $zmienne
     * @return array{0:list<array<string,mixed>>,1:list<string>}
     */
    public static function scalDuplikaty(array $zmienne): array
    {
        $pierwsza = [];
        $zmiany = [];
        foreach ($zmienne as $i => $z) {
            $k = self::typ($z) . '|' . NazwaZmiennej::klucz(self::raw($z));
            if (!isset($pierwsza[$k])) {
                $pierwsza[$k] = $i;
                continue;
            }
            $j = $pierwsza[$k];
            $zmienne[$j] = self::wchlon($zmienne[$j], $z);
            $zmiany[] = sprintf('scalono %s „%s" → alias %s „%s" (ta sama nazwa)',
                $z['id'] ?? '?', self::raw($z), $zmienne[$j]['id'] ?? '?', self::raw($zmienne[$j]));
            unset($zmienne[$i]);
        }
        return [array_values($zmienne), $zmiany];
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
        $widziane = [];
        foreach (Db::all('SELECT kind, name FROM tag_catalog WHERE club_id = :c', ['c' => $clubId]) as $w) {
            $widziane[(string) $w['kind'] . '|' . NazwaZmiennej::klucz((string) $w['name'])] = true;
        }
        if ($widziane === []) {
            return null;
        }

        $doGlownej = [];
        foreach (NazwaZmiennej::aliasySilnika() as $kluczAliasu => $glowna) {
            $doGlownej[NazwaZmiennej::klucz($glowna)][] = $kluczAliasu;
        }

        $out = [];
        foreach ($zmienne as $i => $z) {
            $typ = self::typ($z);
            $klucze = array_map(
                [NazwaZmiennej::class, 'klucz'],
                array_merge([self::raw($z)], array_map('strval', (array) ($z['aliases'] ?? [])))
            );
            if ($typ === Suggester::TAG) {
                foreach ($klucze as $k) {
                    $klucze = array_merge($klucze, $doGlownej[$k] ?? []);
                }
            }
            $zywa = false;
            foreach ($klucze as $k) {
                $zywa = $zywa || isset($widziane[$typ . '|' . $k]);
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
