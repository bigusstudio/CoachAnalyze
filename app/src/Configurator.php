<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Konfigurator raportu klubu — stan roboczy i budowa templatu.
 *
 * Warstwa modelu dla Sesji 3 i 4 przebudowy: składa słownik z importu
 * założycielskiego, dokłada podpowiedzi, trzyma decyzje operatora do czasu
 * zapisu i waliduje `config` przed oddaniem go `ReportTemplates`.
 *
 * NIE LICZY ŻADNEJ METRYKI PIŁKARSKIEJ (CLAUDE.md §4). Liczby przychodzą
 * z `meta.json`; tutaj są wyłącznie przepisywane i grupowane.
 */
final class Configurator
{
    /**
     * Sekcje raportu — identyfikatory MUSZĄ zgadzać się z `ALL_SECTIONS`
     * w `engine/coachanalyze/coverage.py`.
     *
     * UWAGA NA PODKREŚLENIA. Specyfikacja przebudowy podawała `tl-sbz`
     * z myślnikiem; silnik zna wyłącznie `tl_sbz`. Zapisanie templatu
     * z myślnikami przeszłoby walidację po naszej stronie i wywróciłoby się
     * dopiero przy renderze w Sesji 5, komunikatem „Sekcja nieznana silnikowi".
     */
    public const SEKCJE = ['bilans', 'mapy', 'tl_sbz', 'tl_iii', 'tl_bilans', 'duels', 'noteam'];

    /**
     * Sekcje dostępne dla zmiennej BEZ pojęcia kanonicznego.
     *
     * Zmienna niestandardowa ma tylko nazwę i liczbę wystąpień — nie wiadomo
     * o niej nic poza tym, że się zdarzyła. Wolno ją policzyć w bilansie
     * i pokazać jako pas na osi czasu. Wszystko inne wymaga semantyki:
     * mapa potrzebuje wiedzieć, że to strzał albo wejście w SBZ, oś SBZ
     * potrzebuje wiedzieć, że zdarzenie SBZ dotyczy.
     *
     * Bez tego ograniczenia zmienna trafiałaby do mapy jako punkt bez
     * znaczenia — czyli raport pokazywałby coś, czego nie umie wyjaśnić.
     */
    public const SEKCJE_GENERYCZNE = ['bilans', 'tl_bilans'];

    /** Klucz stanu roboczego w sesji. Draft jest PER KLUB. */
    private const KLUCZ_DRAFT = 'konfigurator_draft';

    // ------------------------------------------------------------ stan roboczy

    /**
     * Stan roboczy konfiguratora dla klubu.
     *
     * SIEDZI W SESJI, NIE W BAZIE — i to jest decyzja, nie skrót. Wymaganie
     * brzmi „draft ma przeżyć odświeżenie strony", a sesja to spełnia.
     * Tabela wymagałaby migracji, którą trzeba uruchomić ręcznie na produkcji,
     * i dokładałaby stan do sprzątania (porzucone drafty sprzed miesięcy).
     * Sesja znika razem z wylogowaniem, co przy pracy trwającej kwadrans
     * jest zachowaniem oczekiwanym, a nie utratą danych.
     *
     * Gdyby draft miał kiedyś przeżywać wylogowanie albo być kontynuowany
     * na innym urządzeniu — wtedy tabela, świadomie i z migracją.
     *
     * @return array<string,mixed>|null
     */
    public static function draft(int $clubId): ?array
    {
        $wszystkie = Session::get(self::KLUCZ_DRAFT);
        if (!is_array($wszystkie)) {
            return null;
        }
        $draft = $wszystkie[(string) $clubId] ?? null;
        return is_array($draft) ? $draft : null;
    }

    /** @param array<string,mixed> $draft */
    public static function saveDraft(int $clubId, array $draft): void
    {
        $wszystkie = Session::get(self::KLUCZ_DRAFT);
        $wszystkie = is_array($wszystkie) ? $wszystkie : [];
        $wszystkie[(string) $clubId] = $draft;
        Session::set(self::KLUCZ_DRAFT, $wszystkie);
    }

