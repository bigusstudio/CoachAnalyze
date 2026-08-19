<?php
declare(strict_types=1);

/**
 * Proces roboczy kolejki. Uruchamiany z CRONA, co minutę.
 *
 *   php app/bin/run_job.php            # jedno przejście po kolejce
 *   php app/bin/run_job.php <job_id>   # konkretne zadanie (diagnostyka)
 *
 * DLACZEGO CRON, A NIE START Z PANELU
 *
 * PHP-FPM na lh.pl ma `disable_functions` obejmujące `proc_open`, `exec`,
 * `shell_exec`, `popen`, `system` i resztę — z przeglądarki nie da się uruchomić
 * żadnego procesu. Wcześniejsza architektura odpalała silnik natychmiast po
 * wgraniu eksportu i wywalała się wyjątkiem PRZED zapisem zadania, przez co
 * tabela `jobs` zostawała pusta i nie było nawet czego ponowić.
 *
 * Cała warstwa żądań tylko KOLEJKUJE. Wykonanie idzie wyłącznie tędy.
 * Szczegóły i pełna lista wyłączonych funkcji: docs/OGRANICZENIA_HOSTINGU.md.
 *
 * PHP CLI nie ma `open_basedir`, więc sięga do STORAGE_PATH i do venv Pythona.
 *
 * Traceback trafia do `jobs.error_text` (skrócony) i do logu (pełny) —
 * nigdy do przeglądarki.
 */

use CoachAnalyze\Audit;
use CoachAnalyze\Clubs;
use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\EngineRunner;
use CoachAnalyze\Imports;
use CoachAnalyze\IndexTerms;
use CoachAnalyze\Jobs;
use CoachAnalyze\Mailer;
use CoachAnalyze\Mappings;
use CoachAnalyze\Matches;
use CoachAnalyze\Notifications;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\PasswordReset;
use CoachAnalyze\Rebuilds;
use CoachAnalyze\RedisClient;
use CoachAnalyze\Stats;
use CoachAnalyze\Storage;
use CoachAnalyze\View;

require dirname(__DIR__) . '/src/bootstrap.php';

// Uruchamianie procesow lezy POZA drzewem autoloadera - patrz naglowek klasy.
require __DIR__ . '/EngineRunner.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/*
 * Który plik konfiguracji wygrał — TYLKO GDY COŚ JEST NIE TAK albo pod flagą.
 *
 * Ta linia szła wcześniej przy KAŻDYM przejściu crona: 1440 wpisów na dobę,
 * w których toną prawdziwe błędy. Log, którego nikt nie czyta, bo w 99%
 * powtarza to samo, przestaje być logiem.
 *
 * Zostaje w dwóch sytuacjach, w których naprawdę pomaga:
 *  - gdy `.env` nie został wczytany wcale (wtedy nic nie zadziała i trzeba
 *    wiedzieć, gdzie szukaliśmy),
 *  - gdy ktoś świadomie diagnozuje: `CA_DEBUG_CONFIG=1 php app/bin/run_job.php`.
 *
 * Awarie i tak zapisują pełny kontekst — patrz `opiszAwarie()` i `zakoncz()`.
 */
$zrodloKonfiguracji = Config::loadedFrom();

if ($zrodloKonfiguracji === null) {
    error_log('run_job: NIE WCZYTANO .env — sprawdzane: '
        . implode(', ', Config::envCandidates()));
} elseif (getenv('CA_DEBUG_CONFIG') !== false) {
    error_log('run_job: konfiguracja z ' . $zrodloKonfiguracji);
}

$tylkoJedno = isset($argv[1]) ? (int) $argv[1] : 0;

// ---------------------------------------------------------------- blokada
//
// Cron chodzi co minutę, a render bywa dłuższy. Bez blokady kolejne uruchomienia
// nakładałyby się na siebie. Blokada jest w Redis, z czasem życia nieco dłuższym
// niż limit silnika, żeby padnięty proces nie zablokował kolejki na zawsze.
//
// To zabezpieczenie DODATKOWE. Właściwą wyłączność daje atomowe przejęcie
// zadania (UPDATE ... WHERE status='queued'), które działa także bez Redisa.
$blokada = null;
try {
    $blokada = RedisClient::fromConfig();
    $ttl = Config::int('ENGINE_TIMEOUT', 180) + 60;
    if (!$blokada->setNx('lock:worker', (string) getmypid(), $ttl)) {
        fwrite(STDOUT, "Inne przejście workera trwa — kończę.\n");
        exit(0);
    }
} catch (\Throwable $e) {
    // Brak Redisa nie może zatrzymać kolejki: atomowe przejęcie zadania wystarczy.
    error_log('run_job: blokada niedostępna (' . $e->getMessage() . ') — jadę bez niej');
    $blokada = null;
}

try {
    if ($tylkoJedno > 0) {
        przetworz($tylkoJedno);
    } else {
        // Ograniczona liczba zadań na przejście — cron wróci za minutę, a długa
        // pętla zwiększa tylko ryzyko, że proces zostanie ubity w połowie.
        // `available_at` przepuszcza tylko zadania, których termin nadszedł.
        // Służy mailowi „przetwarzanie w toku", który ma pójść dopiero po zwłoce —
        // bez tego worker podjąłby go od razu i cała zwłoka byłaby fikcją.
        foreach (Db::all(
            "SELECT id FROM jobs
              WHERE status = 'queued' AND (available_at IS NULL OR available_at <= :teraz)
              ORDER BY id LIMIT 5",
            ['teraz' => Stats::now()]
        ) as $wiersz) {
            przetworz((int) $wiersz['id']);
        }
    }

    // Wersja silnika dla stopki panelu — FPM nie może o nią zapytać sam.
    EngineRunner::refreshVersion();
} finally {
    if ($blokada !== null) {
        try {
            $blokada->del('lock:worker');
        } catch (\Throwable) {
            // Blokada wygaśnie sama po TTL.
        }
    }
}

exit(0);

// --------------------------------------------------------------------------

/**
 * Przejęcie i wykonanie jednego zadania.
 *
 * Warunek `status = 'queued'` jest w UPDATE, nie w PHP — gdyby cron i ręczne
 * uruchomienie ruszyły równocześnie, tylko jedno przejmie wiersz.
 */
