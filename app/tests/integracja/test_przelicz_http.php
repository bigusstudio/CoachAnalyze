<?php
declare(strict_types=1);

/**
 * Regeneracja raportów pod aktualny templat — PEŁNA ŚCIEŻKA przez HTTP (Sesja 7).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. Badge nieaktualności pokazuje OBIE wersje i prowadzi do akcji „Przelicz".
 * 2. Podmiana jest ATOMOWA: zadanie przewrócone w połowie NIE zostawia
 *    uciętego HTML-a pod działającym adresem publicznym. To jest jedyne
 *    wymaganie tej sesji, którego nie da się sprawdzić okiem na produkcji —
 *    bo objaw pojawia się tylko wtedy, gdy coś padnie.
 * 3. Adres publiczny przeżywa przeliczenie: ten sam token, nowa treść.
 * 4. Raport bez surowych plików jest ZABLOKOWANY z powodem, a nie kolejkowany
 *    po to, żeby paść minutę później.
 * 5. Zbiorcze przeliczenie nie zatrzymuje się na pierwszym błędzie.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_przelicz_http.php
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

$baza    = $here . '/przelicz.sqlite';
$magazyn = $here . '/przelicz_storage';
$logFile = $here . '/przelicz.log';
$envFile = $here . '/.env.przelicz';
$sock    = $here . '/przelicz_redis.sock';
$port    = 8991;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=przelicz:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 = tenant „Klub A" (club_key HUT7K2QX)

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

/** @return array{status:int, location:?string, body:string} */
function http(string $method, string $path, array $opts = []): array
{
    global $bazaUrl, $ciasteczka;
    $naglowki = ['Connection: close'];
    if ($ciasteczka !== []) {
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
    $status = 0; $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) { $status = (int) $m[1]; $location = null; }
        elseif (stripos($h, 'Location:') === 0) { $location = trim(substr($h, 9)); }
        elseif (stripos($h, 'Set-Cookie:') === 0
            && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }
    return ['status' => $status, 'location' => $location, 'body' => (string) $body];
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

/** Pliki tymczasowe podmiany — nie wolno im przeżyć żadnego zadania. */
function smieciPodmiany(string $magazyn): array
{
    return array_values(array_filter(
        glob($magazyn . '/reports/.przelicz-*') ?: [],
        'is_file'
    ));
}

/**
 * Pełna ścieżka od wgrania eksportu do gotowego raportu.
 *
 * Wydzielona, bo zestaw potrzebuje DWÓCH meczów (zbiorcze przeliczenie bez
 * drugiego meczu nie różniłoby się od pojedynczego), a przejście jest długie:
 * upload → inspekcja → meta → diff → generowanie.
 *
 * @return array{import:int, match:int, report:int}
 */
function zrobRaport(string $nazwaPliku, string $csv, string $rywal, string $data): array
{
    $formularz = http('GET', '/klub/1/import');
    $upload = http('POST', '/klub/1/import', ['multipart' => multipart(
        ['csrf' => csrfZ($formularz['body'])], ['csv' => [$nazwaPliku, $csv]]
    )]);
    if ($upload['status'] !== 302) {
        throw new RuntimeException('upload odrzucony: ' . $upload['status']);
    }
    cron();

    $import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
    $importId = (int) $import['id'];
    $matchId  = (int) $import['match_id'];

    $meta = http('GET', '/import/' . $importId . '/meta');
    http('POST', '/import/' . $importId . '/meta', ['form' => [
        'csrf' => csrfZ($meta['body']),
        'nowy_rywal' => $rywal,
        'played_at'  => $data,
        'is_home'    => '1',
    ]]);

    // Wszystkie nowe pozycje pomijamy — ten zestaw bada regenerację, nie diff.
    $diff = http('GET', '/import/' . $importId . '/diff');
    if ($diff['status'] === 200) {
        preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $diff['body'], $mm);
        $decyzje = [];
        foreach (array_unique($mm[1]) as $k) {
            $decyzje[$k] = \CoachAnalyze\TemplateDiff::POMIN;
        }
        http('POST', '/import/' . $importId . '/diff', ['form' => [
            'csrf' => csrfZ($diff['body']), 'decyzja' => $decyzje,
        ]]);
    }

    $pokrycie = http('GET', '/import/' . $importId);
    http('POST', '/import/' . $importId . '/generuj', ['form' => ['csrf' => csrfZ($pokrycie['body'])]]);
    cron();

    $raport = Db::one('SELECT id FROM reports WHERE match_id = :m ORDER BY id DESC LIMIT 1',
        ['m' => $matchId]);
    if ($raport === null) {
        throw new RuntimeException('raport nie powstał dla meczu ' . $matchId);
    }

    return ['import' => $importId, 'match' => $matchId, 'report' => (int) $raport['id']];
}

