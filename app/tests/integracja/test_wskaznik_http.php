<?php
declare(strict_types=1);

/**
 * Wskaźnik pracy kolejki i chmurka wyniku — PRZELOT HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. Punkty końcowe stanu (`/zadania/{id}/stan`, `/partia/{hex}/stan`) są
 *    OSIĄGALNE, zwracają dane, nie są buforowane i BEZ SESJI dają 404 —
 *    nie 401 i nie przekierowanie na logowanie, bo `fetch()` wykonałby je
 *    po cichu i skrypt dostałby stronę logowania jako „odpowiedź JSON".
 * 2. Wskaźnik jest w HTML-u WYSŁANYM PRZEZ SERWER, na każdym ekranie, na
 *    którym się czeka — czyli działa bez skryptu.
 * 3. Stan końcowy renderuje SERWER: przy zadaniu w toku w dokumencie nie ma
 *    ani przycisku „Ponów", ani komunikatu błędu.
 * 4. Timeout uczciwości: po trzech minutach w kolejce pojawia się „trwa
 *    dłużej niż zwykle" i ani jednego wymyślonego procentu.
 * 5. Chmurka wyniku idzie ISTNIEJĄCYM systemem powiadomień, a partia daje
 *    JEDNĄ chmurkę zbiorczą zamiast N osobnych.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_wskaznik_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\ReportTemplates;

$root = dirname(__DIR__, 3);
$here = __DIR__;

$ok = 0;
$fail = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $ok, $fail;
    if ($cond) {
        $ok++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  BŁĄD {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$baza    = $here . '/wskaznik.sqlite';
$magazyn = $here . '/wskaznik_storage';
$logFile = $here . '/wskaznik.log';
$envFile = $here . '/.env.wskaznik';
$sock    = $here . '/wskaznik_redis.sock';
$port    = 8996;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

$python = $root . '/venv/bin/python';
putenv('PYTHONPATH=' . $root . '/engine');

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'PYTHON_BIN=' . $python, 'ENGINE_TIMEOUT=60',
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=wsk:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);

$procesy = [];
$procesy[] = proc_open(
    'php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r1
);
for ($i = 0; $i < 50 && !file_exists($sock); $i++) {
    usleep(100000);
}
$procesy[] = proc_open(
    'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r2
);
for ($i = 0; $i < 50; $i++) {
    $p = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if (is_resource($p)) { fclose($p); break; }
    usleep(100000);
}

register_shutdown_function(static function () use ($procesy, $baza, $envFile, $logFile, $sock, $magazyn): void {
    foreach ($procesy as $p) {
        if (is_resource($p)) { proc_terminate($p); proc_close($p); }
    }
    @unlink($baza); @unlink($envFile); @unlink($logFile); @unlink($sock);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

$bazaUrl = 'http://127.0.0.1:' . $port;
$ciasteczka = [];

/** @return array{status:int, location:?string, body:string, headers:list<string>} */
function http(string $method, string $path, array $opts = []): array
{
    global $bazaUrl, $ciasteczka;
    $naglowki = ['Connection: close'];
    if (!empty($opts['bez_sesji'])) {
        // Świadomie BEZ ciasteczek — tak wygląda żądanie po wygaśnięciu sesji.
    } elseif ($ciasteczka !== []) {
        $pary = [];
        foreach ($ciasteczka as $k => $v) { $pary[] = $k . '=' . $v; }
        $naglowki[] = 'Cookie: ' . implode('; ', $pary);
    }
    $tresc = null;
    if (isset($opts['form'])) {
        $tresc = http_build_query($opts['form']);
        $naglowki[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif (isset($opts['multipart'])) {
        [$tresc, $typ] = $opts['multipart'];
        $naglowki[] = 'Content-Type: ' . $typ;
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $naglowki), 'content' => $tresc,
        'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 30,
    ]]);
    $body = @file_get_contents($bazaUrl . $path, false, $ctx);
    $status = 0; $location = null; $naglowkiOdp = $http_response_header ?? [];
    foreach ($naglowkiOdp as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) { $status = (int) $m[1]; $location = null; }
        elseif (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        elseif (stripos($h, 'Set-Cookie:') === 0 && empty($opts['bez_sesji'])
            && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }
    return ['status' => $status, 'location' => $location, 'body' => (string) $body, 'headers' => $naglowkiOdp];
}