function przetworz(int $jobId): void
{
    $przejete = Db::run(
        "UPDATE jobs SET status = 'running', attempts = attempts + 1, started_at = :now,
                         finished_at = NULL, exit_code = NULL, error_text = NULL
          WHERE id = :id AND status = 'queued'",
        ['id' => $jobId, 'now' => Stats::now()]
    )->rowCount();

    if ($przejete !== 1) {
        return;
    }

    $job = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);
    $payload = json_decode((string) ($job['payload_json'] ?? ''), true);

    // Wysyłka poczty nie dotyczy importu — obsługujemy ją przed sprawdzeniem
    // istnienia importu, inaczej każde zadanie mailowe kończyłoby się
    // komunikatem „Import 0 nie istnieje".
    if ((string) $job['type'] === 'send_mail') {
        try {
            wyslijMail($jobId, (int) ($payload['notification_id'] ?? 0));
        } catch (\Throwable $e) {
            error_log("run_job {$jobId} (poczta): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            zakoncz($jobId, 4, $e->getMessage());
        }
        return;
    }

    if ((string) $job['type'] === PasswordReset::JOB_TYPE) {
        try {
            wyslijReset($jobId, (string) ($payload['email'] ?? ''), (int) $job['attempts']);
        } catch (\Throwable $e) {
            error_log("run_job {$jobId} (reset hasła): " . $e->getMessage() . "\n" . $e->getTraceAsString());
            zakoncz($jobId, 4, $e->getMessage());
        }
        return;
    }

    $importId = (int) ($payload['import_id'] ?? 0);
    $import = $importId > 0 ? Imports::find($importId) : null;

    if ($import === null) {
        zakoncz($jobId, 4, 'Import ' . $importId . ' nie istnieje albo został usunięty.');
        return;
    }

    try {
        match ((string) $job['type']) {
            'inspect'         => wykonajInspekcje($jobId, $importId, $import),
            'build_report'    => wykonajRender($jobId, $importId, $import, $payload),
            Rebuilds::JOB_TYPE => wykonajPrzeliczenie($jobId, $importId, $import, $payload),
            default           => zakoncz($jobId, 4, 'Nieznany typ zadania: ' . $job['type']),
        };
    } catch (\Throwable $e) {
        error_log("run_job {$jobId}: " . $e->getMessage() . "\n" . $e->getTraceAsString());

        /*
         * STANU MECZU NIE RUSZAMY PRZY PRZELICZENIU.
         *
         * Mecz z gotowym raportem jest `done` i taki zostaje: nieudane
         * przeliczenie niczego mu nie odbiera — stary HTML nadal leży na dysku
         * i nadal odpowiada pod swoim adresem publicznym. Oznaczenie meczu jako
         * `failed` mówiłoby operatorowi, że stracił raport, którego nie stracił,
         * i zapaliłoby alert na pulpicie bez powodu.
         */
        if ((string) $job['type'] !== Rebuilds::JOB_TYPE) {
            Db::run('UPDATE matches SET status = :s WHERE id = :id',
                ['s' => 'failed', 'id' => (int) $import['match_id']]);
        }
        zakoncz($jobId, 4, $e->getMessage());
    }
}

/** Raport pokrycia — bez renderu. Wynik ląduje w bazie i zasila ekran pokrycia. */
function wykonajInspekcje(int $jobId, int $importId, array $import): void
{
    $wynik = EngineRunner::inspect((string) $import['csv_path'], $import['json_path'] ?: null);

    if (!$wynik['ok']) {
        // Ten sam opis co przy renderze — inaczej te same awarie tłumaczyłyby się
        // operatorowi inaczej w zależności od tego, którym wejściem przyszły.
        $powod = opiszAwarie($wynik, (array) ($wynik['meta'] ?? []));
        Db::run('UPDATE matches SET status = :s WHERE id = :id',
            ['s' => 'failed', 'id' => (int) $import['match_id']]);
        zakoncz($jobId, $wynik['exit'], $powod);
        powiadomOAwarii((int) $import['match_id'], $jobId, $powod, Jobs::canRetry('failed'));
        return;
    }

    Imports::saveInspection($importId, (array) $wynik['meta']);
    zakoncz($jobId, 0, null);
    Audit::log('inspect.done', null, 'import', $importId, ['job_id' => $jobId]);
}

/**
 * Jedno przejście silnika: konfiguracja zadania, templat klubu, wywołanie CLI.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * JEDNA ŚCIEŻKA DLA RENDERU I DLA PRZELICZENIA (Sesja 7).
 *
 * Przeliczenie raportu pod nowy templat ma dać DOKŁADNIE to samo, co dałby
 * import tego eksportu dzisiaj. Drugi zestaw wywołań silnika — nawet skopiowany
 * wiernie — znaczyłby drugie miejsce, w którym liczby mogą się rozjechać, i
 * rozjechałyby się przy pierwszej zmianie w konfiguracji, o której ktoś
 * zapomniałby w drugim egzemplarzu.
 *
 * Funkcja NICZEGO NIE ZAPISUJE do `reports`. Co zrobić z wynikiem, rozstrzyga
 * wywołujący: render wstawia nowy wiersz, przeliczenie podmienia plik w miejscu.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @param string $outHtml  dokąd silnik ma zapisać HTML. Przy przeliczeniu to
 *   plik TYMCZASOWY obok docelowego, nigdy sam docelowy.
 * @return array{wynik:array<string,mixed>, meta:array<string,mixed>,
 *               template_version:?int, club_id:?int, dir:string}
 */
