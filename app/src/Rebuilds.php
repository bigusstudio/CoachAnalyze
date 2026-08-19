<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Przeliczenie raportu pod AKTUALNY templat klubu (Sesja 7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CZYM TO SIĘ RÓŻNI OD „Wygeneruj ponownie"
 *
 *   `Imports::queueBuild()`   → powstaje NOWY raport, nowy plik, nowy adres.
 *                               Stary zostaje jako ślad wcześniejszych liczb.
 *   `Rebuilds::queue()`       → ten SAM raport, ten SAM plik, ten SAM token
 *                               publiczny. Podmieniamy wyłącznie treść.
 *
 * Obie akcje istnieją i obie mają sens. Przelicz odpowiada na sytuację, w której
 * templat klubu urósł o sekcje, a link do raportu jest już rozesłany sztabowi:
 * nowy raport pod nowym adresem znaczyłby, że wszyscy oglądają nieaktualny.
 *
 * CENA, ŚWIADOMIE PŁACONA: podmiana in place kasuje poprzednią treść HTML.
 * Ślad zostaje w `audit_log` (`report.rebuilt`, z numerami wersji przed i po)
 * oraz w historii wersji templatu — nie w pliku. Alternatywą byłoby zerwanie
 * działającego adresu publicznego i to jest gorsze.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KOLEJKOWANIE, NIGDY WYKONANIE. Ta klasa wyłącznie wstawia wiersz do `jobs`.
 * Silnik uruchamia proces roboczy z crona (`app/bin/run_job.php`) — PHP-FPM na
 * lh.pl ma `proc_open` na liście `disable_functions`.
 */
final class Rebuilds
{
    public const JOB_TYPE = 'rebuild_report';

    /** Powody odmowy — klucze tekstów, nie zdania. Wyświetla je warstwa widoku. */
    public const BLAD_BRAK_RAPORTU = 'recalc.err.no_report';
    public const BLAD_BRAK_PLIKOW  = 'recalc.err.no_raw';
    public const BLAD_W_TOKU       = 'recalc.err.in_progress';

    /**
     * Identyfikator partii zbiorczego przeliczenia.
     *
     * Siedzi w `payload_json` zadania, a NIE we własnej tabeli. Nowa tabela
     * znaczyłaby migrację do ręcznego odpalenia na produkcji dla wartości, od
     * której nie zależy ani jedna liczba w raporcie — ta sama decyzja co przy
     * `is_sample` (patrz `Imports::queueBuild()`).
     *
     * Wyłącznie szesnastkowy, bo trafia do wzorca `LIKE` w `batchProgress()`.
     */
    public static function newBatchId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Zadanie przeliczenia jednego raportu.
     *
     * @return array{ok:bool, job_id?:int, error?:string}
     */
    public static function queue(int $reportId, int $userId, ?string $batch = null): array
    {
        $report = Db::one(
            'SELECT id, match_id, club_id, template_version FROM reports WHERE id = :id',
            ['id' => $reportId]
        );
        if ($report === null) {
            return ['ok' => false, 'error' => self::BLAD_BRAK_RAPORTU];
        }

        $matchId = (int) $report['match_id'];
        $import  = Imports::latestForMatch($matchId);

        /*
         * BRAK SUROWYCH PLIKÓW ROZSTRZYGAMY TUTAJ, nie w procesie roboczym.
         *
         * Zakolejkowanie zadania, o którym już wiemy, że padnie, przenosi
         * komunikat z przycisku na ekran zadania — czyli o minutę później
         * i o jedno kliknięcie dalej, niż operator potrzebuje.
         */
        if (!Imports::rawUsable($import)) {
            return ['ok' => false, 'error' => self::BLAD_BRAK_PLIKOW];
        }

        $mecz = Db::one('SELECT owner_id FROM matches WHERE id = :id', ['id' => $matchId]);
        $wlasciciel = $mecz !== null && $mecz['owner_id'] !== null ? (int) $mecz['owner_id'] : null;

        if (self::pending($reportId) !== null) {
            // Dwa zadania na ten sam plik znaczą dwa `rename()` w nieznanej
            // kolejności — wygrałby ten, który skończy później, niekoniecznie
            // ten, który wystartował później.
            return ['ok' => false, 'error' => self::BLAD_W_TOKU];
        }

        Db::run(
            'INSERT INTO jobs (type, payload_json, status, attempts, created_at)
             VALUES (:type, :payload, :status, 0, :now)',
            [
                'type'    => self::JOB_TYPE,
                'payload' => json_encode([
                    'report_id' => $reportId,
                    // `import_id` jest OBOWIĄZKOWY dla dyspozytora w run_job.php:
                    // to po nim rozstrzyga, czy zadanie ma jeszcze na czym pracować.
                    'import_id' => (int) $import['id'],
                    'match_id'  => $matchId,
                    'batch'     => $batch,
                    /*
                     * WŁAŚCICIEL ZADANIA W ŁADUNKU.
                     *
                     * Potrzebny `Notifications::hasActiveWork()`, żeby chmurki
                     * przyspieszyły odpytywanie na czas przeliczania. Ta metoda
                     * patrzy na `matches.status`, a przeliczenie świadomie go
                     * NIE rusza (mecz ma sprawny raport i zostaje `done`) —
                     * bez tej wartości nie ma jak dojść do konta, które czeka.
                     *
                     * Bierzemy właściciela MECZU, nie klikającego: to jemu
                     * przychodzi chmurka „Raport przeliczony", tak samo jak
                     * przy generowaniu (`powiadomOGotowym()`).
                     */
                    'owner_id'  => $wlasciciel,
                ], JSON_UNESCAPED_UNICODE),
                'status'  => 'queued',
                'now'     => Stats::now(),
            ]
        );
        $jobId = (int) Db::pdo()->lastInsertId();

        Audit::log('report.rebuild_queued', $userId, 'report', $reportId, [
            'job_id' => $jobId,
            'batch'  => $batch,
            'from_template' => $report['template_version'],
        ]);

        return ['ok' => true, 'job_id' => $jobId];
    }

    /**
     * Zbiorcze przeliczenie wszystkich nieaktualnych raportów klubu.
     *
     * BŁĄD JEDNEJ POZYCJI NIE ZATRZYMUJE RESZTY — ani tutaj (odmowa wchodzi na
     * listę `blocked` i idziemy dalej), ani w kolejce (każdy raport to osobne
     * zadanie, a proces roboczy bierze je pojedynczo).
     *
     * @return array{batch:?string, queued:int, blocked:list<array<string,mixed>>}
     */
    public static function queueClub(int $clubId, int $userId): array
    {
        $batch   = self::newBatchId();
        $queued  = 0;
        $blocked = [];

        foreach (Reports::outdatedForClub($clubId) as $raport) {
            $wynik = self::queue((int) $raport['id'], $userId, $batch);

            if (!empty($wynik['ok'])) {
                $queued++;
                continue;
            }

            $blocked[] = [
                'report_id' => (int) $raport['id'],
                'match_id'  => (int) $raport['match_id'],
                'label'     => self::opisMeczu($raport),
                'error'     => (string) $wynik['error'],
            ];
        }

        Audit::log('report.rebuild_batch', $userId, 'club', $clubId, [
            'batch'   => $batch,
            'queued'  => $queued,
            'blocked' => count($blocked),
        ]);

        // Pusta partia nie ma czego pokazywać — zwracamy null, żeby widok nie
        // otwierał ekranu postępu dla zera zadań.
        return ['batch' => $queued > 0 ? $batch : null, 'queued' => $queued, 'blocked' => $blocked];
    }

    /**
     * Postęp partii: ile gotowych z ilu i co poszło nie tak, mecz po meczu.
     *
     * @return array{total:int, done:int, failed:int, working:int, rows:list<array<string,mixed>>}
     */
    public static function batchProgress(string $batch): array
    {
        $pusty = ['total' => 0, 'done' => 0, 'failed' => 0, 'working' => 0, 'rows' => []];

        // Identyfikator partii jest szesnastkowy — sprawdzamy to, zanim trafi
        // do wzorca LIKE. Znaki `%` i `_` z zewnątrz zmieniłyby zakres dopasowania.
        if (preg_match('/^[0-9a-f]{16}$/', $batch) !== 1) {
            return $pusty;
        }

        /*
         * LIKE zawęża, a rozstrzyga rozbiór JSON-a w PHP.
         *
         * Funkcje JSON-owe SQL-a różnią się między MariaDB a SQLite, a testy
         * chodzą na tym drugim — `JSON_EXTRACT` w zapytaniu działałby na
         * produkcji i wywracał się w CI. Ta sama decyzja co w `Matches::history()`.
         */
        $wiersze = Db::all(
            "SELECT id, status, error_text, payload_json, finished_at
               FROM jobs
              WHERE type = :typ AND payload_json LIKE :wzor
              ORDER BY id ASC",
            ['typ' => self::JOB_TYPE, 'wzor' => '%"batch":"' . $batch . '"%']
        );

        // Ładunki rozbieramy RAZ, przed pętlą budującą wiersze: nazwy meczów
        // biorą się z jednego zapytania, a nie z `Matches::find()` przy każdej
        // pozycji. Partia potrafi mieć kilkadziesiąt meczów, a wzorzec „N+1"
        // wprowadzony raz zostaje na stałe (por. `Reports::dolaczStanLinkow()`).
        $zadania = [];
        foreach ($wiersze as $job) {
            $payload = json_decode((string) $job['payload_json'], true);
            if (!is_array($payload) || (string) ($payload['batch'] ?? '') !== $batch) {
                continue;
            }
            $zadania[] = [$job, $payload];
        }

        $opisy = self::opisyMeczow(array_values(array_unique(array_map(
            static fn(array $z) => (int) ($z[1]['match_id'] ?? 0),
            $zadania
        ))));

        $rows = [];
        $done = $failed = $working = 0;

        foreach ($zadania as [$job, $payload]) {
            $status = (string) $job['status'];
            match ($status) {
                'done'   => $done++,
                'failed' => $failed++,
                default  => $working++,
            };

            $rows[] = [
                'job_id'    => (int) $job['id'],
                'report_id' => (int) ($payload['report_id'] ?? 0),
                'match_id'  => (int) ($payload['match_id'] ?? 0),
                'label'     => $opisy[(int) ($payload['match_id'] ?? 0)] ?? null,
                'status'    => $status,
                // Pełny zapis błędu zostaje w podglądzie zadania; na liście
                // partii mieści się jedna linia, żeby N pozycji dało się objąć wzrokiem.
                'error'     => self::pierwszaLinia((string) ($job['error_text'] ?? '')),
                'at'        => $job['finished_at'],
            ];
        }

        return [
            'total'   => count($rows),
            'done'    => $done,
            'failed'  => $failed,
            'working' => $working,
            'rows'    => $rows,
        ];
    }

    /**
     * Stan partii dla wskaźnika pracy — kształt oddawany przez punkt końcowy.
     *
     * WYŁĄCZNIE DANE, zero HTML-a: skrypt buduje z tego elementy przez
     * `textContent` (CLAUDE.md §9).
     *
     * @return array<string,mixed>
     */
    public static function batchStatus(string $batch): array
    {
        $postep = self::batchProgress($batch);

        $bledy = [];
        foreach ($postep['rows'] as $poz) {
            if ((string) $poz['status'] !== 'failed') {
                continue;
            }
            // Lista błędów PER MECZ. Przy kilkunastu pozycjach zbiorczy
            // komunikat nie mówi, który plik naprawić.
            $bledy[] = [
                'match_id' => (int) $poz['match_id'],
                'label'    => $poz['label'],
                'error'    => $poz['error'],
                'job_url'  => '/zadania/' . (int) $poz['job_id'],
            ];
        }

        return [
            'batch'   => $batch,
            'total'   => $postep['total'],
            'done'    => $postep['done'],
            'failed'  => $postep['failed'],
            'working' => $postep['working'],
            // Partia jest zamknięta, gdy nic już nie czeka ani nie liczy.
            // Rozstrzyga to `working`, a nie `done + failed === total`:
            // gdyby ktoś dołożył zadanie do tej samej partii, suma by się
            // zgadzała przez chwilę, w której praca jeszcze trwa.
            'finished' => $postep['total'] > 0 && $postep['working'] === 0,
            'errors'  => $bledy,
        ];
    }

    /**
     * Zadanie przeliczenia tego raportu, które jeszcze się nie skończyło.
     *
     * @return array<string,mixed>|null
     */
    public static function pending(int $reportId): ?array
    {
        // Zbiór jest z natury malutki: zadania czekające albo trwające.
        foreach (Db::all(
            "SELECT id, status, payload_json FROM jobs
              WHERE type = :typ AND status IN ('queued', 'running')
              ORDER BY id ASC",
            ['typ' => self::JOB_TYPE]
        ) as $job) {
            $payload = json_decode((string) $job['payload_json'], true);
            if (is_array($payload) && (int) ($payload['report_id'] ?? 0) === $reportId) {
                return $job;
            }
        }
        return null;
    }

    /**
     * Opisy meczów jednym zapytaniem.
     *
     * @param list<int> $matchIds
     * @return array<int,string>
     */
    private static function opisyMeczow(array $matchIds): array
    {
        $matchIds = array_values(array_filter($matchIds));
        if ($matchIds === []) {
            return [];
        }

        // Identyfikatory pochodzą z bazy, ale i tak idą przez parametry —
        // konkatenacja w SQL nie ma tu prawa się pojawić.
        $params = [];
        foreach ($matchIds as $i => $id) {
            $params['m' . $i] = $id;
        }
        $miejsca = implode(', ', array_map(static fn($i) => ':m' . $i, array_keys($matchIds)));

        $opisy = [];
        foreach (Db::all(
            'SELECT m.id, m.played_at, h.name AS home_name, a.name AS away_name
               FROM matches m
               LEFT JOIN clubs h ON h.id = m.club_home_id
               LEFT JOIN clubs a ON a.id = m.club_away_id
              WHERE m.id IN (' . $miejsca . ')',
            $params
        ) as $mecz) {
            $opisy[(int) $mecz['id']] = self::opisMeczu($mecz);
        }
        return $opisy;
    }

    /** „Nasza drużyna — Rywal", bez słów zastępczych na miejscu nieznanej nazwy. */
    private static function opisMeczu(array $wiersz): string
    {
        $nasza = trim((string) ($wiersz['home_name'] ?? ''));
        $rywal = trim((string) ($wiersz['away_name'] ?? ''));

        if ($nasza !== '' && $rywal !== '') {
            return $nasza . ' — ' . $rywal;
        }

        $znana = $nasza !== '' ? $nasza : $rywal;
        $data  = substr((string) ($wiersz['played_at'] ?? ''), 0, 10);

        if ($znana !== '') {
            return $data !== '' ? $znana . ' (' . $data . ')' : $znana;
        }
        return $data !== '' ? $data : View::t('common.unknown');
    }

    private static function pierwszaLinia(string $tekst): ?string
    {
        $tekst = trim($tekst);
        if ($tekst === '') {
            return null;
        }
        $koniec = strpos($tekst, "\n");
        return mb_substr($koniec === false ? $tekst : substr($tekst, 0, $koniec), 0, 160);
    }
}