    public static function clearDraft(int $clubId): void
    {
        $wszystkie = Session::get(self::KLUCZ_DRAFT);
        if (!is_array($wszystkie)) {
            return;
        }
        unset($wszystkie[(string) $clubId]);
        Session::set(self::KLUCZ_DRAFT, $wszystkie);
    }

    // ------------------------------------------------------------ słownik

    /**
     * Zmienne wstępne ze słownika importu, z podpowiedziami.
     *
     * Źródłem jest blok `dictionary` z `meta.json` — PEŁNY słownik eksportu,
     * a nie `unmapped_tags`. Powód w `docs/KONTRAKT_CLI.md`: przy imporcie
     * założycielskim tagi z domyślnego słownika silnika są rozpoznawane
     * i z listy nierozpoznanych znikają, więc konfigurator by ich nie pokazał.
     *
     * Podpowiedź jest PROPOZYCJĄ z poziomem pewności. Nic nie jest tu
     * rozstrzygane — zatwierdza człowiek w edytorze zmiennych.
     *
     * @param array<string,mixed> $meta   zdekodowany `coverage_json`
     * @param array<string,mixed> $paleta barwy z pliku projektu LiveTag
     * @return list<array<string,mixed>>
     */
    public static function zmienneZeSlownika(
        array $meta,
        Suggester $suggester,
        ?int $clubId = null,
        array $paleta = [],
        array $barwyKlubu = []
    ): array {
        $slownik = is_array($meta['dictionary'] ?? null) ? $meta['dictionary'] : [];
        $znane = ($clubId !== null ? Mappings::decidedTags($clubId) : []) + Mappings::domyslneTagi();

        $zmienne = [];
        $i = 0;

        foreach ([Suggester::TAG => 'tags', Suggester::ETYKIETA => 'labels'] as $typ => $klucz) {
            foreach ((array) ($slownik[$klucz] ?? []) as $poz) {
                $raw = (string) ($poz[$typ] ?? $poz['name'] ?? '');
                if ($raw === '') {
                    continue;
                }

                $podpowiedz = $suggester->suggest($typ, $raw, $znane);

                $zmienne[] = [
                    'id'            => sprintf('v_%03d', ++$i),
                    'source'        => ['type' => $typ, 'raw' => $raw],
                    'count'         => isset($poz['count']) ? (int) $poz['count'] : null,
                    'samples'       => array_slice((array) ($poz['samples'] ?? []), 0, 3),
                    'canon'         => $podpowiedz['canon'],
                    'confidence'    => $podpowiedz['confidence'],
                    'reason'        => $podpowiedz['reason'],
                    'display_label' => self::etykietaZNazwy($raw),
                    'color'         => self::barwa($typ, $raw, $paleta, $barwyKlubu, $i),
                    // Sekcje domyślne: same generyczne. Sekcje wymagające
                    // semantyki operator włącza świadomie, po zatwierdzeniu
                    // bindingu — inaczej propozycja przesądzałaby o kształcie
                    // raportu bez niczyjej decyzji.
                    'sections'      => self::SEKCJE_GENERYCZNE,
                    'visible'       => true,
                ];
            }
        }

        return $zmienne;
    }