function uruchomSilnik(int $jobId, array $import, string $outHtml): array
{
    $matchId = (int) $import['match_id'];
    $dir = Storage::jobDir($jobId);
    $reportPath = $outHtml;

    // Nazwy i barwy klubów z bazy — silnik ich nie odgaduje (docs/KONTRAKT_CLI.md).
    $match = Db::one('SELECT club_home_id, club_away_id FROM matches WHERE id = :id', ['id' => $matchId]);
    $teams = Clubs::engineConfig(
        $match['club_home_id'] !== null ? (int) $match['club_home_id'] : null,
        $match['club_away_id'] !== null ? (int) $match['club_away_id'] : null
    );

    /*
     * Odsyłacze do indeksu współczynników (M1) — TYLKO gdy znamy klub.
     *
     * Adres bazowy jest PUBLICZNY (/r/{club_key}/i/…): ten sam wyrenderowany
     * plik HTML jest serwowany i w panelu, i pod adresem publicznym, więc
     * odsyłacz musi działać w obu kontekstach bez logowania. Klucz klubu jest
     * losowy i jest już obecny w każdym publicznym adresie raportu.
     *
     * Bez klubu render nie dostaje `index_base` i NICZEGO nie dokleja —
     * wyjście jest wtedy bajt w bajt takie jak przed M1 (test złoty).
     */
    $klubIndeksu = $match['club_home_id'] !== null ? Clubs::find((int) $match['club_home_id']) : null;
    $opcjeIndeksu = [];
    if ($klubIndeksu !== null && !empty($klubIndeksu['club_key'])) {
        $opcjeIndeksu = [
            'index_base'  => '/r/' . $klubIndeksu['club_key'] . '/i/',
            'index_links' => IndexTerms::linksFor((int) $klubIndeksu['id']),
        ];
    }

    // Tenant meczu — właściciel analizy, a więc i właściciel templatu.
    // Potrzebny PRZED wywołaniem silnika, bo od niego zależy `--template`.
    $meczTenant = Db::one('SELECT club_id FROM matches WHERE id = :id', ['id' => $matchId]);
    $clubIdTenanta = $meczTenant === null || $meczTenant['club_id'] === null
        ? null
        : (int) $meczTenant['club_id'];

    /*
     * TEMPLAT RAPORTU KLUBU (Sesja 5) — serializowany do pliku roboczego zadania.
     *
     * Bierzemy AKTUALNĄ wersję (MAX version) i zapisujemy JĄ, a nie odsyłacz do
     * bazy: silnik nie chodzi do bazy (D4), a plik zostaje w katalogu zadania
     * jako dowód, z czego powstał ten konkretny raport. Pytanie „dlaczego raport
     * z marca pokazuje inną liczbę" ma dzięki temu odpowiedź na dysku, nie tylko
     * w historii wersji.
     *
     * Zapisujemy TREŚĆ KOLUMNY BEZ PRZEKSZTAŁCEŃ. Przepakowanie po drodze
     * znaczyłoby, że plik roboczy i wiersz w bazie mogą się różnić, a wtedy
     * dowód przestaje być dowodem.
     *
     * Brak templatu (klub przed konfiguratorem) to POPRAWNY STAN: `--template`
     * nie leci, a pipeline zachowuje się dokładnie tak jak przed Sesją 5.
     */
    $templat = $clubIdTenanta !== null ? ReportTemplates::current($clubIdTenanta) : null;
    $templatePath = null;
    $templateVersion = null;

    if ($templat !== null && !empty($templat['config'])) {
        $templatePath = $dir . '/template.json';
        file_put_contents($templatePath, (string) $templat['config']);
        $templateVersion = (int) $templat['version'];
    }

    $configPath = $dir . '/config.json';
    file_put_contents($configPath, json_encode([
        'match_id'        => $matchId,
        'season_label'    => null,
        'teams'           => $teams === [] ? new stdClass() : $teams,
        // PROFIL MAPOWAŃ KLUBU, nie pusty zestaw.
        //
        // Dotąd szedł tu `['version' => 1, 'rules' => []]`, czyli silnik liczył
        // wyłącznie słownikiem domyślnym. Klub z własnym nazewnictwem dostawał
        // przez to raport z pustymi sekcjami, a decyzje operatora z kreatora
        // mapowań nie miały żadnego wpływu na liczby.
        //
        // Bierzemy profil klubu „naszego": to jego metodyka opisuje tagi.
        'mapping_profile' => Mappings::forEngine(
            $match['club_home_id'] !== null ? (int) $match['club_home_id'] : null
        ),
        // Korekta kontrastu barw klubu należy do silnika: PHP nie może uruchomić
        // Pythona z warstwy żądań, a arytmetyki koloru nie przepisujemy.
        //
        // `xg_model` (M3): strzały BEZ xG od analityka dostają wartość z modelu
        // (`xg_source: model`); wartości ręczne mają bezwzględne pierwszeństwo.
        // Świadome włączenie: ponowny render meczu, w którym analityk zostawił
        // luki, pokaże wyższą sumę xG niż poprzedni — meta niesie wtedy
        // ostrzeżenie XG_MODEL, widoczne na ekranie pokrycia.
        'options'         => ['contrast_fix' => true, 'engine_locale' => 'pl_PL', 'xg_model' => true]
            + $opcjeIndeksu,
        // STEMPEL WERSJI w stopce raportu. NULL przy klubie bez templatu —
        // wtedy stopki nie ma i wyjście jest bajt w bajt jak przed Sesją 5.
        'template_version' => $templateVersion,
        'generated_at'     => Stats::now(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $wynik = EngineRunner::build([
        'csv'         => (string) $import['csv_path'],
        'json'        => $import['json_path'] ?: null,
        'config'      => $configPath,
        'template'    => $templatePath,
        'out_html'    => $reportPath,
        'out_meta'    => $dir . '/meta.json',
        'out_canon'   => $dir . '/canon.json',
        'out_metrics' => $dir . '/metrics.json',
    ]);

    return [
        'wynik'            => $wynik,
        'meta'             => wczytajMeta($dir . '/meta.json'),
        'template_version' => $templateVersion,
        'club_id'          => $clubIdTenanta,
        'dir'              => $dir,
    ];
}

/** Pełny render HTML wraz z artefaktami. Powstaje NOWY wiersz w `reports`. */
/**
 * @param array<string,mixed> $payload  ładunek zadania. `is_sample` MUSI tu
 *   dojść parametrem — w tej funkcji nie ma zasięgu dyspozytora, a odwołanie
 *   do nieistniejącej zmiennej dawało po cichu `false` i raport przykładowy
 *   nie był niczym oznaczony. Złapał to dopiero przelot HTTP.
 */
function wykonajRender(int $jobId, int $importId, array $import, array $payload = []): void
{
    $matchId    = (int) $import['match_id'];
    $reportPath = Storage::reportDir() . '/' . Storage::randomName('html');

    $bieg  = uruchomSilnik($jobId, $import, $reportPath);
    $wynik = $bieg['wynik'];
    $meta  = $bieg['meta'];
    $templateVersion = $bieg['template_version'];
    $clubIdTenanta   = $bieg['club_id'];

    if ($wynik['exit'] === 0 && is_file($reportPath)) {
        /*
         * TENANT RAPORTU — przepisany z meczu, nie zgadywany.
         *
         * Kolumna `reports.club_id` (migracja 012) jest denormalizacją: da się
         * dojść do klubu przez `match_id`, ale lista raportów klubu filtruje
         * właśnie po niej i nie ma dotykać drugiej tabeli. Raport zapisany bez
         * tej wartości nie wywali się na bazie — po prostu zniknie z listy
         * swojego klubu, a to gorsze niż błąd, bo nikt tego nie zauważy.
         *
         * `template_version` niesie wersję templatu użytą przy generowaniu
         * (Sesja 5). NULL znaczy „klub nie ma jeszcze templatu" — raport
         * powstał wtedy na słowniku domyślnym i profilu kreatora, dokładnie
         * jak przed tą sesją. To fakt historyczny, nie brak do uzupełnienia.
         */
        $clubId = $clubIdTenanta;

        Db::run(
            'INSERT INTO reports (match_id, club_id, html_path, params_json, engine_version,
                                  template_version, generated_at)
             VALUES (:mid, :club, :path, :params, :ver, :tplv, :now)',
            [
                'mid'    => $matchId,
                'club'   => $clubId,
                'path'   => $reportPath,
                'params' => json_encode([
                    'sections' => $meta['sections_available'] ?? [],
                    'job_id'   => $jobId,
                    /*
                     * `is_sample` — przykładowy raport z konfiguratora.
                     * Siedzi w `params_json`, a NIE we własnej kolumnie: to
                     * wyłącznie oznaczenie dla interfejsu, a nowa kolumna
                     * znaczyłaby migrację do ręcznego odpalenia na produkcji
                     * dla flagi, od której nie zależy ani jedna liczba.
                     */
                    'is_sample' => !empty($payload['is_sample']),
                ], JSON_UNESCAPED_UNICODE),
                'ver'    => $meta['engine_version'] ?? null,
                'tplv'   => $templateVersion,
                'now'    => Stats::now(),
            ]
        );

        /*
         * IDENTYFIKATOR ODCZYTUJEMY NATYCHMIAST PO WSTAWIENIU.
         *
         * Usterka z produkcji: mail prowadził do `/raport/0`. `lastInsertId()`
         * stało tu niżej, za `saveInspection()` i `UPDATE matches` — a każde
         * kolejne zapytanie wstawiające wiersz (choćby wpis w `audit_log`)
         * podmienia wartość zwracaną przez sterownik.
         *
         * To nie jest miejsce na sprytniejsze rozwiązanie: odczyt ma stać
         * bezpośrednio przy INSERT i nic nie ma prawa się między nie wcisnąć.
         */
        $reportId = (int) Db::pdo()->lastInsertId();

        if ($reportId <= 0) {
            // Raport JEST zapisany — brakuje tylko jego identyfikatora. Nie
            // przewracamy zadania, ale mówimy o tym w logu, bo powiadomienie
            // pójdzie wtedy bez odnośnika.
            error_log("run_job {$jobId}: raport zapisany, ale sterownik nie zwrócił identyfikatora");
        }

        // Pokrycie z pełnego przebiegu jest dokładniejsze niż z `inspect`
        // (tam nie było konfiguracji klubów) — nadpisujemy je.
        if ($meta !== []) {
            Imports::saveInspection($importId, $meta);
        }

        Db::run('UPDATE matches SET status = :s, half_split_ms = :hs WHERE id = :id', [
            's'  => 'done',
            'hs' => $meta['half_split_ms'] ?? $import['half_split_ms'],
            'id' => $matchId,
        ]);

        zakoncz($jobId, 0, null);
        Audit::log('report.generated', null, 'match', $matchId, ['job_id' => $jobId]);

        // DOPIERO TERAZ, po domknięciu zadania. Gdyby powiadomienie powstawało
        // wcześniej i rzuciło wyjątkiem, gotowy raport zostałby oznaczony jako
        // nieudany — czyli awaria powiadomienia skasowałaby udaną pracę.
        // `Notifications::create()` dodatkowo łyka własne błędy.
        powiadomOGotowym($matchId, $reportId);
        return;
    }

    Db::run('UPDATE matches SET status = :s WHERE id = :id', ['s' => 'failed', 'id' => $matchId]);
    $powod = opiszAwarie($wynik, $meta);
    zakoncz($jobId, $wynik['exit'], $powod);
    powiadomOAwarii($matchId, $jobId, $powod, Jobs::canRetry('failed'));
}

/**
 * Przeliczenie ISTNIEJĄCEGO raportu pod aktualny templat klubu (Sesja 7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * PODMIANA MUSI BYĆ ATOMOWA I TO JEST CAŁA ISTOTA TEJ FUNKCJI.
 *
 * Adres publiczny raportu bywa rozesłany sztabowi i zarządowi. Render trwa
 * kilkadziesiąt sekund. Gdyby silnik pisał wprost do pliku docelowego, przez
 * ten czas pod działającym linkiem leżałby plik ucięty w połowie — a przy
 * awarii silnika zostałby taki na zawsze.
 *
 * Dlatego: silnik pisze do pliku TYMCZASOWEGO obok docelowego, a na końcu
 * robimy `rename()`. Na jednym systemie plików to operacja atomowa — czytelnik
 * dostaje albo całą starą treść, albo całą nową, nigdy nic pośredniego.
 * Plik tymczasowy MUSI leżeć w tym samym katalogu: `rename()` przez granicę
 * systemów plików degraduje się do kopiowania i przestaje być atomowy.
 *
 * Kropka na początku nazwy nie jest ozdobą — plik tymczasowy ma być na pierwszy
 * rzut oka odróżnialny od raportu przy przeglądaniu katalogu.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * @param array<string,mixed> $payload  wymaga `report_id`
 */
function wykonajPrzeliczenie(int $jobId, int $importId, array $import, array $payload): void
{
    $reportId = (int) ($payload['report_id'] ?? 0);
    $report = $reportId > 0
        ? Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $reportId])
        : null;

    if ($report === null) {
        zakoncz($jobId, 4, 'Raport ' . $reportId . ' nie istnieje albo został usunięty.');
        return;
    }

    $matchId   = (int) $import['match_id'];
    $docelowy  = (string) $report['html_path'];

    /*
     * Ścieżka pochodzi z bazy, ale i tak ją sprawdzamy. Wpis `../../` w kolumnie
     * pozwoliłby nadpisać dowolny plik dostępny dla procesu roboczego, a CLI —
     * w odróżnieniu od FPM — nie ma `open_basedir`, więc nic go nie zatrzyma.
     *
     * Pytamy o KATALOG, nie o plik: przy raporcie, którego plik zniknął z dysku,
     * przeliczenie ma go odtworzyć, a nie odmówić.
     */
    if ($docelowy === '' || !Storage::dirInside($docelowy)) {
        zakoncz($jobId, 4, 'Ścieżka raportu ' . $reportId . ' leży poza magazynem — przeliczenie wstrzymane.');
        return;
    }

    $tymczasowy = dirname($docelowy) . '/.przelicz-' . Storage::randomName('html');

    $bieg  = uruchomSilnik($jobId, $import, $tymczasowy);
    $wynik = $bieg['wynik'];
    $meta  = $bieg['meta'];

    if ($wynik['exit'] !== 0 || !is_file($tymczasowy)) {
        // STARY RAPORT ZOSTAJE NIETKNIĘTY. Sprzątamy wyłącznie po sobie.
        @unlink($tymczasowy);
        $powod = opiszAwarie($wynik, $meta);
        zakoncz($jobId, $wynik['exit'], $powod);
        powiadomOAwarii($matchId, $jobId, $powod, Jobs::canRetry('failed'));
        return;
    }

    // Uprawnienia bierzemy ze starego pliku, jeśli istnieje: `rename()` zachowuje
    // je z pliku źródłowego, a ten powstał z domyślną maską procesu roboczego.
    @chmod($tymczasowy, is_file($docelowy) ? (fileperms($docelowy) & 0777) : 0640);

    if (!@rename($tymczasowy, $docelowy)) {
        @unlink($tymczasowy);
        zakoncz($jobId, 4, 'Nie udało się podmienić pliku raportu ' . $reportId . '. Poprzednia treść została nietknięta.');
        powiadomOAwarii($matchId, $jobId, 'Podmiana pliku raportu nie powiodła się.', Jobs::canRetry('failed'));
        return;
    }

    /*
     * DOPIERO TERAZ baza. Kolejność ma znaczenie: gdyby wiersz szedł pierwszy,
     * a `rename()` padł, `template_version` obiecywałby treść, której w pliku nie ma.
     *
     * `html_path` NIE JEST aktualizowane — o to w tym wszystkim chodzi. Ten sam
     * plik, ten sam wiersz, ten sam token publiczny.
     *
     * `is_sample` przepisujemy ze starego ładunku: raport przykładowy z
     * konfiguratora zostaje przykładowym także po przeliczeniu.
     */
    $stareParams = json_decode((string) ($report['params_json'] ?? ''), true);
    $stareParams = is_array($stareParams) ? $stareParams : [];

    Db::run(
        'UPDATE reports
            SET template_version = :tplv, engine_version = :ver,
                generated_at = :now, params_json = :params
          WHERE id = :id',
        [
            'tplv'   => $bieg['template_version'],
            'ver'    => $meta['engine_version'] ?? $report['engine_version'],
            'now'    => Stats::now(),
            'params' => json_encode([
                'sections'  => $meta['sections_available'] ?? [],
                'job_id'    => $jobId,
                'is_sample' => !empty($stareParams['is_sample']),
                // Ślad, że ten raport powstał z podmiany, a nie z importu.
                // Pytanie „dlaczego treść się zmieniła bez nowego raportu"
                // ma mieć odpowiedź w danych, nie tylko w pamięci operatora.
                'rebuilt_from_template' => $report['template_version'],
            ], JSON_UNESCAPED_UNICODE),
            'id'     => $reportId,
        ]
    );

    /*
     * POKRYCIE JAK PRZY IMPORCIE. Templat mógł urosnąć o sekcje, których ten
     * eksport nie obsłuży — wtedy silnik zgłasza je jako niedostępne z powodem,
     * a ekran pokrycia pokazuje to samo, co pokazałby przy świeżym imporcie.
     * To poprawny stan, nie awaria (spec Sesji 7, pkt 6).
     */
    if ($meta !== []) {
        Imports::saveInspection($importId, $meta);
    }

    // Stan meczu zostaje `done` — nie było go po co ruszać. Aktualizujemy
    // wyłącznie to, co silnik mógł policzyć inaczej niż poprzednio.
    Db::run('UPDATE matches SET half_split_ms = :hs WHERE id = :id', [
        'hs' => $meta['half_split_ms'] ?? $import['half_split_ms'],
        'id' => $matchId,
    ]);

    zakoncz($jobId, 0, null);
    Audit::log('report.rebuilt', null, 'report', $reportId, [
        'job_id'        => $jobId,
        'match_id'      => $matchId,
        'from_template' => $report['template_version'],
        'to_template'   => $bieg['template_version'],
        'batch'         => $payload['batch'] ?? null,
    ]);

    powiadomOPrzeliczeniu($matchId, $reportId, $bieg['template_version']);
}

/** Powiadomienie po udanym przeliczeniu — ten sam system chmurek co „raport gotowy". */
function powiadomOPrzeliczeniu(int $matchId, int $reportId, ?int $wersja): void
{
    $match = Matches::find($matchId);
    if ($match === null) {
        return;
    }

    Notifications::create((int) $match['owner_id'], [
        'type'  => Notifications::TYP_READY,
        'title' => 'Raport przeliczony: ' . opisMeczu($match),
        // Adres publiczny się NIE zmienił i to jest właśnie ta informacja,
        // której odbiorca potrzebuje — inaczej odruchowo wygeneruje nowy link.
        'body'  => $wersja !== null
            ? 'Treść raportu została podmieniona pod templat v' . $wersja
              . '. Dotychczasowy link publiczny działa bez zmian.'
            : 'Treść raportu została podmieniona. Dotychczasowy link publiczny działa bez zmian.',
        'entity'    => 'report',
        'entity_id' => $reportId,
        'url'       => '/raport/' . $reportId,
    ]);
}

/** Powiadomienie o gotowym raporcie — w aplikacji zawsze, mailem wedle ustawień. */
function powiadomOGotowym(int $matchId, int $reportId): void
{
    $match = Matches::find($matchId);
    if ($match === null) {
        return;
    }

    Notifications::create((int) $match['owner_id'], [
        'type'      => Notifications::TYP_READY,
        'title'     => 'Raport gotowy: ' . opisMeczu($match),
        'body'      => 'Raport został wygenerowany i jest dostępny w panelu.',
        'entity'    => 'report',
        'entity_id' => $reportId,
        'url'       => '/raport/' . $reportId,
    ]);
}

/** Powiadomienie o nieudanym przetwarzaniu, z powodem po polsku. */
function powiadomOAwarii(int $matchId, int $jobId, string $powod, bool $moznaPonowic): void
{
    $match = Matches::find($matchId);
    if ($match === null) {
        return;
    }

    Notifications::create((int) $match['owner_id'], [
        'type'      => Notifications::TYP_FAILED,
        'title'     => 'Przetwarzanie nie powiodło się: ' . opisMeczu($match),
        // Informacja „czy ponawiać" jest częścią powiadomienia, nie ozdobą:
        // bez niej odbiorca i tak musi wejść do panelu, żeby cokolwiek zrobić.
        'body'      => $powod . "\n\n" . ($moznaPonowic
            ? 'To zadanie można ponowić — plik jest zapisany, nie trzeba wgrywać go jeszcze raz.'
            : 'Tego zadania nie da się ponowić; konieczne jest ponowne wgranie eksportu.'),
        'entity'    => 'job',
        'entity_id' => $jobId,
        'url'       => '/zadania/' . $jobId,
    ]);
}

/** Opis meczu do treści powiadomienia: „Nasza drużyna — Rywal". */
function opisMeczu(array $match): string
{
    $nasza = trim((string) ($match['home_name'] ?? ''));
    $rywal = trim((string) ($match['away_name'] ?? ''));

    /*
     * ŻADNYCH SŁÓW ZASTĘPCZYCH W TEMACIE MAILA.
     *
     * Usterka z produkcji: temat brzmiał „KS WIĄZOWNICA — rywal", bo przeciwnik
     * nie był przypisany do meczu (eksport LiveTag zawierał tylko jedną nazwę
     * drużyny), a kod wstawiał w to miejsce dosłowne słowo „rywal".
     *
     * Słowo zastępcze wygląda jak nazwa i czyta się jak błąd systemu. Data
     * meczu jest informacją PRAWDZIWĄ i wystarczającą, żeby odbiorca wiedział,
     * o który mecz chodzi — a przy okazji widać, że nazwy po prostu nie mamy,
     * zamiast udawać, że mamy (CLAUDE.md §8).
     */
    $data = '';
    if (!empty($match['played_at'])) {
        $stamp = strtotime((string) $match['played_at']);
        if ($stamp !== false) {
            $data = date('d.m', $stamp);
        }
    }

    if ($nasza !== '' && $rywal !== '') {
        return $nasza . ' — ' . $rywal;
    }

    $znana = $nasza !== '' ? $nasza : $rywal;

    if ($znana !== '') {
        return $data !== ''
            ? $znana . ' — ' . View::t('mail.match.on_date', $data)
            : $znana;
    }

    return $data !== '' ? View::t('mail.match.on_date', $data) : View::t('mail.match.unknown');
}

/**
 * Wysyłka jednego powiadomienia mailem.
 *
 * OSOBNY TYP ZADANIA, nie doczepka do renderu. Serwer poczty bywa wolny i bywa
 * niedostępny; gdyby wysyłka siedziała w zadaniu renderu, awaria SMTP oznaczałaby
 * „raport się nie wygenerował", co jest nieprawdą i uruchamia niepotrzebne
 * ponawianie ciężkiej pracy silnika.
 */
function wyslijMail(int $jobId, int $notificationId): void
{
    $powiadomienie = $notificationId > 0 ? Notifications::find($notificationId) : null;

    if ($powiadomienie === null) {
        // Powiadomienie mogło zniknąć razem z użytkownikiem — to nie jest awaria.
        zakoncz($jobId, 0, null);
        return;
    }

    /*
     * Stany TRWAŁE: `sent` i `skipped`. Powtórne przejście po nich nie ma prawa
     * niczego wysłać — bez tego ponowienie zadania dublowałoby mail w skrzynce
     * albo wskrzeszało powiadomienie świadomie odwołane jako nieaktualne.
     *
     * `failed` jest inny: znaczy „skończyły się automatyczne próby". Operator,
     * który klika „ponów", prosi właśnie o kolejną — więc wracamy do `pending`
     * i próbujemy. Traktowanie `failed` jako stanu trwałego dawało przycisk,
     * który kończył się natychmiastowym „gotowe" i nie wysyłał nic.
     */
    $stanMaila = (string) $powiadomienie['mail_status'];

    if ($stanMaila === 'sent' || $stanMaila === 'skipped') {
        zakoncz($jobId, 0, null);
        return;
    }

    if ($stanMaila === 'none') {
        // Mail nie był przewidziany (brak SMTP albo wyłączony przełącznik
        // w chwili powstania). Zadanie nie ma czego wysłać.
        zakoncz($jobId, 0, null);
        return;
    }

    if ($stanMaila === 'failed') {
        // Licznik prób zerujemy: stan `failed` powstaje wyłącznie po wyczerpaniu
        // automatycznych podejść, więc trafiamy tu tylko z ręcznego ponowienia.
        // Bez zerowania operator dostawałby jedną próbę i natychmiast ten sam
        // komunikat o wyczerpaniu limitu.
        Db::run(
            "UPDATE notifications SET mail_status = 'pending', mail_attempts = 0 WHERE id = :id",
            ['id' => $notificationId]
        );
        $powiadomienie['mail_attempts'] = 0;
    }

    if (!Mailer::isConfigured()) {
        Notifications::markSkipped($notificationId, 'SMTP nie jest skonfigurowany.');
        zakoncz($jobId, 0, null);
        return;
    }

    // Sedno wymagania o dwóch minutach: sprawdzamy stan TERAZ, nie w chwili
    // zaplanowania. Jeśli raport zdążył się wygenerować, mail „przetwarzanie
    // w toku" jest już nieprawdą i nie ma prawa wyjść.
    if (!Notifications::stillRelevant($powiadomienie)) {
        Notifications::markSkipped($notificationId, 'Powód zdezaktualizował się przed wysyłką.');
        zakoncz($jobId, 0, null);
        return;
    }

    try {
        $mailer = Mailer::fromConfig();
    } catch (\Throwable $e) {
        // Błąd KONFIGURACJI, nie łączności — ponawianie nic tu nie da, bo
        // `.env` sam się nie poprawi. Typowy przypadek: literówka
        // w SMTP_ENCRYPTION. Kończymy od razu, z powodem po polsku.
        error_log("poczta {$notificationId}: zła konfiguracja — " . $e->getMessage());
        Notifications::markFailed($notificationId, $e->getMessage());
        zakoncz($jobId, 4, 'Konfiguracja poczty jest błędna: ' . $e->getMessage());
        return;
    }

    try {
        $mailer->send(
            (string) $powiadomienie['mail_to'],
            (string) $powiadomienie['title'],
            trescMaila($powiadomienie),
            trescMailaHtml($powiadomienie)
        );
    } catch (\Throwable $e) {
        // Rozmowa z serwerem trafia WYŁĄCZNIE do logu — bywa w niej adres
        // i nazwa użytkownika SMTP. W bazie zostaje sam powód, po polsku.
        error_log("poczta {$notificationId}: " . $e->getMessage() . "\n" . $mailer->transcript());
        ponowWysylke($jobId, $notificationId, (int) $powiadomienie['mail_attempts'], $e->getMessage());
        return;
    }

    Notifications::markSent($notificationId);
    zakoncz($jobId, 0, null);
}

/**
 * Mail z odnośnikiem do resetu hasła.
 *
 * TUTAJ, a nie w warstwie żądań, rozstrzyga się, czy konto istnieje —
 * warstwa żądań kolejkuje każdą prośbę, żeby odpowiedź HTTP była identyczna
 * dla adresu istniejącego i nieistniejącego (PasswordReset, CLAUDE.md §5).
 *
 * SUROWY TOKEN istnieje wyłącznie tu i w treści maila. Do bazy trafia skrót;
 * `jobs.payload_json` niesie sam adres. Dlatego nieudaną wysyłkę ponawiamy
 * z NOWYM tokenem — poprzedniego nie ma już skąd wziąć, a `issue()` i tak
 * unieważnia wcześniejsze nieużyte.
 */
function wyslijReset(int $jobId, string $email, int $proba): void
{
    $email = trim($email);
    $user = $email !== '' ? Db::one('SELECT * FROM users WHERE email = :e', ['e' => $email]) : null;

    if ($user === null || (string) ($user['status'] ?? 'active') === 'disabled') {
        // Adres spoza systemu albo konto wyłączone — prośba obsłużona: ciszą.
        // Status `done`, nie `failed`: to nie jest awaria, a treść błędu
        // zdradzałaby na liście zadań, które adresy istnieją.
        zakoncz($jobId, 0, null);
        return;
    }

    if (!Mailer::isConfigured()) {
        // Bez SMTP nie ma jak dostarczyć odnośnika. Głośno w logu — właściciel
        // konta czeka na mail, który nie wyjdzie, i ktoś musi o tym wiedzieć.
        error_log("reset hasła: SMTP nieskonfigurowany — mail dla konta {$user['id']} nie wyjdzie");
        zakoncz($jobId, 0, null);
        return;
    }

    $token = PasswordReset::issue((int) $user['id']);

    $wiadomosc = [
        'type'  => 'auth.reset',
        'title' => View::t('reset.mail.title'),
        'body'  => View::t('reset.mail.body'),
        'url'   => '/haslo/reset/' . $token,
    ];

    try {
        $mailer = Mailer::fromConfig();
        $mailer->send(
            (string) $user['email'],
            (string) $wiadomosc['title'],
            trescMaila($wiadomosc),
            trescMailaHtml($wiadomosc)
        );
    } catch (\Throwable $e) {
        error_log("reset hasła {$jobId}: " . $e->getMessage());

        $odstep = Notifications::retryDelay($proba - 1);
        if ($odstep === null) {
            zakoncz($jobId, 4, 'Nie udało się wysłać maila resetu hasła po '
                . Notifications::MAX_PROB_MAILA . ' próbach.');
            return;
        }
        Jobs::requeueLater($jobId, $odstep, sprintf(
            "Próba %d z %d nie powiodła się, kolejna za %s.",
            $proba,
            Notifications::MAX_PROB_MAILA,
            $odstep >= 60 ? intdiv($odstep, 60) . ' min' : $odstep . ' s'
        ));
        return;
    }

    Audit::log('password.reset_mail_sent', null, 'user', (int) $user['id']);
    zakoncz($jobId, 0, null);
}

/**
 * Nieudana wysyłka: zadanie WRACA DO KOLEJKI, a nie kończy się błędem.
 *
 * Raport jest już wygenerowany i zapisany — jego zadanie ma status `done`
 * i nic tutaj tego nie zmienia. Przewracanie czegokolwiek poza samą wysyłką
 * byłoby karą za awarię cudzego serwera poczty.
 *
 * Polityka ponawiania (ile prób, jakie odstępy) siedzi w `Notifications`,
 * a odłożenie zadania w `Jobs` — tutaj zostaje samo złożenie tych dwóch rzeczy.
 */
function ponowWysylke(int $jobId, int $notificationId, int $probyDotad, string $powod): void
{
    $odstep = Notifications::retryDelay($probyDotad);

    if ($odstep === null) {
        Notifications::markFailed($notificationId, $powod);
        zakoncz($jobId, 4, sprintf(
            "Nie udało się wysłać powiadomienia po %d próbach.\n\n%s",
            Notifications::MAX_PROB_MAILA,
            $powod
        ));
        error_log("poczta {$notificationId}: próby wyczerpane — {$powod}");
        return;
    }

    // Powiadomienie zostaje `pending` — inaczej kolejne podejście zobaczyłoby
    // „już obsłużone" i cicho nic nie zrobiło.
    Notifications::markRetry($notificationId, $powod);

    Jobs::requeueLater($jobId, $odstep, sprintf(
        "Próba %d z %d nie powiodła się, kolejna za %s.\n\n%s",
        $probyDotad + 1,
        Notifications::MAX_PROB_MAILA,
        $odstep >= 60 ? intdiv($odstep, 60) . ' min' : $odstep . ' s',
        $powod
    ));

    error_log(sprintf(
        'poczta %d: próba %d z %d nieudana, ponowienie za %d s — %s',
        $notificationId,
        $probyDotad + 1,
        Notifications::MAX_PROB_MAILA,
        $odstep,
        $powod
    ));
}

/** Treść maila. Zwykły tekst — bez HTML-a, bez śledzenia otwarć. */
function trescMaila(array $powiadomienie): string
{
    $linie = [(string) $powiadomienie['title'], ''];

    if (!empty($powiadomienie['body'])) {
        $linie[] = (string) $powiadomienie['body'];
        $linie[] = '';
    }

    if (!empty($powiadomienie['url'])) {
        $baza = rtrim((string) Config::get('APP_URL', ''), '/');
        $linie[] = $baza !== '' ? $baza . $powiadomienie['url'] : (string) $powiadomienie['url'];
        $linie[] = '';
    }

    $linie[] = '—';
    $linie[] = 'CoachAnalyze. Powiadomienia można wyłączyć w panelu, w ustawieniach konta.';

    return implode("\n", $linie);
}

/**
 * Wersja HTML wiadomosci.
 *
 * BEZ OBRAZKOW, BEZ SLEDZENIA OTWARC, BEZ ZASOBOW Z ZEWNATRZ. Powiadomienie ma
 * dojsc i dac sie kliknac, a nie raportowac, kto je przeczytal. Zewnetrzny zasob
 * w mailu to dodatkowo najprostszy sposob na wpadniecie do spamu.
 *
 * Style SA WPISANE W ATRYBUTY. To jedyne miejsce w projekcie, gdzie odchodzimy
 * od zmiennych CSS — arkusze i zmienne nie dzialaja w wiekszosci klientow
 * pocztowych, a Gmail usuwa nawet znacznik `<style>`. Barwy sa tu przepisane
 * z motywu jasnego i to jest swiadome powielenie, nie przeoczenie.
 */
function trescMailaHtml(array $powiadomienie): ?string
{
    $tytul = htmlspecialchars((string) $powiadomienie['title'], ENT_QUOTES, 'UTF-8');
    $tresc = !empty($powiadomienie['body'])
        ? nl2br(htmlspecialchars((string) $powiadomienie['body'], ENT_QUOTES, 'UTF-8'))
        : '';

    $baza = rtrim((string) Config::get('APP_URL', ''), '/');
    $adres = '';
    if (!empty($powiadomienie['url'])) {
        $adres = $baza !== '' ? $baza . $powiadomienie['url'] : (string) $powiadomienie['url'];
    }
    $adresHtml = htmlspecialchars($adres, ENT_QUOTES, 'UTF-8');

    /*
     * ETYKIETA PRZYCISKU ZALEZY OD TYPU POWIADOMIENIA.
     *
     * USTERKA Z PRODUKCJI: mail „import wgrany" mial przycisk „Otworz raport"
     * prowadzacy do `/zadania/13` — czyli do strony stanu, bo raportu jeszcze
     * nie bylo. Przycisk obiecywal cos, czego pod tym adresem nie ma.
     *
     * Etykieta to obietnica: ma opisywac to, co odbiorca ZOBACZY po kliknieciu,
     * a nie to, na co czeka.
     */
    $etykieta = match ((string) ($powiadomienie['type'] ?? '')) {
        'report.ready'   => View::t('mail.btn.report'),
        'import.pending' => View::t('mail.btn.progress'),
        'report.failed'  => View::t('mail.btn.details'),
        'auth.reset'     => View::t('mail.btn.reset'),
        default          => View::t('mail.btn.open'),
    };
    $etykietaHtml = htmlspecialchars($etykieta, ENT_QUOTES, 'UTF-8');

    $przycisk = $adres === '' ? '' : <<<PRZYCISK
        <p style="margin:24px 0;">
          <a href="{$adresHtml}"
             style="display:inline-block;padding:12px 22px;border-radius:8px;
                    background:#E8722C;color:#FFFFFF;text-decoration:none;
                    font-weight:600;font-size:15px;">{$etykietaHtml}</a>
        </p>
        <p style="margin:0 0 8px;font-size:12px;color:#6B7A88;">
          Jeśli przycisk nie działa, skopiuj adres:<br>
          <a href="{$adresHtml}" style="color:#B4551A;">{$adresHtml}</a>
        </p>
PRZYCISK;

    $ustawienia = $baza !== '' ? $baza . '/konto' : '/konto';
    $ustawieniaHtml = htmlspecialchars($ustawienia, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="pl">
<head><meta charset="utf-8"><title>{$tytul}</title></head>
<body style="margin:0;padding:0;background:#F3F5F7;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
         style="background:#F3F5F7;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="max-width:560px;background:#FFFFFF;border:1px solid #DCE3E9;
                    border-radius:12px;overflow:hidden;
                    font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">

        <tr><td style="padding:18px 28px;border-bottom:1px solid #DCE3E9;">
          <span style="font-size:16px;font-weight:700;color:#1B2430;">CoachAnalyze</span>
          <span style="font-size:13px;color:#6B7A88;"> · Analityka meczowa</span>
        </td></tr>

        <tr><td style="padding:28px;">
          <h1 style="margin:0 0 12px;font-size:19px;line-height:1.35;color:#1B2430;">{$tytul}</h1>
          <p style="margin:0;font-size:15px;line-height:1.55;color:#3A4753;">{$tresc}</p>
          {$przycisk}
        </td></tr>

        <tr><td style="padding:16px 28px;border-top:1px solid #DCE3E9;
                       font-size:12px;line-height:1.5;color:#6B7A88;">
          Wiadomość wysłana automatycznie przez CoachAnalyze.<br>
          Powiadomienia mailem możesz wyłączyć w panelu, w
          <a href="{$ustawieniaHtml}" style="color:#B4551A;">ustawieniach konta</a>.
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

function zakoncz(int $jobId, int $exitCode, ?string $error): void
{
    Db::run(
        'UPDATE jobs SET status = :status, exit_code = :code, error_text = :err, finished_at = :now
          WHERE id = :id',
        [
            'status' => $exitCode === 0 ? 'done' : 'failed',
            'code'   => $exitCode,
            // Kolumna to TEXT, ale wielomegabajtowy traceback w bazie nie pomaga
            // nikomu — pełna treść jest w logu.
            'err'    => $error === null ? null : mb_substr($error, 0, 8000),
            'now'    => Stats::now(),
            'id'     => $jobId,
        ]
    );
}

/** @return array<string,mixed> */
function wczytajMeta(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Komunikat dla operatora. Kolejność źródeł jest celowa: najpierw to, co silnik
 * powiedział o sobie sam w `meta.json` (kody 2 i 3), potem stderr, na końcu
 * ogólny opis kodu wyjścia.
 *
 * @param array{exit:int, stdout:string, stderr:string, timed_out:bool} $wynik
 * @param array<string,mixed> $meta
 */
function opiszAwarie(array $wynik, array $meta): string
{
    if (($meta['ok'] ?? null) === false && !empty($meta['msg'])) {
        $msg = (string) $meta['msg'];
        if (!empty($meta['missing_columns'])) {
            $msg .= "\n\nBrakujące kolumny: " . implode(', ', (array) $meta['missing_columns']);
        }
        return $msg;
    }

    if ($wynik['timed_out']) {
        return 'Przekroczony limit czasu silnika (' . Config::int('ENGINE_TIMEOUT', 180) . ' s).';
    }

    $stderr = trim($wynik['stderr']);

    // Awaria ŚRODOWISKA, nie danych. `docs/KONTRAKT_CLI.md` zna kody 0, 2, 3, 4 i 5;
    // wszystko inne znaczy, że silnik w ogóle nie ruszył — brak paczki w venv,
    // zła ścieżka PYTHON_BIN, zepsute środowisko po wdrożeniu.
    //
    // Rozróżnienie jest istotne, bo komunikat „Silnik nie odczytał pliku" kazałby
    // operatorowi szukać winy w eksporcie, którego nikt nawet nie otworzył.
    if (!in_array($wynik['exit'], [0, 2, 3, 4, 5], true)) {
        if ($stderr !== '') {
            error_log("engine — awaria środowiska:\n" . $stderr);
        }
        return 'Silnik nie uruchomił się w ogóle — to awaria konfiguracji serwera, nie problem z eksportem. '
            . 'Plik jest zapisany, wystarczy ponowić zadanie po naprawie. '
            . 'Sprawdź PYTHON_BIN i instalację pakietu w venv; szczegóły w logu.';
    }
    if ($stderr === '') {
        return 'Silnik zakończył się kodem ' . $wynik['exit'] . ' bez komunikatu.';
    }

    // Pełny traceback WYŁĄCZNIE do logu — nigdy do bazy ani do przeglądarki
    // (CLAUDE.md §5). Operatorowi pokazujemy ostatnią linię, czyli typ i treść wyjątku.
    error_log("engine stderr:\n" . $stderr);

    $linie = array_values(array_filter(array_map('trim', explode("\n", $stderr)), fn($l) => $l !== ''));
    $ostatnia = $linie === [] ? '' : (string) end($linie);

    return 'Silnik zakończył się kodem ' . $wynik['exit'] . '.'
        . ($ostatnia === '' ? '' : "\n\n" . $ostatnia)
        . "\n\nPełny ślad błędu znajduje się w logu serwera.";
}
