<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Diff słownika importu wobec templatu klubu (Sesja 6 przebudowy).
 *
 * Import meczu nr 2+ w klubie z gotowym templatem ma być trzema minutami
 * roboty: tagi znane mapują się cicho, a operator ogląda WYŁĄCZNIE to, czego
 * templat jeszcze nie zna.
 *
 * TRZY ZBIORY, NIE DWA. Poza „znane" i „nowe" istnieje trzeci: pozycje
 * zignorowane na stałe (`club_ignored_tags`). Są znane systemowi i nie pytamy
 * o nie ponownie, ale NIE SĄ obsłużone — ich zdarzenia nie wchodzą do metryk.
 * Muszą więc być wyliczone w raporcie pokrycia, inaczej raport sugeruje
 * kompletność, której nie ma. Zero cichego wyrzucania danych.
 *
 * NIC TU NIE LICZY METRYK (CLAUDE.md §4). Liczby przychodzą z `meta.json`,
 * ta klasa je grupuje i porównuje nazwy.
 */
final class TemplateDiff
{
    /** Operator dodaje pozycję do templatu — powstanie z niej zmienna. */
    public const DODAJ = 'dodaj';

    /** Pomijamy w TYM imporcie. Nic nie zapisujemy, następnym razem zapytamy znowu. */
    public const POMIN = 'pomin';

    /** „Nie pytaj więcej" — wpis w `club_ignored_tags`, NIE podbija wersji templatu. */
    public const NA_STALE = 'na_stale';

    /**
     * Cofnięcie „na stałe" — pozycja wraca do pytania przy następnym imporcie.
     *
     * Dostępne WYŁĄCZNIE w trybie rewizji. Na zwykłym ekranie nowych tagów nie
     * ma czego cofać: pozycje zignorowane na stałe są tam z definicji pominięte,
     * bo operator poprosił, żeby o nie nie pytać.
     */
    public const COFNIJ = 'cofnij';

    /** Stany pozycji w trybie rewizji — do oznaczenia na ekranie. */
    public const STAN_NOWA     = 'nowa';
    public const STAN_POMINIETA = 'pominieta';
    public const STAN_NA_STALE = 'na_stale';

    /**
     * Porównanie słownika importu z templatem i listą zignorowanych.
     *
     * DOPASOWANIE PRZEZ RÓWNOŚĆ PEŁNEJ NAZWY, nigdy przez zawieranie —
     * pułapka 7 z CLAUDE.md: `substring` łapie `CELNY` wewnątrz `NIECELNY`.
     * Klucz zbioru niesie typ, bo tag i etykieta o tej samej nazwie to dwie
     * różne pozycje.
     *
     * @param array<string,mixed> $meta   zdekodowany `coverage_json`
     * @param array<string,mixed>|null $config  config aktualnego templatu
     * @param array{tag:array<string,bool>,label:array<string,bool>} $ignorowane
     * @return array{
     *     znane: list<array<string,mixed>>,
     *     nowe: list<array<string,mixed>>,
     *     ignorowane: list<array<string,mixed>>,
     *     ma_templat: bool
     * }
     */
    public static function policz(array $meta, ?array $config, array $ignorowane): array
    {
        $wTemplacie = [];
        foreach ((array) ($config['variables'] ?? []) as $z) {
            $typ = (string) ($z['source']['type'] ?? '');
            $raw = (string) ($z['source']['raw'] ?? '');
            if ($raw !== '') {
                $wTemplacie[self::klucz($typ, $raw)] = $z;
            }
        }

        $znane = [];
        $nowe = [];
        $pominiete = [];

        foreach (self::pozycjeSlownika($meta) as $poz) {
            $klucz = self::klucz($poz['type'], $poz['name']);

            if (isset($wTemplacie[$klucz])) {
                $poz['variable'] = $wTemplacie[$klucz];
                $znane[] = $poz;
                continue;
            }

            // „Nie pytaj więcej" ma być respektowane PRZED zaliczeniem do nowych,
            // inaczej operator dostawałby to samo pytanie przy każdym imporcie.
            if (!empty($ignorowane[$poz['type']][$poz['name']])) {
                $pominiete[] = $poz;
                continue;
            }

            $nowe[] = $poz;
        }

        return [
            'znane'      => $znane,
            'nowe'       => $nowe,
            'ignorowane' => $pominiete,
            'ma_templat' => $config !== null && ($config['variables'] ?? null) !== null,
        ];
    }