function multipart(array $pola, array $pliki): array
{
    $granica = '----ca' . bin2hex(random_bytes(8));
    $out = '';
    foreach ($pola as $n => $w) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$n}\"\r\n\r\n{$w}\r\n";
    }
    foreach ($pliki as $n => [$plik, $tresc]) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$n}\"; filename=\"{$plik}\"\r\n"
              . "Content-Type: text/csv\r\n\r\n{$tresc}\r\n";
    }
    $out .= "--{$granica}--\r\n";
    return [$out, 'multipart/form-data; boundary=' . $granica];
}

function cron(): int
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile)
        . ' PYTHONPATH=' . escapeshellarg($root . '/engine')
        . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1', $o, $kod);
    return $kod;
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

function maNaglowek(array $odp, string $fragment): bool
{
    foreach ($odp['headers'] as $h) {
        if (stripos($h, $fragment) !== false) { return true; }
    }
    return false;
}

$CSV = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n";

$CSV_ZEPSUTY = "cokolwiek,zupelnie,innego\n1,2,3\n";

// ============================================================ A. bez sesji
echo "== A. punkty końcowe bez sesji: 404, nigdy 401 ani 302 ==\n";

$bezSesji = http('GET', '/zadania/1/stan', ['bez_sesji' => true]);
check('stan zadania bez sesji daje 404', $bezSesji['status'] === 404,
    'status ' . $bezSesji['status'] . ' → ' . (string) $bezSesji['location']);
check('bez sesji NIE przekierowuje na logowanie', $bezSesji['location'] === null,
    'fetch wykonałby przekierowanie po cichu i dostałby HTML zamiast JSON-a');

$bezSesjiP = http('GET', '/partia/0123456789abcdef/stan', ['bez_sesji' => true]);
check('stan partii bez sesji daje 404', $bezSesjiP['status'] === 404);
check('stan partii bez sesji nie przekierowuje', $bezSesjiP['location'] === null);

// ============================================================ B. przygotowanie
echo "\n== B. klub z templatem, import i zadanie w kolejce ==\n";

$konfig = [
    'schema_version'   => 1,
    'team_us_rule'     => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'mapy'],
    'variables' => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
    ],
];
check('templat v1 zapisany', ReportTemplates::saveNewVersion(1, $konfig, 1) === 1);

$login = http('GET', '/login');
$zal = http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]]);
check('zalogowano', $zal['status'] === 302);

$formularz = http('GET', '/klub/1/import');
$upload = http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => csrfZ($formularz['body'])], ['csv' => ['mecz1.csv', $CSV]]
)]);
check('upload przyjęty', $upload['status'] === 302);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$importId = (int) $import['id'];
$matchId  = (int) $import['match_id'];
$jobId = (int) Db::one("SELECT id FROM jobs WHERE type = 'inspect' ORDER BY id DESC LIMIT 1")['id'];

// ============================================================ C. wskaźnik z serwera
echo "\n== C. wskaźnik jest w HTML-u z serwera (działa bez skryptu) ==\n";

$ekran = http('GET', '/zadania/' . $jobId);
check('ekran zadania odpowiada', $ekran['status'] === 200);
check('wskaźnik wyrenderowany przez serwer', str_contains($ekran['body'], 'class="wskaznik'));
check('etapy są w dokumencie, nie budowane skryptem',
    str_contains($ekran['body'], 'W kolejce')
    && str_contains($ekran['body'], 'Przetwarzanie')
    && str_contains($ekran['body'], 'Gotowe'));
check('wskaźnik niesie adres punktu stanu',
    str_contains($ekran['body'], 'data-zadanie-punkt="/zadania/' . $jobId . '/stan"'));
check('czas od zgłoszenia jest w dokumencie',
    preg_match('#data-rola="czas">\d+:\d\d<#', $ekran['body']) === 1);

/*
 * ZERO WYMYŚLONYCH PROCENTÓW. Postępu renderu nie znamy i nie udajemy,
 * że znamy — etapy są dyskretne i każdy z nich jest prawdziwy.
 */