    /**
     * Nazwa wyświetlana proponowana z nazwy surowej.
     *
     * Eksporty piszą wersalikami („STRZAŁ"), a raport czyta zarząd klubu.
     * Zamiana na „Strzał" jest propozycją do poprawienia, nie regułą —
     * dlatego siedzi tu, a nie w renderze.
     */
    public static function etykietaZNazwy(string $raw): string
    {
        $czysta = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
        if ($czysta === '') {
            return $raw;
        }
        return mb_convert_case(mb_strtolower($czysta, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Barwa zmiennej: paleta z pliku projektu, potem barwy klubu.
     *
     * Paleta LiveTag jest pierwsza, bo to barwy, które sztab widzi na tablicy
     * kodowej — raport w tych samych kolorach czyta się bez tłumaczenia.
     * Bez pliku projektu schodzimy na barwy klubu; nie wymyślamy palety.
     *
     * @param array<string,mixed> $paleta
     * @param list<string> $barwyKlubu
     */
    private static function barwa(string $typ, string $raw, array $paleta, array $barwyKlubu, int $nr): string
    {
        $grupa = $typ === Suggester::TAG ? 'tags' : 'labels';
        $zPalety = $paleta[$grupa][$raw] ?? null;
        if (is_string($zPalety) && preg_match('/^#[0-9A-Fa-f]{6}$/', $zPalety) === 1) {
            return strtoupper($zPalety);
        }

        $dostepne = array_values(array_filter(
            $barwyKlubu,
            static fn($c) => is_string($c) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c) === 1
        ));
        if ($dostepne !== []) {
            return strtoupper((string) $dostepne[$nr % count($dostepne)]);
        }

        return '#8899AA';
    }

    // ------------------------------------------------------------ walidacja

    /**
     * Twarda walidacja `config` PRZED zapisem templatu.
     *
     * WALIDACJA JEST TUTAJ, NIE W WIDOKU. Ekran chowa zablokowane sekcje, żeby
     * nie kusiły, ale o tym, co wolno zapisać, rozstrzyga to miejsce — pole
     * wyboru da się odblokować w przeglądarce, a żądanie wysłać z konsoli.
     * Templat z niepoprawną sekcją przeszedłby wtedy do bazy i wywrócił render
     * dopiero u klienta.
     *
     * @param array<string,mixed> $config
     * @return list<string> lista kluczy komunikatów; pusta = config poprawny
     */
    public static function bledyConfigu(array $config): array
    {
        $bledy = [];

        $zmienne = $config['variables'] ?? null;
        if (!is_array($zmienne) || $zmienne === []) {
            $bledy[] = 'conf.err.no_variables';
            return $bledy;   // Bez zmiennych reszta sprawdzeń nie ma o czym mówić.
        }

        $sekcjeWlaczone = array_values((array) ($config['sections_enabled'] ?? []));
        if ($sekcjeWlaczone === []) {
            $bledy[] = 'conf.err.no_sections';
        }
        foreach ($sekcjeWlaczone as $sekcja) {
            if (!in_array($sekcja, self::SEKCJE, true)) {
                $bledy[] = 'conf.err.unknown_section';
                break;
            }
        }

        $widzianeId = [];
        $widzianeZrodla = [];

        foreach ($zmienne as $z) {
            if (!is_array($z)) {
                $bledy[] = 'conf.err.variable_shape';
                continue;
            }

            $id = (string) ($z['id'] ?? '');
            if ($id === '' || isset($widzianeId[$id])) {
                $bledy[] = 'conf.err.duplicate_id';
            }
            $widzianeId[$id] = true;

            $typ = (string) ($z['source']['type'] ?? '');
            $raw = (string) ($z['source']['raw'] ?? '');
            if ($raw === '' || !in_array($typ, [Suggester::TAG, Suggester::ETYKIETA], true)) {
                $bledy[] = 'conf.err.variable_source';
            } else {
                // Ta sama pozycja słownika dwa razy znaczy dwie zmienne liczące
                // to samo — w bilansie zobaczylibyśmy podwojoną liczbę.
                $klucz = $typ . "\0" . $raw;
                if (isset($widzianeZrodla[$klucz])) {
                    $bledy[] = 'conf.err.duplicate_source';
                }
                $widzianeZrodla[$klucz] = true;
            }

            $canon = $z['canon'] ?? null;
            if ($canon !== null && !in_array($canon, self::dozwoloneCanon($typ), true)) {
                $bledy[] = 'conf.err.unknown_canon';
            }

            if (preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($z['color'] ?? '')) !== 1) {
                $bledy[] = 'conf.err.color';
            }

            if (trim((string) ($z['display_label'] ?? '')) === '') {
                $bledy[] = 'conf.err.label';
            }

            $sekcjeZmiennej = array_values((array) ($z['sections'] ?? []));
            foreach ($sekcjeZmiennej as $sekcja) {
                if (!in_array($sekcja, self::SEKCJE, true)) {
                    $bledy[] = 'conf.err.unknown_section';
                    continue;
                }
                // Sekcja zmiennej spoza sekcji włączonych w templacie byłaby
                // deklaracją bez skutku — raport i tak jej nie narysuje.
                if ($sekcjeWlaczone !== [] && !in_array($sekcja, $sekcjeWlaczone, true)) {
                    $bledy[] = 'conf.err.section_disabled';
                }
            }

            /*
             * SEDNO TWARDEJ ZASADY: bez pojęcia kanonicznego wolno wyłącznie
             * licznik w bilansie i pas na osi czasu.
             */
            if ($canon === null) {
                foreach ($sekcjeZmiennej as $sekcja) {
                    if (!in_array($sekcja, self::SEKCJE_GENERYCZNE, true)) {
                        $bledy[] = 'conf.err.canon_required';
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($bledy));
    }

    /**
     * Pojęcia dopuszczalne dla danego typu źródła.
     *
     * Tag mapuje się na POJĘCIE (rodzaj zdarzenia), etykieta na KWALIFIKATOR
     * (uszczegółowienie). Wymieszanie ich znaczyłoby, że etykieta „CELNY"
     * może być rodzajem zdarzenia — a wtedy liczby w bilansie przestają się
     * sumować do liczby zdarzeń.
     *
     * @return list<string>
     */
    public static function dozwoloneCanon(string $typ): array
    {
        return $typ === Suggester::ETYKIETA ? Mappings::KWALIFIKATORY : Mappings::POJECIA;
    }

    /**
     * `config` w kształcie z docs/PRZEBUDOWA_KLUB_SESJE.md (Sesja 4 pkt 4).
     *
     * Pola robocze konfiguratora (`count`, `samples`, `confidence`, `reason`)
     * NIE WCHODZĄ do templatu. To dane z JEDNEGO importu, a templat opisuje
     * raport klubu na stałe — liczba wystąpień z pierwszego meczu nie ma
     * w nim czego szukać i po roku wprowadzałaby w błąd.
     *
     * @param list<array<string,mixed>> $zmienne
     * @param list<string> $sekcje
     * @return array<string,mixed>
     */
    public static function config(array $zmienne, array $sekcje, array $markery = ['NASZA', 'MASZA']): array
    {
        $out = [];
        foreach ($zmienne as $z) {
            $out[] = [
                'id'            => (string) ($z['id'] ?? ''),
                'source'        => [
                    'type' => (string) ($z['source']['type'] ?? ''),
                    'raw'  => (string) ($z['source']['raw'] ?? ''),
                ],
                'canon'         => $z['canon'] ?? null,
                'display_label' => (string) ($z['display_label'] ?? ''),
                'color'         => (string) ($z['color'] ?? ''),
                'sections'      => array_values((array) ($z['sections'] ?? [])),
                'visible'       => !empty($z['visible']),
            ];
        }

        return [
            'schema_version'   => ReportTemplates::SCHEMA_VERSION,
            'team_us_rule'     => ['markers' => array_values($markery)],
            'sections_enabled' => array_values($sekcje),
            'variables'        => $out,
        ];
    }

    /**
     * Podsumowanie templatu do ekranu po zapisie.
     *
     * @param array<string,mixed> $config
     * @return array{variables:int, canon:int, custom:int, sections:int, hidden:int}
     */
    public static function podsumowanie(array $config): array
    {
        $zmienne = (array) ($config['variables'] ?? []);
        $kanoniczne = 0;
        $ukryte = 0;
        foreach ($zmienne as $z) {
            if (($z['canon'] ?? null) !== null) {
                $kanoniczne++;
            }
            if (empty($z['visible'])) {
                $ukryte++;
            }
        }

        return [
            'variables' => count($zmienne),
            'canon'     => $kanoniczne,
            'custom'    => count($zmienne) - $kanoniczne,
            'sections'  => count((array) ($config['sections_enabled'] ?? [])),
            'hidden'    => $ukryte,
        ];
    }
}