    /**
     * Pozycje do REWIZJI MAPOWANIA — wszystko, co nie weszło do templatu.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * CZYM REWIZJA RÓŻNI SIĘ OD ZWYKŁEGO EKRANU NOWYCH TAGÓW.
     *
     * Zwykły diff pyta o pozycje, o które jeszcze nie pytał, i świadomie
     * POMIJA te zignorowane na stałe — o to właśnie prosił operator, klikając
     * „nie pytaj więcej". Rewizja jest wejściem z drugiej strony: operator sam
     * przychodzi z ekranu pokrycia, bo zobaczył, że coś wypada z analizy,
     * i chce to zmienić. Wtedy ukrywanie przed nim własnych decyzji byłoby
     * dokładnie odwrotnością tego, po co przyszedł.
     *
     * Dlatego lista obejmuje OBA zbiory, każdy z widocznym stanem:
     *   - pominięte w tym imporcie (nic nie zapisano — wrócą przy następnym),
     *   - zignorowane na stałe (wpis w `club_ignored_tags`, do cofnięcia).
     *
     * `$diffDone` rozstrzyga, czy pozycja bez decyzji jest „nowa", czy
     * „pominięta": jedno i drugie wygląda w bazie tak samo — brakiem wpisu.
     * Odróżnia je wyłącznie to, czy operator widział już ten ekran.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @param array{nowe: list<array<string,mixed>>, ignorowane: list<array<string,mixed>>} $diff
     * @return list<array<string,mixed>>  pozycje z dodanym kluczem `stan`
     */
    public static function pozycjeRewizji(array $diff, bool $diffDone): array
    {
        $out = [];

        foreach ((array) ($diff['nowe'] ?? []) as $poz) {
            $poz['stan'] = $diffDone ? self::STAN_POMINIETA : self::STAN_NOWA;
            $out[] = $poz;
        }

        foreach ((array) ($diff['ignorowane'] ?? []) as $poz) {
            $poz['stan'] = self::STAN_NA_STALE;
            $out[] = $poz;
        }

        return $out;
    }

    /**
     * Pozycje słownika z `meta.dictionary` — tagi i etykiety w jednym kształcie.
     *
     * Źródłem jest PEŁNY słownik eksportu (silnik ≥ 0.10.0), nie `unmapped_tags`:
     * tag rozpoznany przez słownik domyślny silnika nie trafia do tamtej listy,
     * a dla templatu klubu jest równie nowy jak każdy inny.
     *
     * @return list<array{type:string, name:string, count:?int, samples:list<mixed>}>
     */
    public static function pozycjeSlownika(array $meta): array
    {
        $slownik = is_array($meta['dictionary'] ?? null) ? $meta['dictionary'] : [];
        $out = [];

        foreach (['tag' => 'tags', 'label' => 'labels'] as $typ => $klucz) {
            foreach ((array) ($slownik[$klucz] ?? []) as $poz) {
                $nazwa = (string) ($poz[$typ] ?? $poz['name'] ?? '');
                if ($nazwa === '') {
                    continue;
                }
                $out[] = [
                    'type'    => $typ,
                    'name'    => $nazwa,
                    'count'   => isset($poz['count']) ? (int) $poz['count'] : null,
                    'samples' => array_slice((array) ($poz['samples'] ?? []), 0, 3),
                ];
            }
        }

        return $out;
    }

    /**
     * Podpowiedzi dla nowych pozycji — ten sam kontrakt co w konfiguratorze.
     *
     * @param list<array<string,mixed>> $nowe
     * @return list<array<string,mixed>>
     */
    public static function zPodpowiedziami(array $nowe, Suggester $suggester, ?int $clubId): array
    {
        $znane = ($clubId !== null ? Mappings::decidedTags($clubId) : []) + Mappings::domyslneTagi();

        foreach ($nowe as &$poz) {
            $poz['suggestion'] = $suggester->suggest((string) $poz['type'], (string) $poz['name'], $znane);
        }
        unset($poz);

        return $nowe;
    }