check('wskaźnik nie pokazuje żadnego procentu',
    preg_match('#<progress|\d+%#', substr(
        $ekran['body'],
        (int) strpos($ekran['body'], 'class="wskaznik'),
        1200
    )) !== 1);

echo "\n== stan końcowy renderuje serwer, nie skrypt ==\n";
check('przy zadaniu w toku NIE MA przycisku Ponów', !str_contains($ekran['body'], 'Ponów'),
    'akcja obecna w dokumencie jest obecna, choćby była ukryta');
check('przy zadaniu w toku nie ma komunikatu błędu',
    !str_contains($ekran['body'], 'wskaznik__blad'));

// ============================================================ D. punkt stanu
echo "\n== D. punkt końcowy stanu zadania ==\n";

$stan = http('GET', '/zadania/' . $jobId . '/stan');
check('punkt stanu odpowiada 200', $stan['status'] === 200);
check('odpowiada JSON-em', maNaglowek($stan, 'Content-Type: application/json'));
check('odpowiedź nie jest buforowana', maNaglowek($stan, 'Cache-Control: no-store'));

$dane = json_decode($stan['body'], true);
check('treść daje się rozebrać', is_array($dane), $stan['body']);
check('niesie etap', ($dane['stage'] ?? null) === 'queued', var_export($dane['stage'] ?? null, true));
check('niesie czas od zgłoszenia', isset($dane['elapsed']) && is_int($dane['elapsed']));
check('niesie started_at i finished_at',
    array_key_exists('started_at', $dane) && array_key_exists('finished_at', $dane));
check('niesie pole błędu', array_key_exists('error', $dane));
check('świeże zadanie nie jest oznaczone jako wolne', ($dane['slow'] ?? null) === false);

$brak = http('GET', '/zadania/999999/stan');
check('nieistniejące zadanie daje 404', $brak['status'] === 404,
    'skrypt ma przestać pytać, a nie wracać co cztery sekundy');

// ============================================================ E. timeout uczciwości
echo "\n== E. timeout uczciwości: „trwa dłużej niż zwykle” ==\n";

// Cofamy chwilę zgłoszenia o ponad trzy minuty — tak wygląda zapchana kolejka.
Db::run("UPDATE jobs SET created_at = :kiedy WHERE id = :id", [
    'kiedy' => (new DateTimeImmutable('-6 minutes'))->format('Y-m-d H:i:s'),
    'id'    => $jobId,
]);

$stanWolny = json_decode(http('GET', '/zadania/' . $jobId . '/stan')['body'], true);
check('punkt stanu oznacza zadanie jako wolne', ($stanWolny['slow'] ?? null) === true);
check('czas przekracza próg', (int) ($stanWolny['elapsed'] ?? 0) > 180);

$ekranWolny = http('GET', '/zadania/' . $jobId);
check('ekran mówi wprost, że trwa dłużej niż zwykle',
    str_contains($ekranWolny['body'], 'Trwa dłużej niż zwykle'));
check('ekran tłumaczy, czemu tak bywa (kolejka co minutę)',
    str_contains($ekranWolny['body'], 'co minutę'));
check('uwaga NIE jest ukryta, gdy próg minął',
    preg_match('#data-rola="uwaga"\s+hidden#', $ekranWolny['body']) !== 1);

// ============================================================ F. gotowe + chmurka
echo "\n== F. zadanie kończy się, chmurka wyniku idzie istniejącym systemem ==\n";

check('cron wykonał inspekcję', cron() === 0);

$stanPo = json_decode(http('GET', '/zadania/' . $jobId . '/stan')['body'], true);
check('etap to Gotowe', ($stanPo['stage'] ?? null) === 'done');
check('gotowe zadanie ma adres wyniku',
    ($stanPo['result_url'] ?? null) === '/import/' . $importId,
    var_export($stanPo['result_url'] ?? null, true));
check('gotowe zadanie nie jest już oznaczone jako wolne', ($stanPo['slow'] ?? null) === false);