$CSV = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n"
     . "STRZAŁ,50,60,KLUB A,NIECELNY,\"X 0,2\",70,25\n";

// CSV bez wymaganych kolumn — silnik kończy kodem 3 i NIE zapisuje HTML-a.
// Tym symulujemy pad zadania w trakcie renderu.
$CSV_ZEPSUTY = "cokolwiek,zupelnie,innego\n1,2,3\n";

// ============================================================ A. przygotowanie
echo "== A. klub z templatem v1 i gotowym raportem ==\n";

$konfigV1 = [
    'schema_version'   => 1,
    'team_us_rule'     => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'mapy'],
    'variables' => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
    ],
];
check('templat v1 zapisany', ReportTemplates::saveNewVersion(1, $konfigV1, 1) === 1);

$login = http('GET', '/login');
$zal = http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]]);
check('zalogowano', $zal['status'] === 302);

ca_test_db($baza);
$m1 = zrobRaport('mecz1.csv', $CSV, 'GKS Pierwszy', '2026-09-07');
ca_test_db($baza);

$raport1 = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $m1['report']]);
check('raport powstał na templacie v1',
    $raport1 !== null && (int) $raport1['template_version'] === 1,
    'template_version: ' . var_export($raport1['template_version'] ?? null, true));

$sciezkaHtml = (string) $raport1['html_path'];
check('plik raportu istnieje', is_file($sciezkaHtml));

// ---- link publiczny: to on ma przeżyć całą resztę zestawu
$udost = http('GET', '/raport/' . $m1['report'] . '/udostepnij');
http('POST', '/raport/' . $m1['report'] . '/udostepnij', ['form' => ['csrf' => csrfZ($udost['body'])]]);

ca_test_db($baza);
$link = Db::one('SELECT * FROM share_links WHERE report_id = :r ORDER BY id DESC LIMIT 1',
    ['r' => $m1['report']]);
check('link publiczny utworzony', $link !== null);

$adresPubliczny = '/r/HUT7K2QX/' . (string) $link['token'];
$publiczny = http('GET', $adresPubliczny);
check('link publiczny działa przed przeliczeniem', $publiczny['status'] === 200,
    'status ' . $publiczny['status']);

$trescV1 = $publiczny['body'];
check('raport publiczny niesie stempel templatu v1', str_contains($trescV1, 'templat v1'));

// ============================================================ B. badge i akcja
echo "\n== B. templat v2 → badge nieaktualności i akcja Przelicz ==\n";

$konfigV2 = $konfigV1;
$konfigV2['variables'][] = ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRATA'],
    'canon' => 'loss', 'display_label' => 'Straty', 'color' => '#8899AA',
    'sections' => ['bilans'], 'visible' => true];
check('templat v2 zapisany', ReportTemplates::saveNewVersion(1, $konfigV2, 1) === 2);

$biblioteka = http('GET', '/klub/1/raporty');
check('biblioteka klubu odpowiada', $biblioteka['status'] === 200);
check('badge podaje OBIE wersje: raportu i klubu',
    str_contains($biblioteka['body'], 'wygenerowano z templatem v1 (aktualny v2)'),
    'sam numer wersji nie mówi, czy jest co robić');
check('przy nieaktualnym raporcie jest akcja Przelicz',
    str_contains($biblioteka['body'], '/raport/' . $m1['report'] . '/przelicz'));

$hub = http('GET', '/klub/1');
check('hub klubu prowadzi na ekran przeliczania',
    str_contains($hub['body'], '/klub/1/przelicz'));
/*
 * LICZBA NA KAFELKU OBEJMUJE TAKŻE RAPORT NIE DO PRZELICZENIA.
 *
 * W danych przykładowych stoi raport sprzed ery templatów, bez żadnego wiersza
 * w `imports`. Jest nieaktualny (klub ma już templat) i zablokowany zarazem —
 * i ma być policzony. Kafelek odpowiada na pytanie „ile raportów nie stoi na
 * aktualnym templacie", nie „ile da się kliknąć": raport, którego nie da się
 * przeliczyć, jest właśnie tym, o którym operator ma się dowiedzieć.
 *
 * Liczbę POZYCJI DO KOLEJKI pilnuje osobno przycisk akcji zbiorczej niżej.
 */
check('hub klubu podaje liczbę raportów poza aktualnym templatem',
    str_contains($hub['body'], 'Raportów do przeliczenia: 2'),
    'nasz raport v1 + raport przykładowy sprzed templatów');

