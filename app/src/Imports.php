<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Import eksportu: wiersz w `matches` + wiersz w `imports`, raport pokrycia,
 * kolejkowanie renderu.
 *
 * `coverage_json` i `warnings_json` zapisujemy DOKŁADNIE tak, jak przyszły
 * z silnika. PHP ich nie przelicza ani nie streszcza — od liczenia jest silnik
 * (CLAUDE.md §4), a raport pokrycia ma pokazywać to, co zobaczył parser.
 */
final class Imports
{
    /**
     * Nowy import wraz z meczem w stanie `draft`.
     *
     * BEZ POKRYCIA. Warstwa żądań nie uruchamia silnika (disable_functions na
     * PHP-FPM), więc `coverage_json`, `warnings_json` i `sections_json`
     * wypełnia dopiero zadanie `inspect` podniesione przez crona.
     */
    public static function create(
        int $ownerId,
        string $csvPath,
        ?string $jsonPath,
        string $checksum,
        ?int $clubId = null,
    ): int {
        /*
         * TENANT MECZU. `matches.club_id` jest NOT NULL od migracji 012, więc
         * mecz nie może powstać bez właściciela analizy.
         *
         * Parametr jest opcjonalny, bo ekran importu nie ma jeszcze wyboru klubu
         * — kontekst wchodzi do adresu dopiero w Sesji 2. Do tego czasu
         * rozstrzyga klub własny.
         *
         * Brak jakiegokolwiek klubu przerywamy TUTAJ, własnym komunikatem.
         * Bez tego użytkownik dostałby surowy błąd bazy o naruszeniu NOT NULL,
         * z którego nie wynika, że wystarczy założyć klub.
         *
         * SESJA 2: `/klub/{id}/import` przekazuje `clubId` wprost z trasy —
         * obowiązkowo, bez sięgania po ten fallback. Globalny `/import` (bez
         * kontekstu klubu w adresie) nadal istnieje dla ciągłości i korzysta
         * z klubu domyślnego jak dotąd.
         */
        $clubId ??= Clubs::tenantDefault();
        if ($clubId === null) {
            throw new \RuntimeException(
                'Import wymaga klubu: załóż klub, zanim wgrasz eksport.'
            );
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::run(
                'INSERT INTO matches (owner_id, club_id, season_id, status, created_at)
                 VALUES (:owner, :club, NULL, :status, :now)',
                [
                    'owner'  => $ownerId,
                    'club'   => $clubId,
                    'status' => 'draft',
                    'now'    => Stats::now(),
                ]
            );
            $matchId = (int) $pdo->lastInsertId();

            Db::run(
                'INSERT INTO imports (match_id, csv_path, json_path, checksum_csv, created_at)
                 VALUES (:mid, :csv, :json, :sum, :now)',
                [
                    'mid'  => $matchId,
                    'csv'  => $csvPath,
                    'json' => $jsonPath,
                    'sum'  => $checksum,
                    'now'  => Stats::now(),
                ]
            );
            $importId = (int) $pdo->lastInsertId();

            $pdo->commit();
            Audit::log('import.created', $ownerId, 'import', $importId, ['match_id' => $matchId]);
            return $importId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Kolejny import do ISTNIEJĄCEGO meczu (Sesja 7).
     *
     * Nie powstaje nowy mecz — dopisujemy wiersz do `imports`. Obowiązuje
     * najnowszy (`latestForMatch()`), a poprzednie zostają jako historia wgrań.
     *
     * PO CO TO ISTNIEJE: regeneracja raportu stoi na surowych plikach. Gdy plik
     * zniknął z dysku albo mecz powstał w czasach, gdy eksportów nie trzymaliśmy,
     * „Przelicz" nie ma z czego liczyć. Bez tej ścieżki jedynym wyjściem byłby
     * import od nowa — czyli NOWY mecz, nowy raport i nowy adres publiczny,
     * a stary link rozesłany sztabowi zostałby sierotą.
     *
     * `diff_done_at` zostaje puste: nowy eksport może nieść tagi, o których
     * templat nie wie, i operator ma zostać o nie zapytany jak przy imporcie.
     */
    public static function createForMatch(
        int $matchId,
        string $csvPath,
        ?string $jsonPath,
        string $checksum,
        int $userId,
    ): int {
        Db::run(
            'INSERT INTO imports (match_id, csv_path, json_path, checksum_csv, created_at)
             VALUES (:mid, :csv, :json, :sum, :now)',
            [
                'mid'  => $matchId,
                'csv'  => $csvPath,
                'json' => $jsonPath,
                'sum'  => $checksum,
                'now'  => Stats::now(),
            ]
        );
        $importId = (int) Db::pdo()->lastInsertId();

        Audit::log('import.reuploaded', $userId, 'import', $importId, ['match_id' => $matchId]);
        return $importId;
    }

    /**
     * Czy z tego importu da się jeszcze cokolwiek policzyć.
     *
     * Wiersz w bazie NIE JEST dowodem, że plik leży na dysku: magazyn bywa
     * czyszczony, przenoszony i przywracany z niepełnego zrzutu. Regeneracja
     * pytana o gotowość ma odpowiadać na podstawie dysku, a nie bazy — inaczej
     * „Przelicz" kolejkuje zadanie, które pada minutę później na brakującym
     * pliku, a operator dowiaduje się o tym z ekranu zadania zamiast z przycisku.
     *
     * JSON projektu jest opcjonalny (jak przy imporcie) — brak samego JSON-a
     * nie blokuje niczego, brak CSV blokuje wszystko.
     */
    public static function rawUsable(?array $import): bool
    {
        if ($import === null) {
            return false;
        }

        $csv = (string) ($import['csv_path'] ?? '');
        if ($csv === '') {
            return false;
        }

        try {
            return Storage::isInside($csv) && is_readable($csv);
        } catch (\Throwable $e) {
            // Brak STORAGE_PATH w konfiguracji to awaria wdrożenia, nie powód,
            // żeby wywrócić listę raportów. Mówimy „nie da się" i logujemy.
            error_log('imports: nie mogę sprawdzić surowych plików: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Zapis wyniku `inspect`. Wołane WYŁĄCZNIE z procesu roboczego.
     *
     * @param array<string,mixed> $meta
     */
    public static function saveInspection(int $importId, array $meta): void
    {
        Db::run(
            'UPDATE imports
                SET format_fingerprint = :fp, coverage_json = :cov, warnings_json = :warn,
                    sections_json = :sec, engine_version = :ver
              WHERE id = :id',
            [
                'fp'   => self::bareFingerprint($meta['format_fingerprint'] ?? null),

                /*
                 * `unmapped_tags` i `unmapped_labels` DOKŁADAMY DO `coverage_json`.
                 *
                 * USTERKA Z PRODUKCJI: kreator mapowań nie uruchamiał się nigdy,
                 * mimo eksportu z dwudziestoma nierozpoznanymi tagami. Powód:
                 * w `meta.json` te pola leżą NA NAJWYŻSZYM POZIOMIE, obok
                 * `coverage`, a nie w środku. Zapisywaliśmy tu wyłącznie
                 * `$meta['coverage']`, więc lista nierozpoznanych tagów przepadała
                 * przy zapisie i `needsMapping()` zawsze widziało pustkę.
                 *
                 * Ostrzeżenie o nich szło osobno, w `warnings_json`, i dlatego
                 * ekran pokrycia je pokazywał — co czyniło błąd niewidocznym.
                 *
                 * Scalamy w jeden obiekt zamiast dodawać kolumnę: `coverage_json`
                 * jest naszym własnym polem, a nie kopią kształtu z silnika.
                 * Klucze `coverage` zostają na swoich miejscach, więc
                 * `detectedTeams()` i widok pokrycia działają bez zmian.
                 */
                'cov'  => self::encode(array_merge(
                    (array) ($meta['coverage'] ?? []),
                    [
                        'unmapped_tags'   => array_values((array) ($meta['unmapped_tags'] ?? [])),
                        'unmapped_labels' => array_values((array) ($meta['unmapped_labels'] ?? [])),

                        /*
                         * PEŁNY SŁOWNIK EKSPORTU — dokładnie ta sama klasa
                         * pomyłki, co przy `unmapped_tags` wyżej: blok leży
                         * w `meta.json` NA NAJWYŻSZYM POZIOMIE, obok
                         * `coverage`, a nie w środku. Zapisanie samego
                         * `$meta['coverage']` gubi go po cichu, a konfigurator
                         * raportu (Sesje 3+4) widzi wtedy pusty słownik
                         * i nie ma z czego zbudować templatu.
                         *
                         * `null` zamiast pustej tablicy, gdy silnik bloku nie
                         * podaje: to znaczy „stary artefakt, sprzed tej wersji
                         * silnika", a nie „eksport bez tagów". Konfigurator
                         * odróżnia te dwa stany.
                         */
                        'dictionary'      => $meta['dictionary'] ?? null,
                    ]
                )),
                'warn' => self::encode($meta['warnings'] ?? null),
                'sec'  => self::encode([
                    'available'   => $meta['sections_available'] ?? [],
                    'unavailable' => $meta['sections_unavailable'] ?? [],
                ]),
                'ver'  => $meta['engine_version'] ?? null,
                'id'   => $importId,
            ]
        );

        $import = self::find($importId);
        if ($import !== null) {
            Db::run('UPDATE matches SET half_split_ms = :hs WHERE id = :id', [
                'hs' => $meta['half_split_ms'] ?? null,
                'id' => (int) $import['match_id'],
            ]);
            // Kluby dopasowujemy dopiero teraz — wcześniej nie znaliśmy nazw z danych.
            self::assignClubs((int) $import['match_id'],
                array_map('strval', (array) ($meta['coverage']['teams'] ?? [])));
        }
    }

    /** Zadanie `inspect` do kolejki. Nic nie uruchamia — podnosi je cron. */
    public static function queueInspect(int $importId, int $userId): int
    {
        $import = self::find($importId);
        if ($import === null) {
            throw new \RuntimeException("Import {$importId} nie istnieje");
        }

        Db::run(
            'INSERT INTO jobs (type, payload_json, status, attempts, created_at)
             VALUES (:type, :payload, :status, 0, :now)',
            [
                'type'    => 'inspect',
                'payload' => json_encode([
                    'import_id' => $importId,
                    'match_id'  => (int) $import['match_id'],
                ], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
                'now'     => Stats::now(),
            ]
        );
        $jobId = (int) Db::pdo()->lastInsertId();
        Audit::log('inspect.queued', $userId, 'import', $importId, ['job_id' => $jobId]);
        return $jobId;
    }

    /** Ostatnie zadanie danego typu dla importu — do pokazania stanu. */
    public static function latestJob(int $importId, ?string $type = null): ?array
    {
        $rows = Db::all(
            'SELECT id, type, status, payload_json FROM jobs ORDER BY id DESC LIMIT 200'
        );
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload_json'], true);
            if (!is_array($payload) || (int) ($payload['import_id'] ?? 0) !== $importId) {
                continue;
            }
            if ($type !== null && $row['type'] !== $type) {
                continue;
            }
            return $row;
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT i.*, m.id AS match_id, m.status AS match_status, m.played_at, m.season_id
               FROM imports i
               JOIN matches m ON m.id = i.match_id
              WHERE i.id = :id',
            ['id' => $id]
        );
    }

    /**
     * Pokrycie, ostrzeżenia i sekcje — wszystko z artefaktów zapisanych przez
     * proces roboczy. Warstwa żądań nie ma jak zapytać silnika (disable_functions),
     * więc to jest jedyne źródło tych danych.
     *
     * @return array{coverage:array<string,mixed>, warnings:list<array<string,mixed>>,
     *               sections_available:list<string>, sections_unavailable:list<array<string,mixed>>}
     */
    public static function report(array $import): array
    {
        $sections = self::decode($import['sections_json'] ?? null);

        $meta = self::decode($import['coverage_json'] ?? null);

        return [
            'coverage'             => $meta,
            'warnings'             => self::explainWarnings(
                array_values(self::decode($import['warnings_json'] ?? null)),
                $meta,
                self::mappingClubId($import)
            ),
            'sections_available'   => array_values((array) ($sections['available'] ?? [])),
            'sections_unavailable' => array_values((array) ($sections['unavailable'] ?? [])),
            // Co NIE weszlo do analizy. Raport pokrycia, ktory tego nie pokazuje,
            // sugeruje kompletnosc, ktorej nie ma — a to najgrozniejszy rodzaj
            // bledu w raporcie pokazywanym zarzadowi.
            'excluded'             => self::excluded($import, $meta),
        ];
    }

    /**
     * Dopasowanie nazw z eksportu do klubów i przypisanie ich do meczu.
     *
     * Wołane przy imporcie ORAZ tuż przed renderem — operator mógł w międzyczasie
     * założyć brakujący klub na ekranie pokrycia i wrócić. Właśnie dlatego
     * funkcja WYPEŁNIA puste pola, a nie nadpisuje istniejące: drugie wywołanie
     * ma dopowiedzieć to, czego przy pierwszym nie wiedzieliśmy, a nie cofnąć
     * decyzję podjętą pomiędzy nimi.
     *
     * Eksport LiveTag NIE niesie informacji o tym, kto był gospodarzem, i nie
     * udajemy jej. Klub oznaczony „mój" trafia do `club_home_id`, drugi do
     * `club_away_id`, ale te kolumny znaczą `us` i `them` z kontraktu silnika —
     * nie strony boiska. W interfejsie widać „Nasza drużyna" i „Rywal”.
     *
     * @param list<string> $detected nazwy z `meta.coverage.teams`
     */
    public static function assignClubs(int $matchId, array $detected): void
    {
        $own = null;
        $other = null;

        foreach ($detected as $name) {
            $club = Clubs::matchByExportName((string) $name);
            if ($club === null) {
                continue;
            }
            Clubs::rememberAlias((int) $club['id'], (string) $name);

            if (!empty($club['is_own_team']) && $own === null) {
                $own = (int) $club['id'];
            } elseif ($other === null) {
                $other = (int) $club['id'];
            }
        }

        /*
         * ═══════════════════════════════════════════════════════════════════
         * WYPEŁNIAMY PUSTE POLA. NIGDY NIE NADPISUJEMY JUŻ USTAWIONYCH.
         *
         * USTERKA Z PRODUKCJI: operator wybierał rywala w formularzu meczu,
         * a kliknięcie „Generuj" kasowało ten wybór. Powód: `queueBuild()` woła
         * tę funkcję tuż przed renderem, a ona pisała OBIE kolumny bezwarunkowo.
         * Eksport LiveTag ma kolumnę `team` wypełnioną wybiórczo (pułapka nr 5),
         * więc przy jednej wykrytej nazwie `club_away_id` wracało do NULL —
         * i raport pokazywał „nieznana" po stronie przeciwnika mimo poprawnie
         * wypełnionej mety.
         *
         * Objaw był podstępny, bo formularz zapisywał się poprawnie: wybór ginął
         * dopiero krok dalej, w akcji, która z wyborem rywala nie ma nic wspólnego.
         *
         * Zasada odtąd: dane wykryte w eksporcie UZUPEŁNIAJĄ to, czego nie wiemy,
         * i nie mają prawa unieważnić decyzji człowieka. Korekta błędnego
         * dopasowania jest nadal możliwa — formularz meta (`Matches::saveMeta()`)
         * zapisuje `club_away_id` bezwarunkowo, bo to jawne polecenie operatora.
         * ═══════════════════════════════════════════════════════════════════
         */
        $obecny = Db::one(
            'SELECT club_home_id, club_away_id FROM matches WHERE id = :id',
            ['id' => $matchId]
        );
        if ($obecny === null) {
            return;
        }

        $domTeraz    = $obecny['club_home_id'] !== null ? (int) $obecny['club_home_id'] : null;
        $wyjazdTeraz = $obecny['club_away_id'] !== null ? (int) $obecny['club_away_id'] : null;

        // Gdy żaden klub nie jest oznaczony jako „mój", pierwszy dopasowany
        // idzie na pozycję gospodarza — kolejność z eksportu jest tu jedyną,
        // jaką mamy, i lepsza niż zostawienie obu pól pustych.
        //
        // Promocja ma sens WYŁĄCZNIE przy pustym gospodarzu. Gdy gospodarz już
        // jest, dopasowany klub jest kandydatem na rywala, a nie na drugiego
        // gospodarza — inaczej wykryta nazwa przepadałaby bez śladu.
        if ($own === null && $other !== null && $domTeraz === null) {
            $own = $other;
            $other = null;
        }

        $dom    = $domTeraz    ?? $own;
        $wyjazd = $wyjazdTeraz ?? $other;

        // Ten sam klub po obu stronach byłby meczem z samym sobą. Przy kolizji
        // ustępuje strona wykryta, bo to ona jest zgadywana.
        if ($wyjazd !== null && $wyjazd === $dom) {
            $wyjazd = $wyjazdTeraz;
        }

        // Bez zmiany nie ruszamy bazy: ta funkcja chodzi przy każdym imporcie
        // i przy każdym generowaniu, a zapis bez różnicy to tylko szum.
        if ($dom === $domTeraz && $wyjazd === $wyjazdTeraz) {
            return;
        }

        Db::run('UPDATE matches SET club_home_id = :h, club_away_id = :a WHERE id = :id', [
            'h'  => $dom,
            'a'  => $wyjazd,
            'id' => $matchId,
        ]);
    }

    /** Nazwy drużyn wykryte w danych, zapisane w raporcie pokrycia. */
    public static function detectedTeams(array $import): array
    {
        $coverage = self::decode($import['coverage_json'] ?? null);
        return array_values(array_map('strval', (array) ($coverage['teams'] ?? [])));
    }

    public static function queueBuild(int $importId, int $userId, bool $isSample = false): int
    {
        $import = self::find($importId);
        if ($import === null) {
            throw new \RuntimeException("Import {$importId} nie istnieje");
        }

        // Kluby mogły powstać dopiero teraz, na ekranie pokrycia.
        self::assignClubs((int) $import['match_id'], self::detectedTeams($import));

        Db::run(
            'INSERT INTO jobs (type, payload_json, status, attempts, created_at)
             VALUES (:type, :payload, :status, 0, :now)',
            [
                'type'    => 'build_report',
                'payload' => json_encode([
                    'import_id' => $importId,
                    'match_id'  => (int) $import['match_id'],
                    /*
                     * `is_sample` — przykładowy raport z konfiguratora.
                     * ZERO OSOBNEJ ŚCIEŻKI KODU: to zwykłe zadanie `build_report`
                     * w tej samej kolejce, a flaga służy wyłącznie oznaczeniu
                     * wyniku w interfejsie. Osobny tryb generowania znaczyłby
                     * drugi pipeline do utrzymania i drugie miejsce, w którym
                     * liczby mogą się rozjechać.
                     */
                    'is_sample' => $isSample,
                ], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
                'now'     => Stats::now(),
            ]
        );
        $jobId = (int) Db::pdo()->lastInsertId();

        Db::run('UPDATE matches SET status = :s WHERE id = :id', [
            's'  => 'queued',
            'id' => (int) $import['match_id'],
        ]);

        Audit::log('report.queued', $userId, 'import', $importId, ['job_id' => $jobId]);
        return $jobId;
    }

    /**
     * Najnowszy import meczu — źródło do ponownego wygenerowania raportu.
     *
     * Bierzemy najnowszy, nie pierwszy: jeśli operator wgrał poprawiony eksport,
     * to on jest tym, z którego ma powstać kolejny raport.
     *
     * @return array<string,mixed>|null
     */
    public static function latestForMatch(int $matchId): ?array
    {
        return Db::one(
            'SELECT * FROM imports WHERE match_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $matchId]
        );
    }



    /**
     * Doprecyzowanie ostrzezen silnika o to, czego silnik wiedziec nie moze.
     *
     * PRZYPADEK Z PRODUKCJI: `XG_POZA_STRZALEM` brzmialo „Liczba w komentarzu
     * przy tagu, ktory nie jest strzalem — pominieta przy xG: Strzal". Tag
     * NAZYWAL SIE „Strzal", wiec komunikat sam sobie przeczyl i czytal sie jak
     * awaria. Prawdziwa przyczyna byla inna: tag nie mial mapowania, bo slownik
     * silnika zna „STRZAL" wielkimi literami.
     *
     * Silnik nie moze tego napisac — nie wie, ze tag da sie zmapowac, bo nie zna
     * profilu klubu ani tego, czy ktos juz o nim decydowal. My wiemy, wiec
     * dopisujemy zdanie o rzeczywistej przyczynie i droge wyjscia.
     *
     * ORYGINALNY KOMUNIKAT ZOSTAJE. Nie podmieniamy tresci od silnika, tylko
     * ja uzupelniamy — inaczej diagnostyka z logu przestalaby sie zgadzac
     * z tym, co widzi operator.
     *
     * @param list<array<string,mixed>> $warnings
     * @param array<string,mixed> $meta zdekodowany `coverage_json`
     * @return list<array<string,mixed>>
     */
    private static function explainWarnings(array $warnings, array $meta, ?int $clubId): array
    {
        $nierozpoznane = [];
        foreach ((array) ($meta['unmapped_tags'] ?? []) as $poz) {
            $nazwa = is_array($poz) ? (string) ($poz['tag'] ?? $poz['name'] ?? '') : (string) $poz;
            if ($nazwa !== '') {
                $nierozpoznane[$nazwa] = true;
            }
        }

        // Tagi, o ktorych juz zdecydowano, NIE sa juz „bez mapowania".
        // `inspect` nie zna profilu klubu, wiec jego lista bywa nieaktualna —
        // twierdzenie „tag nie ma pojecia" po zmapowaniu byloby po prostu falszem.
        foreach (array_keys($clubId !== null ? Mappings::decidedTags($clubId) : []) as $tag) {
            unset($nierozpoznane[(string) $tag]);
        }

        if ($nierozpoznane === []) {
            return $warnings;
        }

        foreach ($warnings as &$w) {
            if ((string) ($w['code'] ?? '') !== 'XG_POZA_STRZALEM') {
                continue;
            }

            // Tagi z ostrzezenia, ktore sa jednoczesnie niezmapowane.
            $winne = array_values(array_filter(
                array_map('strval', (array) ($w['tags'] ?? [])),
                static fn(string $tag) => isset($nierozpoznane[$tag])
            ));

            if ($winne === []) {
                continue;   // Tag jest zmapowany i naprawde nie jest strzalem.
            }

            $w['cause']       = 'unmapped';
            $w['cause_tags']  = $winne;
            $w['cause_url']   = $clubId !== null ? '/kluby/' . $clubId . '/mapowania' : null;
        }
        unset($w);

        return $warnings;
    }

    /**
     * Zdarzenia poza analiza: ile ich jest i z jakich tagow pochodza.
     *
     * DWA ZRODLA, obydwa musza byc widoczne:
     *  - tagi NIEROZPOZNANE (silnik ich nie zna i nikt jeszcze nie zdecydowal),
     *  - tagi swiadomie oznaczone „nie analizuj" w profilu klubu.
     *
     * Drugie sa grozniejsze, bo wygladaja na obsluzone. Decyzja „pomijamy" jest
     * poprawna, ale musi byc WIDOCZNA przy kazdym raporcie — inaczej po pol roku
     * nikt nie pamieta, ze jedna trzecia zdarzen nie wchodzi do liczb.
     *
     * @return array{count:?int, unrecognised:list<string>, ignored:list<string>, total:?int}
     */
    private static function excluded(array $import, array $meta): array
    {
        $clubId = self::mappingClubId($import);

        $nierozpoznane = [];
        foreach ((array) ($meta['unmapped_tags'] ?? []) as $poz) {
            $nazwa = is_array($poz) ? (string) ($poz['tag'] ?? $poz['name'] ?? '') : (string) $poz;
            if ($nazwa !== '') {
                $nierozpoznane[] = $nazwa;
            }
        }

        $zdecydowane = $clubId !== null ? Mappings::decidedTags($clubId) : [];
        $pominiete   = $clubId !== null ? Mappings::ignoredTags($clubId) : [];

        // Z listy „nierozpoznanych" WYPADAJA tagi, o ktorych juz zdecydowano.
        //
        // `inspect` nie dostaje profilu klubu (docs/KONTRAKT_CLI.md), wiec zglasza
        // jako nierozpoznane rowniez te tagi, ktore operator wlasnie zmapowal.
        // Pokazywanie ich dalej jako nierozpoznanych sugerowaloby, ze decyzja
        // nie zadzialala — a zadziala przy najblizszym renderze, bo tam profil
        // juz idzie do silnika.
        $nierozpoznane = array_values(array_filter(
            $nierozpoznane,
            static fn(string $tag) => !isset($zdecydowane[$tag])
        ));

        // Liczba zdarzen: bierzemy ja z `meta`, jesli silnik ja podaje.
        // NIE liczymy jej w PHP — parsowanie eksportu tutaj oznaczaloby drugi
        // parser i wszystkie jedenascie pulapek formatu LiveTag od nowa.
        // `coverage_json` JEST obiektem `coverage` — bez zagniezdzenia.
        // Odczyt przez `$meta['coverage']['events']` zawsze dawal null i to ta
        // sama pomylka, ktora wylaczyla caly kreator.
        $ile = $meta['unanalysed'] ?? null;

        return [
            'count'        => $ile !== null ? (int) $ile : null,
            'total'        => isset($meta['events']) ? (int) $meta['events'] : null,
            'unrecognised' => array_values(array_unique($nierozpoznane)),
            'ignored'      => $pominiete,
        ];
    }

    /**
     * Klub „nasz" wykryty w eksporcie — właściciel profilu mapowań.
     *
     * Kreator działa PRZED ekranem pokrycia, czyli zanim kluby zostaną
     * przypisane do meczu. Rozstrzygamy więc z nazw wykrytych w danych,
     * tak samo jak `assignClubs()`, tylko bez zapisu.
     *
     * Gdy żaden klub nie pasuje, profilu nie ma i wszystkie tagi są nowe —
     * co jest prawdą o pierwszym imporcie nowego klubu.
     */
    public static function mappingClubId(array $import): ?int
    {
        $matchId = (int) ($import['match_id'] ?? 0);
        if ($matchId > 0) {
            $mecz = Db::one(
                'SELECT club_home_id FROM matches WHERE id = :id',
                ['id' => $matchId]
            );
            if ($mecz !== null && $mecz['club_home_id'] !== null) {
                return (int) $mecz['club_home_id'];
            }
        }

        $pierwszyDopasowany = null;
        foreach (self::detectedTeams($import) as $nazwa) {
            $club = Clubs::matchByExportName((string) $nazwa);
            if ($club === null) {
                continue;
            }
            if (!empty($club['is_own_team'])) {
                return (int) $club['id'];
            }
            $pierwszyDopasowany ??= (int) $club['id'];
        }

        return $pierwszyDopasowany;
    }

    /**
     * Czy import wymaga zatrzymania na kreatorze mapowań.
     *
     * Pytamy o to przy każdym wejściu na pokrycie, a nie raz po imporcie:
     * profil klubu mógł się w międzyczasie zmienić i decyzja podjęta wtedy
     * bywa już nieaktualna.
     */
    public static function needsMapping(array $import): bool
    {
        $meta = self::decode($import['coverage_json'] ?? null);
        if (!is_array($meta) || $meta === []) {
            return false;   // Bez pokrycia nie ma czego porównywać.
        }

        $nieznane = Mappings::unknown($meta, self::mappingClubId($import));
        return $nieznane['tags'] !== [] || $nieznane['labels'] !== [];
    }

    /**
     * Zapamiętanie, z jaką wersją profilu przeszedł ten import.
     *
     * Bez tego nie da się odróżnić „nie było nowych tagów, przeszliśmy dalej"
     * od „ekran mapowania nigdy się nie pokazał". Pierwsze jest poprawne,
     * drugie jest błędem — i mają wyglądać inaczej.
     */
    public static function setMappingProfile(int $importId, int $profileId): void
    {
        Db::run(
            'UPDATE imports SET mapping_profile_id = :p WHERE id = :id',
            ['p' => $profileId, 'id' => $importId]
        );
    }

    /**
     * „Operator widział ekran nowych tagów i zdecydował" (Sesja 6).
     *
     * NIE ZNACZY „nie ma już nic nowego". Akcja „pomiń w tym imporcie" niczego
     * nie zapisuje — i tak ma być, bo przy następnym eksporcie chcemy o ten tag
     * zapytać ponownie. Bez tego znacznika pominięta pozycja byłaby przy każdym
     * wejściu znowu „nowa", ekran pokrycia odsyłałby na diff w kółko, a operator
     * nigdy nie dotarłby do przycisku „Generuj".
     */
    public static function markDiffDone(int $importId): void
    {
        Db::run('UPDATE imports SET diff_done_at = :now WHERE id = :id', [
            'now' => Stats::now(),
            'id'  => $importId,
        ]);
    }

    /** Najnowszy raport dla meczu — do odnośnika po zakończeniu. */
    public static function latestReport(int $matchId): ?array
    {
        return Db::one(
            'SELECT id, html_path, engine_version, generated_at
               FROM reports WHERE match_id = :mid ORDER BY id DESC LIMIT 1',
            ['mid' => $matchId]
        );
    }

    private static function bareFingerprint(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        return substr(str_starts_with($value, 'sha256:') ? substr($value, 7) : $value, 0, 64);
    }

    private static function encode(mixed $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string,mixed> */
    private static function decode(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