// Pełna ścieżka do raportu — po niej powstaje chmurka „Raport gotowy".
$meta = http('GET', '/import/' . $importId . '/meta');
http('POST', '/import/' . $importId . '/meta', ['form' => [
    'csrf' => csrfZ($meta['body']), 'nowy_rywal' => 'GKS Testowy', 'played_at' => '2026-09-07',
]]);
$diff = http('GET', '/import/' . $importId . '/diff');
if ($diff['status'] === 200) {
    preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $diff['body'], $mm);
    $decyzje = [];
    foreach (array_unique($mm[1]) as $k) { $decyzje[$k] = \CoachAnalyze\TemplateDiff::POMIN; }
    http('POST', '/import/' . $importId . '/diff', ['form' => [
        'csrf' => csrfZ($diff['body']), 'decyzja' => $decyzje,
    ]]);
}
$pokrycie = http('GET', '/import/' . $importId);
http('POST', '/import/' . $importId . '/generuj', ['form' => ['csrf' => csrfZ($pokrycie['body'])]]);
check('cron wygenerował raport', cron() === 0);

ca_test_db($baza);
$raportId = (int) Db::one('SELECT id FROM reports ORDER BY id DESC LIMIT 1')['id'];

$feed = http('GET', '/powiadomienia/nowe');
check('feed chmurek odpowiada', $feed['status'] === 200);
$chmurki = json_decode($feed['body'], true);
check('chmurka o gotowym raporcie jest w feedzie ISTNIEJĄCEGO systemu',
    is_array($chmurki) && !empty(array_filter(
        $chmurki['items'] ?? [],
        static fn(array $i) => str_contains((string) $i['title'], 'Raport gotowy')
    )));
check('chmurka prowadzi wprost do raportu',
    !empty(array_filter(
        $chmurki['items'] ?? [],
        static fn(array $i) => $i['url'] === '/raport/' . $raportId
    )));

// Chmurki renderuje też SERWER — na dowolnym widoku panelu, bez skryptu.
$pulpit = http('GET', '/pulpit');
check('chmurka jest w HTML-u dowolnego widoku panelu',
    str_contains($pulpit['body'], 'Raport gotowy'),
    'lekki globalny polling ma czym uzupełniać wariant serwerowy');
check('obszar chmurek stoi w layoucie', str_contains($pulpit['body'], 'id="chmurki"'));

// ============================================================ G. partia
echo "\n== G. partia: licznik X/N i JEDNA chmurka zbiorcza ==\n";

// Drugi mecz, żeby partia miała dwie pozycje.
$formularz2 = http('GET', '/klub/1/import');
http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => csrfZ($formularz2['body'])], ['csv' => ['mecz2.csv', $CSV]]
)]);
cron();
ca_test_db($baza);
$import2 = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$import2Id = (int) $import2['id'];
$meta2 = http('GET', '/import/' . $import2Id . '/meta');
http('POST', '/import/' . $import2Id . '/meta', ['form' => [
    'csrf' => csrfZ($meta2['body']), 'nowy_rywal' => 'GKS Drugi', 'played_at' => '2026-09-14',
]]);
$diff2 = http('GET', '/import/' . $import2Id . '/diff');
if ($diff2['status'] === 200) {
    preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $diff2['body'], $mm2);
    $dec2 = [];
    foreach (array_unique($mm2[1]) as $k) { $dec2[$k] = \CoachAnalyze\TemplateDiff::POMIN; }
    http('POST', '/import/' . $import2Id . '/diff', ['form' => [
        'csrf' => csrfZ($diff2['body']), 'decyzja' => $dec2,
    ]]);
}
$pokrycie2 = http('GET', '/import/' . $import2Id);
http('POST', '/import/' . $import2Id . '/generuj', ['form' => ['csrf' => csrfZ($pokrycie2['body'])]]);
cron();

// Nowa wersja templatu czyni OBA raporty nieaktualnymi.
ca_test_db($baza);
$konfig2 = $konfig;
$konfig2['sections_enabled'][] = 'duels';
check('templat v2 zapisany', ReportTemplates::saveNewVersion(1, $konfig2, 1) === 2);

// Oznaczamy wszystkie chmurki jako odczytane, żeby policzyć TYLKO te z partii.
Db::run('UPDATE notifications SET read_at = :t WHERE read_at IS NULL',
    ['t' => \CoachAnalyze\Stats::now()]);