$zbiorczy = http('GET', '/klub/1/przelicz');
check('ekran zbiorczy odpowiada', $zbiorczy['status'] === 200);
check('ekran zbiorczy proponuje przeliczenie wszystkich',
    str_contains($zbiorczy['body'], 'Przelicz wszystkie nieaktualne (1)'));

// ============================================================ C. atomowość
echo "\n== C. pad zadania w trakcie → stary HTML nadal serwowany ==\n";

/*
 * Kolejkujemy przeliczenie, a DOPIERO POTEM psujemy eksport. Tak wygląda
 * prawdziwa awaria: zadanie stoi w kolejce, a między jego przyjęciem
 * a wykonaniem coś się rozjeżdża. Gdybyśmy zepsuli plik wcześniej, akcja
 * zostałaby odrzucona przy przycisku i nie sprawdzilibyśmy niczego o podmianie.
 */
$przelicz = http('POST', '/raport/' . $m1['report'] . '/przelicz', [
    'form' => ['csrf' => csrfZ($biblioteka['body']), 'powrot' => '/klub/1/raporty'],
]);
check('przeliczenie przyjęte i prowadzi na zadanie',
    $przelicz['status'] === 302 && preg_match('#^/zadania/(\d+)$#', (string) $przelicz['location']) === 1,
    (string) $przelicz['location']);

ca_test_db($baza);
$csvSciezka = (string) Db::one('SELECT csv_path FROM imports WHERE id = :i',
    ['i' => $m1['import']])['csv_path'];
$csvKopia = file_get_contents($csvSciezka);
file_put_contents($csvSciezka, $CSV_ZEPSUTY);

check('cron przerobił zadanie', cron() === 0);

ca_test_db($baza);
$zadanie = Db::one("SELECT * FROM jobs WHERE type = 'rebuild_report' ORDER BY id DESC LIMIT 1");
check('zadanie przeliczenia zakończone błędem',
    $zadanie !== null && (string) $zadanie['status'] === 'failed',
    'status: ' . var_export($zadanie['status'] ?? null, true));

$poAwarii = http('GET', $adresPubliczny);
check('link publiczny NADAL odpowiada 200 po nieudanym przeliczeniu',
    $poAwarii['status'] === 200, 'status ' . $poAwarii['status']);
check('treść pod linkiem jest STARA, bajt w bajt',
    $poAwarii['body'] === $trescV1,
    'podmiana nieatomowa zostawiłaby plik ucięty albo pusty');

check('po nieudanym zadaniu nie ma pliku tymczasowego',
    smieciPodmiany($magazyn) === [],
    'znalezione: ' . implode(', ', smieciPodmiany($magazyn)));

$raportPoAwarii = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $m1['report']]);
check('wersja templatu na raporcie NIE zmieniła się po awarii',
    (int) $raportPoAwarii['template_version'] === 1);
check('ścieżka pliku raportu nietknięta',
    (string) $raportPoAwarii['html_path'] === $sciezkaHtml);

$mecz = Db::one('SELECT status FROM matches WHERE id = :m', ['m' => $m1['match']]);
check('mecz z gotowym raportem NIE został oznaczony jako nieudany',
    (string) $mecz['status'] === 'done',
    'status meczu: ' . (string) $mecz['status']);

// ============================================================ D. udana podmiana
echo "\n== D. udane przeliczenie: ten sam token, nowa treść ==\n";

file_put_contents($csvSciezka, $csvKopia);

$biblioteka = http('GET', '/klub/1/raporty');
$przelicz = http('POST', '/raport/' . $m1['report'] . '/przelicz', [
    'form' => ['csrf' => csrfZ($biblioteka['body']), 'powrot' => '/klub/1/raporty'],
]);
check('drugie przeliczenie przyjęte', $przelicz['status'] === 302);
check('cron przerobił zadanie', cron() === 0);

ca_test_db($baza);
$raport2 = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $m1['report']]);
check('STEMPEL: raport niesie teraz templat v2',
    (int) $raport2['template_version'] === 2,
    'template_version: ' . var_export($raport2['template_version'], true));
check('to CIĄGLE TEN SAM wiersz raportu i ten sam plik',
    (string) $raport2['html_path'] === $sciezkaHtml);
check('nie powstał drugi raport dla tego meczu',
    (int) Db::one('SELECT COUNT(*) AS ile FROM reports WHERE match_id = :m',
        ['m' => $m1['match']])['ile'] === 1);
check('znacznik czasu zaktualizowany',
    (string) $raport2['generated_at'] >= (string) $raport1['generated_at']);