    /**
     * Decyzje operatora → NOWA WERSJA TEMPLATU. Jedna na cały import.
     *
     * JEDNA WERSJA NA IMPORT, NIE JEDNA NA TAG — i to jest sedno. Wersja
     * templatu jest znacznikiem „raporty starsze niż to są nieaktualne"
     * (Sesja 7). Podbijanie jej przy każdym tagu z osobna oznaczałoby, że
     * import z pięcioma nowymi tagami unieważnia raporty klubu pięć razy,
     * a cztery z tych wersji nigdy nie istniały jako stan, w którym coś
     * wyrenderowano.
     *
     * Zwraca `null`, gdy nie ma czego dopisywać — wtedy NIE tworzymy wersji.
     * Pusta wersja różniłaby się od poprzedniej wyłącznie numerem, a każda
     * różnica numeru każe Sesji 7 przeliczyć wszystkie raporty klubu.
     *
     * @param array<string,mixed> $config     config aktualnego templatu
     * @param list<array<string,mixed>> $nowe pozycje z ekranu diffu
     * @param array<string,string> $decyzje   klucz pozycji => stała DODAJ/POMIN/NA_STALE
     * @param array<string,array<string,mixed>> $pola  klucz pozycji => canon/label/color/sections
     * @return array<string,mixed>|null nowy config albo null
     */
    public static function nowyConfig(array $config, array $nowe, array $decyzje, array $pola): array
    {
        $zmienne = array_values((array) ($config['variables'] ?? []));

        // Numerację ciągniemy dalej od najwyższego istniejącego `id`, zamiast
        // liczyć od count(): usunięcie zmiennej w konfiguratorze zostawia dziurę,
        // a powtórzony identyfikator złamałby walidację przy zapisie.
        $najwyzszy = 0;
        foreach ($zmienne as $z) {
            if (preg_match('/^v_(\d+)$/', (string) ($z['id'] ?? ''), $m) === 1) {
                $najwyzszy = max($najwyzszy, (int) $m[1]);
            }
        }

        foreach ($nowe as $poz) {
            $klucz = self::klucz((string) $poz['type'], (string) $poz['name']);
            if (($decyzje[$klucz] ?? '') !== self::DODAJ) {
                continue;
            }

            $wlasne = $pola[$klucz] ?? [];
            $typ = (string) $poz['type'];

            $canon = (string) ($wlasne['canon'] ?? '');
            $canon = in_array($canon, Configurator::dozwoloneCanon($typ), true) ? $canon : null;

            $sekcje = array_values(array_intersect(
                Configurator::SEKCJE,
                array_map('strval', (array) ($wlasne['sections'] ?? []))
            ));
            // Zmienna bez pojęcia kanonicznego wchodzi wyłącznie do sekcji
            // generycznych. Pilnuje tego także `Configurator::bledyConfigu()`
            // przy zapisie, ale odcinamy wcześniej, żeby nie odbijać operatora
            // komunikatem o czymś, czego ekran mu nie pozwolił wybrać.
            if ($canon === null) {
                $sekcje = array_values(array_intersect($sekcje, Configurator::SEKCJE_GENERYCZNE));
            }
            if ($sekcje === []) {
                $sekcje = Configurator::SEKCJE_GENERYCZNE;
            }

            $etykieta = trim((string) ($wlasne['display_label'] ?? ''));

            $zmienne[] = [
                'id'            => sprintf('v_%03d', ++$najwyzszy),
                'source'        => ['type' => $typ, 'raw' => (string) $poz['name']],
                'canon'         => $canon,
                'display_label' => $etykieta !== '' ? $etykieta : Configurator::etykietaZNazwy((string) $poz['name']),
                'color'         => View::color($wlasne['color'] ?? null, '#8899AA'),
                'sections'      => $sekcje,
                'visible'       => true,
            ];
        }

        return Configurator::config(
            $zmienne,
            array_values((array) ($config['sections_enabled'] ?? Configurator::SEKCJE)),
            array_values((array) ($config['team_us_rule']['markers'] ?? ['NASZA', 'MASZA']))
        );
    }

    /**
     * Czy decyzje w ogóle coś dopisują do templatu.
     *
     * @param array<string,string> $decyzje
     */
    public static function czyDopisuje(array $decyzje): bool
    {
        foreach ($decyzje as $decyzja) {
            if ($decyzja === self::DODAJ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Klucz pozycji: typ i nazwa razem.
     *
     * Tag `CELNY` i etykieta `CELNY` to dwie różne pozycje słownika i muszą
     * dać się rozróżnić — także w polach formularza, gdzie klucz trafia do
     * nazwy pola. Bajt zerowy jest tu bezpieczny, bo klucz nie idzie do HTML-a
     * wprost; formularz używa `kluczHtml()`.
     */
    public static function klucz(string $typ, string $raw): string
    {
        return $typ . "\0" . $raw;
    }

    /**
     * Klucz w postaci nadającej się na nazwę pola formularza.
     *
     * Nazwa tagu bywa dowolnym tekstem z eksportu — z polskimi znakami,
     * spacjami, cudzysłowami. Do `name="..."` idzie więc skrót, a nie nazwa;
     * odwzorowanie wraca przez `poKluczuHtml()`.
     */
    public static function kluczHtml(string $typ, string $raw): string
    {
        return substr(sha1(self::klucz($typ, $raw)), 0, 16);
    }

    /**
     * Odwzorowanie skrótów z formularza na pełne klucze pozycji.
     *
     * Budowane z listy pozycji, którą właśnie pokazaliśmy — NIGDY z żądania.
     * Dzięki temu decyzja może dotyczyć wyłącznie pozycji faktycznie obecnej
     * w tym imporcie, a podmiana pola w przeglądarce nie pozwala dopisać do
     * templatu czegoś, czego w eksporcie nie było.
     *
     * @param list<array<string,mixed>> $pozycje
     * @return array<string,string> skrót => pełny klucz
     */
    public static function mapaKluczy(array $pozycje): array
    {
        $out = [];
        foreach ($pozycje as $poz) {
            $typ = (string) $poz['type'];
            $raw = (string) $poz['name'];
            $out[self::kluczHtml($typ, $raw)] = self::klucz($typ, $raw);
        }
        return $out;
    }
}