$zbiorczy = http('GET', '/klub/1/przelicz');
$start = http('POST', '/klub/1/przelicz', ['form' => ['csrf' => csrfZ($zbiorczy['body'])]]);
check('akcja zbiorcza przyjęta', $start['status'] === 302, (string) $start['location']);

preg_match('/partia=([0-9a-f]{16})/', (string) $start['location'], $mb);
$partia = $mb[1] ?? '';
check('adres niesie identyfikator partii', $partia !== '');

$stanPartii = http('GET', '/partia/' . $partia . '/stan');
check('punkt stanu partii odpowiada', $stanPartii['status'] === 200);
check('stan partii jest JSON-em', maNaglowek($stanPartii, 'Content-Type: application/json'));

$p = json_decode($stanPartii['body'], true);
check('partia zna liczbę pozycji', (int) ($p['total'] ?? 0) === 2, var_export($p['total'] ?? null, true));
check('nic jeszcze nie jest gotowe', (int) ($p['done'] ?? -1) === 0);
check('partia jeszcze trwa', ($p['finished'] ?? null) === false);
check('lista błędów jest tablicą', is_array($p['errors'] ?? null));

// Psujemy eksport drugiego meczu — jeden ma przejść, drugi nie.
$csv2 = (string) Db::one('SELECT csv_path FROM imports WHERE match_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => (int) $import2['match_id']])['csv_path'];
file_put_contents($csv2, $CSV_ZEPSUTY);

check('cron przerobił partię', cron() === 0);

$pKoniec = json_decode(http('GET', '/partia/' . $partia . '/stan')['body'], true);
check('partia domknięta', ($pKoniec['finished'] ?? null) === true);
check('jedna pozycja gotowa', (int) ($pKoniec['done'] ?? -1) === 1);
check('jedna pozycja nieudana', (int) ($pKoniec['failed'] ?? -1) === 1);
check('błąd jest przypisany do meczu, z powodem',
    count($pKoniec['errors'] ?? []) === 1
    && !empty($pKoniec['errors'][0]['label'])
    && str_contains((string) $pKoniec['errors'][0]['error'], 'brak kolumn'),
    json_encode($pKoniec['errors'] ?? []));

echo "\n== JEDNA chmurka na partię, nie N ==\n";

ca_test_db($baza);
$nowe = Db::all('SELECT title, url, type FROM notifications WHERE read_at IS NULL ORDER BY id');
check('partia dała DOKŁADNIE JEDNĄ chmurkę', count($nowe) === 1,
    'jest ' . count($nowe) . ': ' . json_encode(array_column($nowe, 'title'), JSON_UNESCAPED_UNICODE));
check('chmurka podsumowuje wynik partii',
    count($nowe) === 1 && str_contains((string) $nowe[0]['title'], 'Przeliczono'),
    count($nowe) === 1 ? (string) $nowe[0]['title'] : '');
check('chmurka oznaczona jako niepowodzenie, bo jedna pozycja padła',
    count($nowe) === 1 && (string) $nowe[0]['type'] === 'report.failed');
check('chmurka prowadzi do listy partii z błędami',
    count($nowe) === 1 && str_contains((string) $nowe[0]['url'], '/klub/1/przelicz?partia=' . $partia),
    count($nowe) === 1 ? (string) $nowe[0]['url'] : '');

echo "\n== licznik X/N na ekranie partii ==\n";

$ekranPartii = http('GET', '/klub/1/przelicz?partia=' . $partia);
check('ekran partii odpowiada', $ekranPartii['status'] === 200);
check('licznik gotowych jest osobnym elementem do podmiany',
    preg_match('#data-rola="gotowe">\s*1\s*<#', $ekranPartii['body']) === 1);
check('licznik nieudanych też', preg_match('#data-rola="nieudane">\s*1\s*<#', $ekranPartii['body']) === 1);
check('ekran niesie adres punktu stanu partii',
    str_contains($ekranPartii['body'], 'data-partia-punkt="/partia/' . $partia . '/stan"'));
check('zamknięta partia nie każe skryptowi odpytywać',
    str_contains($ekranPartii['body'], 'data-partia-trwa="0"'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