$poPodmianie = http('GET', $adresPubliczny);
check('TEN SAM adres publiczny nadal działa', $poPodmianie['status'] === 200);
check('treść pod tym samym adresem jest NOWA', $poPodmianie['body'] !== $trescV1);
check('nowa treść niesie stempel templatu v2',
    str_contains($poPodmianie['body'], 'templat v2'));
check('po udanym zadaniu też nie ma pliku tymczasowego',
    smieciPodmiany($magazyn) === []);

$linkPoPodmianie = Db::one('SELECT token FROM share_links WHERE id = :i', ['i' => (int) $link['id']]);
check('token publiczny niezmieniony',
    (string) $linkPoPodmianie['token'] === (string) $link['token']);

// Pokrycie po regeneracji liczone jak przy imporcie.
$importPo = Db::one('SELECT * FROM imports WHERE id = :i', ['i' => $m1['import']]);
check('pokrycie odświeżone przy regeneracji',
    !empty($importPo['coverage_json']) && !empty($importPo['engine_version']));

$biblioteka = http('GET', '/klub/1/raporty');
check('badge po przeliczeniu nie mówi już o nieaktualności',
    !str_contains($biblioteka['body'], 'wygenerowano z templatem v1 (aktualny v2)'));
check('chmurka o przeliczeniu powstała',
    Db::one("SELECT COUNT(*) AS ile FROM notifications WHERE title LIKE 'Raport przeliczony%'")['ile'] > 0);

// ============================================================ E. brak plików
echo "\n== E. raport bez surowych plików: akcja zablokowana + ponowne wgranie ==\n";

$konfigV3 = $konfigV2;
$konfigV3['sections_enabled'][] = 'duels';
check('templat v3 zapisany', ReportTemplates::saveNewVersion(1, $konfigV3, 1) === 3);

// Eksport znika z dysku — dokładnie to, co robi niepełny restore magazynu.
unlink($csvSciezka);

$biblioteka = http('GET', '/klub/1/raporty');
check('lista mówi WPROST, że brakuje surowych plików',
    str_contains($biblioteka['body'], 'brak surowych plików'));
check('lista prowadzi do ponownego wgrania w widoku meczu',
    str_contains($biblioteka['body'], '/mecze/' . $m1['match'] . '/wgraj'));
check('przy zablokowanym raporcie nie ma przycisku Przelicz',
    !str_contains($biblioteka['body'], '/raport/' . $m1['report'] . '/przelicz'));

$ileZadanPrzed = (int) Db::one("SELECT COUNT(*) AS ile FROM jobs WHERE type = 'rebuild_report'")['ile'];
$odmowa = http('POST', '/raport/' . $m1['report'] . '/przelicz', [
    'form' => ['csrf' => csrfZ($biblioteka['body']), 'powrot' => '/klub/1/raporty'],
]);
check('POST wprost pod adres też jest odrzucony',
    $odmowa['status'] === 302 && $odmowa['location'] === '/klub/1/raporty',
    (string) $odmowa['location']);
ca_test_db($baza);
check('żadne zadanie skazane na porażkę nie trafiło do kolejki',
    (int) Db::one("SELECT COUNT(*) AS ile FROM jobs WHERE type = 'rebuild_report'")['ile'] === $ileZadanPrzed);

$powrot = http('GET', '/klub/1/raporty');
check('powód odmowy widać na ekranie',
    str_contains($powrot['body'], 'brakuje surowych plików'));

// ---- ponowne wgranie do ISTNIEJĄCEGO meczu
$ileMeczyPrzed = (int) Db::one('SELECT COUNT(*) AS ile FROM matches')['ile'];

$wgraj = http('GET', '/mecze/' . $m1['match'] . '/wgraj');
check('ekran ponownego wgrania odpowiada', $wgraj['status'] === 200);
check('ekran mówi, że plik trafi do TEGO meczu',
    str_contains($wgraj['body'], 'nie powstanie nowy'));

$wynikWgrania = http('POST', '/mecze/' . $m1['match'] . '/wgraj', ['multipart' => multipart(
    ['csrf' => csrfZ($wgraj['body'])], ['csv' => ['mecz1-ponownie.csv', $CSV]]
)]);
check('ponowne wgranie przyjęte', $wynikWgrania['status'] === 302, (string) $wynikWgrania['location']);
check('cron policzył pokrycie', cron() === 0);

ca_test_db($baza);
check('NIE powstał nowy mecz',
    (int) Db::one('SELECT COUNT(*) AS ile FROM matches')['ile'] === $ileMeczyPrzed);
