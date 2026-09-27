<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Import bez tarcia — co dzieje się SAMO po inspekcji eksportu (Sesja 8 pivotu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ZASADA PIVOTU: ZMIENNA TO SUROWA NAZWA TAGU, A UŻYTKOWNIK NIE MUSI NICZEGO
 * KONFIGUROWAĆ, ŻEBY ZOBACZYĆ RAPORT.
 *
 * Do sesji 8 import nowego meczu zatrzymywał się dwa razy: na nowych tagach
 * („dodaj do templatu?") i na nieznanym rywalu („załóż klub?"). Obie odpowiedzi
 * brzmiały niemal zawsze tak samo, a ekran, który pyta o coś rozstrzygniętego
 * z góry, uczy klikać „dalej" bez czytania — i wtedy przestaje chronić także
 * wtedy, gdy naprawdę ma co powiedzieć.
 *
 * Odtąd jedno i drugie dzieje się samo, a operator dostaje INFORMACJĘ, co
 * powstało, i odsyłacz do poprawienia. Cofnięcie jest tańsze niż decyzja
 * podejmowana w ciemno przy każdym imporcie.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * NIC TU NIE LICZY METRYK (CLAUDE.md §4). Liczby przychodzą z `meta.json`;
 * ta klasa czyta nazwy, porównuje je i zapisuje decyzje.
 */
final class AutoImport
{
    /**
     * Barwy zapasowe dla klubów i zmiennych zakładanych automatycznie.
     *
     * KOLEJKA, NIE LOSOWANIE: dwa kluby założone tego samego dnia mają się
     * różnić na wykresie, a losowa barwa potrafi dwa razy z rzędu trafić w to
     * samo. Kolejność jest stała, więc wynik importu jest powtarzalny.
     *
     * Barwy dobrane pod ciemne tło raportu i wyraźnie różne od siebie także
     * w druku czarno-białym (różna jasność, nie tylko odcień).
     */
    public const BARWY = [
        '#2C6FE8', '#E8722C', '#189151', '#A55EEA', '#C1304A',
        '#F7B731', '#2D53AD', '#EB3B5A', '#4B2E74', '#686F7A',
    ];

    /**
     * Wszystko, co dzieje się samo po zakończonej inspekcji.
     *
     * Wołane z `run_job.php`, zaraz po `Imports::saveInspection()`. Kolejność
     * jest istotna: najpierw kluby (bo templat należy do klubu-tenanta), potem
     * zmienne.
     *
     * @return array{club:?array<string,mixed>, variables:list<string>, version:?int}
     */
    public static function poInspekcji(int $importId): array
    {
        $import = Imports::find($importId);
        if ($import === null) {
            return ['club' => null, 'variables' => [], 'version' => null];
        }

        $rywal = self::autoKluby((int) $import['match_id'], $import);
        [$nazwy, $wersja] = self::autoZmienne($importId, $import);

        return ['club' => $rywal, 'variables' => $nazwy, 'version' => $wersja];
    }

    // ══════════════════════════════════════════════════════════════════ kluby

    /**
     * Dopasowanie drużyn z eksportu do klubów; nierozpoznany rywal zakładany.
     *
     * DOPASOWANIE PRZEZ RÓWNOŚĆ PO NORMALIZACJI (`Clubs::normalize`: wielkość
     * liter, nadmiarowe spacje), NIGDY przez fragment. Pułapka 7 z CLAUDE.md
     * dotyczy etykiet, ale ta sama pomyłka kosztuje tu więcej: „Pogoń" wewnątrz
     * „Pogoń II" przypisałoby mecz rezerw do pierwszej drużyny i rozbiło
     * porównania sezonowe bez żadnego śladu.
     *
     * ═══════════════════════════════════════════════════════════════════════
     * KIEDY NIE ZAKŁADAMY NICZEGO — i dlaczego to jest ważniejsze od wygody.
     *
     * Gdy ŻADNA nazwa z eksportu nie trafiła w klub-tenanta, nie wiemy, która
     * drużyna jest „nasza". Założenie wtedy rywala z pierwszej lepszej nazwy
     * potrafi zrobić rywala z WŁASNEJ drużyny klubu — a to jest błąd, którego
     * nikt nie zauważy, dopóki nie spojrzy na raport z odwróconymi stronami.
     *
     * W tym jednym przypadku zostawiamy decyzję człowiekowi: ekran pokrycia
     * pokazuje wtedy „załóż klub" tak jak dotąd.
     * ═══════════════════════════════════════════════════════════════════════
     *
     * @param array<string,mixed> $import
     * @return array<string,mixed>|null klub założony w tym przebiegu albo null
     */
    public static function autoKluby(int $matchId, array $import): ?array
    {
        $mecz = Matches::find($matchId);
        if ($mecz === null) {
            return null;
        }

        $wykryte = Imports::detectedTeams($import);
        if ($wykryte === []) {
            return null;
        }

        $tenantId = $mecz['club_id'] !== null ? (int) $mecz['club_id'] : null;
        $dopasowane = [];
        $nieznane = [];
        $tenantWPliku = false;

        foreach ($wykryte as $nazwa) {
            $club = Clubs::matchByExportName((string) $nazwa);
            if ($club === null) {
                $nieznane[] = (string) $nazwa;
                continue;
            }
            Clubs::rememberAlias((int) $club['id'], (string) $nazwa);
            $dopasowane[] = $club;
            if ($tenantId !== null && (int) $club['id'] === $tenantId) {
                $tenantWPliku = true;
            }
        }

        $utworzony = null;

        /*
         * RYWALA ZAKŁADAMY, GDY WIEMY, KTÓRA DRUŻYNA JEST NASZA.
         *
         * Warunek jest spełniony, gdy tenant rozpoznał się w pliku albo gdy
         * eksport niesie tylko jedną nazwę (wtedy druga strona nie istnieje
         * jako nazwa i nie ma czego pomylić).
         */
        $wiemyKtoNasz = $tenantWPliku || count($wykryte) === 1;

        if ($wiemyKtoNasz) {
            foreach ($nieznane as $nazwa) {
                $utworzony = self::zalozRywala($nazwa, $mecz);
                if ($utworzony !== null) {
                    $dopasowane[] = $utworzony;
                }
            }
        }

        // Przypisanie stron zostawiamy `Imports::assignClubs()` — jednemu
        // miejscu, w którym rozstrzyga się, co jest `us`, a co `them`.
        // Drugie takie miejsce rozjechałoby się przy pierwszej poprawce.
        Imports::assignClubs($matchId, $wykryte);

        return $utworzony;
    }

    /**
     * Klub rywala z nazwy w eksporcie.
     *
     * `aliases_json` dostaje nazwę z pliku, więc następny mecz z tym rywalem
     * dopasuje się sam — także wtedy, gdy operator poprawi nazwę wyświetlaną
     * na „MKS Pogoń Lubaczów".
     *
     * @param array<string,mixed> $mecz
     * @return array<string,mixed>|null
     */
    public const ZNACZNIK_AUTO = 'auto_import';

    private static function zalozRywala(string $nazwa, array $mecz): ?array
    {
        $nazwa = trim($nazwa);
        if ($nazwa === '') {
            return null;
        }

        // Wyścig dwóch importów tego samego rywala: sprawdzamy jeszcze raz tuż
        // przed zapisem. Kolejka cron przetwarza zadania pojedynczo, więc
        // sytuacja nie występuje — ale klub-duplikat rozbiłby historię meczów
        // na dwa wiersze i naprawa wymagałaby ręcznego scalania.
        $istnieje = Clubs::matchByExportName($nazwa);
        if ($istnieje !== null) {
            return null;
        }

        $ownerId = $mecz['owner_id'] !== null ? (int) $mecz['owner_id'] : 0;
        $id = Clubs::create($ownerId, [
            'name'            => mb_substr($nazwa, 0, 160),
            'short_name'      => '',
            'color_primary'   => self::kolejnaBarwa(),
            'color_secondary' => '',
            'is_own_team'     => false,
            'aliases'         => $nazwa,
            /*
             * ŚLAD, ŻE KLUB POWSTAŁ SAM. Bez niego raport pokrycia nie umie
             * odróżnić klubu założonego przez import od założonego ręcznie —
             * a operator ma zobaczyć, że nazwa przyszła z pliku i warto ją
             * przejrzeć (skrót, herb, pełna nazwa).
             *
             * W `details`, bo to pole jest CELOWO otwarte na dane opisowe
             * i żadna liczba w raporcie od niego nie zależy. Kolumna znaczyłaby
             * migrację dla znacznika, który służy jednemu zdaniu na ekranie.
             */
            'details'         => [self::ZNACZNIK_AUTO => (string) ($mecz['id'] ?? '')],
        ]);

        Audit::log('club.auto', null, 'club', $id, [
            'name'     => $nazwa,
            'match_id' => (int) $mecz['id'],
        ]);

        return Clubs::find($id);
    }

    /**
     * Kolejna barwa z listy — po liczbie klubów, nie losowo.
     *
     * Przy więcej niż dziesięciu klubach barwy zaczynają się powtarzać i to
     * jest w porządku: dwa kluby tej samej barwy nigdy nie stoją obok siebie
     * na jednym wykresie, bo raport pokazuje zawsze dwie drużyny.
     */
    private static function kolejnaBarwa(): string
    {
        $row = Db::one('SELECT COUNT(*) AS c FROM clubs');
        return self::BARWY[((int) ($row['c'] ?? 0)) % count(self::BARWY)];
    }

    // ═══════════════════════════════════════════════════════════════ zmienne

    /**
     * Nowe zmienne z tego importu → nowa, AUTOMATYCZNA wersja templatu.
     *
     * Zwraca `[nazwy, wersja]`. Pusta lista nazw znaczy „templat już wszystko
     * znał" i wtedy NIE tworzymy wersji: wersja różniąca się od poprzedniej
     * wyłącznie numerem to szum w historii.
     *
     * KLUB BEZ TEMPLATU NIE DOSTAJE GO TUTAJ. Pierwszy templat powstaje
     * w konfiguratorze, świadomie — tam człowiek rozstrzyga sekcje, barwy
     * i markery drużyny „naszej", czyli rzeczy, których z jednego eksportu
     * wyprowadzić się nie da.
     *
     * @param array<string,mixed> $import
     * @return array{0: list<string>, 1: ?int}
     */
    public static function autoZmienne(int $importId, array $import): array
    {
        $mecz = Matches::find((int) $import['match_id']);
        $clubId = $mecz !== null && $mecz['club_id'] !== null ? (int) $mecz['club_id'] : null;
        if ($clubId === null) {
            return [[], null];
        }

        $templat = ReportTemplates::current($clubId);
        if ($templat === null) {
            return [[], null];
        }

        $config = ReportTemplates::decodeConfig($templat['config']);
        $meta = Imports::coverageMeta($import);
        if ($meta === [] || !is_array($config['variables'] ?? null)) {
            return [[], null];
        }

        /*
         * NOWE TAGI NIE WCHODZĄ DO TEMPLATU PRZY IMPORCIE (golden layout W3).
         *
         * Zapis słownika dzieje się wyłącznie w Słowniku klubu („Wlicz jako…").
         * Tag, którego klub nie wliczył, trafia do „Nierozpoznanych", a raport
         * mówi o nim banerem — zamiast po cichu dokładać zmienną, której nikt
         * nie nazwał ani nie przypisał do sekcji. Wcześniej (sesja 8) import
         * dopisywał tu `Configurator::autoZmienne()`.
         *
         * ZOSTAJĄ ALIASY niżej: wariant zapisu nazwy, która JUŻ jest zmienną
         * („INNE" przy „Inne"), to nie nowa decyzja, a bez aliasu baner w raporcie
         * (porównanie dosłowne) i Słownik (po normalizacji) mówiłyby co innego.
         */
        $nowe = [];

        $zmienne = array_merge(array_values((array) $config['variables']), $nowe);

        /*
         * ALIAS ZAMIAST DRUGIEJ ZMIENNEJ (0.16.3). Nazwa, która po normalizacji
         * JEST zmienną templatu („INNE" przy „Inne"), albo alias silnika
         * (`SBZ PODAJĄCY` przy `ZDOBYCIE SBZ`), dopisuje się do `aliases`
         * istniejącej zmiennej. Liczone PO dołożeniu nowych: wariant zapisu
         * nazwy, która właśnie weszła, trafia do niej, a nie w próżnię.
         */
        $aliasy = Configurator::autoAliasy($meta, ['variables' => $zmienne]);
        foreach ($aliasy as $i => $nazwyAliasow) {
            $zmienne[$i]['aliases'] = array_values(array_unique(array_merge(
                array_map('strval', (array) ($zmienne[$i]['aliases'] ?? [])),
                $nazwyAliasow
            )));
        }

        if ($nowe === [] && $aliasy === []) {
            return [[], null];
        }

        // Układ, progi i markery przenoszą się z poprzedniej wersji. Import
        // dokłada ZMIENNE, a nie przestawia raport — klub, który poukładał
        // kafle, nie ma ich stracić przy pierwszym nowym tagu w eksporcie.
        $nowyConfig = Configurator::config(
            $zmienne,
            array_values((array) ($config['sections_enabled'] ?? Configurator::SEKCJE)),
            array_values((array) ($config['team_us_rule']['markers'] ?? ['NASZA', 'MASZA'])),
            ReportLayout::zConfigu($config),
            (array) ($config['thresholds'] ?? [])
        );

        if (Configurator::bledyConfigu($nowyConfig) !== []) {
            // Nie zapisujemy templatu, którego render i tak by nie przyjął.
            // Import ma się udać nawet wtedy — raport powstanie na poprzedniej
            // wersji, a operator zobaczy nowe tagi na ekranie diffu.
            return [[], null];
        }

        $wersja = ReportTemplates::saveNewVersion(
            $clubId,
            $nowyConfig,
            null,                                   // created_by NULL: zapis systemowy
            // Notka BEZ dopisków: `ReportTemplates::autoForImport()` szuka jej
            // przez równość, a ekran pokrycia stoi na tym dopasowaniu.
            'auto: import #' . $importId
        );

        $nazwy = array_map(
            static fn(array $z): string => (string) ($z['source']['raw'] ?? ''),
            $nowe
        );

        return [array_values(array_filter($nazwy)), $wersja];
    }
}