check('powstał nowy wiersz w imports dla tego samego meczu',
    (int) Db::one('SELECT COUNT(*) AS ile FROM imports WHERE match_id = :m',
        ['m' => $m1['match']])['ile'] === 2);

$biblioteka = http('GET', '/klub/1/raporty');
check('po ponownym wgraniu akcja Przelicz wraca',
    str_contains($biblioteka['body'], '/raport/' . $m1['report'] . '/przelicz'));

// ============================================================ F. zbiorczo
echo "\n== F. przeliczenie zbiorcze: błąd jednego nie zatrzymuje reszty ==\n";

$m2 = zrobRaport('mecz2.csv', $CSV, 'GKS Drugi', '2026-09-14');
ca_test_db($baza);

// Mecz 2 powstał już na v3, więc podbijamy templat jeszcze raz — dopiero wtedy
// OBA raporty są nieaktualne i akcja zbiorcza ma na czym pokazać, że działa.
$konfigV4 = $konfigV3;
$konfigV4['sections_enabled'][] = 'noteam';
check('templat v4 zapisany', ReportTemplates::saveNewVersion(1, $konfigV4, 1) === 4);

$zbiorczy = http('GET', '/klub/1/przelicz');
check('ekran zbiorczy widzi dwa nieaktualne raporty',
    str_contains($zbiorczy['body'], 'Przelicz wszystkie nieaktualne (2)'),
    'oba mecze stoją na starszym templacie');

$start = http('POST', '/klub/1/przelicz', ['form' => ['csrf' => csrfZ($zbiorczy['body'])]]);
check('akcja zbiorcza przyjęta i prowadzi na widok partii',
    $start['status'] === 302 && preg_match('#^/klub/1/przelicz\?partia=[0-9a-f]{16}$#', (string) $start['location']) === 1,
    (string) $start['location']);

ca_test_db($baza);
check('w kolejce stoją DWA zadania przeliczenia',
    (int) Db::one("SELECT COUNT(*) AS ile FROM jobs
                    WHERE type = 'rebuild_report' AND status = 'queued'")['ile'] === 2);

// Psujemy eksport DRUGIEGO meczu — pierwszy ma przejść mimo to.
$csv2 = (string) Db::one('SELECT csv_path FROM imports WHERE match_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => $m2['match']])['csv_path'];
file_put_contents($csv2, $CSV_ZEPSUTY);

check('cron przerobił obie pozycje', cron() === 0);

ca_test_db($baza);
$raportA = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $m1['report']]);
$raportB = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $m2['report']]);
check('sprawny mecz PRZELICZONY mimo błędu drugiego',
    (int) $raportA['template_version'] === 4,
    'template_version: ' . var_export($raportA['template_version'], true));
check('zepsuty mecz zachował poprzednią wersję i poprzedni raport',
    (int) $raportB['template_version'] === 3,
    'template_version: ' . var_export($raportB['template_version'], true));
check('plik zepsutego raportu nadal istnieje', is_file((string) $raportB['html_path']));
check('zbiorcze przeliczenie nie zostawiło plików tymczasowych',
    smieciPodmiany($magazyn) === []);

$postep = http('GET', (string) $start['location']);
check('widok partii odpowiada', $postep['status'] === 200);
check('postęp podaje X z N', str_contains($postep['body'], 'gotowe 1 z 2'),
    'jedno gotowe, jedno nieudane');
check('postęp wymienia nieudane', str_contains($postep['body'], 'nieudane: 1'));
/*
 * BŁĄD MUSI BYĆ PRZYPISANY DO MECZU, nie zsypany do jednego komunikatu.
 *
 * Sprawdzamy odsyłacz do meczu, NAZWĘ meczu i treść powodu: przy kilkunastu
 * pozycjach lista błędów bez wskazania meczu nie mówi, który plik naprawić.
 *
 * Nazwa rywala jest tu jednocześnie asercją regresji: pojawia się wyłącznie
 * wtedy, gdy `assignClubs()` nie skasowało wyboru operatora przy generowaniu.
 */
check('nieudana pozycja odsyła do swojego meczu',
    str_contains($postep['body'], '/mecze/' . $m2['match'] . '/historia'));
check('nieudana pozycja jest podpisana nazwą meczu, nie samą datą',
    str_contains($postep['body'], 'GKS Drugi'),
    'wybór rywala musi przeżyć generowanie');
check('przy nieudanej pozycji stoi powód od silnika',
    str_contains($postep['body'], 'brak kolumn'),
    'sam status „błąd" nie mówi, co naprawić');
check('nieudana pozycja odsyła do swojego zadania',
    preg_match('#/zadania/\d+#', $postep['body']) === 1);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
