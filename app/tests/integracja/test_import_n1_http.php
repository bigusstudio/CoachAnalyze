<?php
declare(strict_types=1);

/**
 * Import meczu n+1 — PEŁNA ŚCIEŻKA przez prawdziwy HTTP (Sesja 6).
 *
 * meta → diff → pokrycie → generowanie, na klubie, który MA JUŻ templat.
 * Z prawdziwym silnikiem i prawdziwą kolejką cron.
 *
 * Powód istnienia: model przetestowany osobno bywa zielony przy funkcji
 * nieosiągalnej z interfejsu. W tej przebudowie przelot HTTP złapał już dwa
 * błędy niewidoczne dla testów modelowych (zgubiony blok `dictionary`
 * i `$payload` poza zasięgiem) — obie wartości ginęły MIĘDZY warstwami.
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_import_n1_http.php
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

$baza    = $here . '/import_n1.sqlite';
$magazyn = $here . '/import_n1_storage';
$logFile = $here . '/import_n1.log';
$envFile = $here . '/.env.import_n1';
$sock    = $here . '/import_n1_redis.sock';
$port    = 8981;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=n1:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 = tenant „Klub A", klub 2/3 = rywale

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

// ---------------------------------------------------------------- przygotowanie
echo "== klub z gotowym templatem ==\n";

$konfig = [
    'schema_version' => 1,
    'team_us_rule'   => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'tl_bilans', 'mapy', 'duels', 'noteam'],
    'variables' => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
        ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRATA'],
         'canon' => 'loss', 'display_label' => 'Straty', 'color' => '#8899AA',
         'sections' => ['bilans', 'tl_bilans'], 'visible' => true],
    ],
];
$v1 = ReportTemplates::saveNewVersion(1, $konfig, 1);
check('klub ma templat v1', $v1 === 1);

$login = http('GET', '/login');
$zal = http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]]);
check('zalogowano', $zal['status'] === 302);

// ---------------------------------------------------------------- import n+1
echo "\n== import kolejnego meczu (słownik z nowymi tagami) ==\n";

$formularz = http('GET', '/klub/1/import');
$csrf = csrfZ($formularz['body']);

$csv = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n"
     . "PRESSING WYSOKI,50,60,KLUB A,SKUTECZNY,,60,25\n"
     . "SBZ PODAJĄCY,70,80,KLUB A,STRZAŁ,,85,33\n"
     . "DOŚRODKOWANIE,90,100,KLUB A,CELNE,,80,10\n";

$upload = http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => $csrf], ['csv' => ['mecz2.csv', $csv]]
)]);
check('upload przyjęty', $upload['status'] === 302, (string) $upload['location']);

check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$importId = (int) $import['id'];
$matchId = (int) $import['match_id'];
check('import zapisany z tenantem',
    (int) Db::one('SELECT club_id FROM matches WHERE id = :m', ['m' => $matchId])['club_id'] === 1);

// ---------------------------------------------------------------- meta
echo "\n== krok 1: meta meczu ==\n";

$pokrycie = http('GET', '/import/' . $importId);
check('pokrycie odsyła NAJPIERW na meta meczu',
    $pokrycie['status'] === 302 && $pokrycie['location'] === '/import/' . $importId . '/meta',
    $pokrycie['status'] . ' → ' . (string) $pokrycie['location']);

$meta = http('GET', '/import/' . $importId . '/meta');
check('formularz meta odpowiada', $meta['status'] === 200);
check('nasza drużyna wzięta z klubu, nie z pola', str_contains($meta['body'], 'Klub A'));
check('lista rywali zawiera istniejące kluby', str_contains($meta['body'], 'Klub B'));
check('rywal NIE jest polem tekstowym w miejscu wyboru',
    str_contains($meta['body'], 'name="club_away_id"'));
check('są trzy stany dla „gdzie grany"',
    str_contains($meta['body'], 'nie wiemy') && str_contains($meta['body'], 'u siebie'));

$csrfM = csrfZ($meta['body']);

/*
 * NOWY RYWAL ZAKŁADANY NA MIEJSCU — sprawdzamy, że powstaje wiersz w `clubs`
 * z `is_own_team = 0`, a nie wolny tekst w meczu (rozstrzygnięcie z Sesji 1).
 */
$zapisMeta = http('POST', '/import/' . $importId . '/meta', ['form' => [
    'csrf' => $csrfM,
    'nowy_rywal' => 'GKS Nowy',
    'played_at' => '2026-09-14',
    'is_home' => '1',
    'score_us' => '2',
    'score_them' => '1',
    'competition' => 'IV liga',
]]);
check('zapis meta prowadzi na diff',
    $zapisMeta['status'] === 302 && $zapisMeta['location'] === '/import/' . $importId . '/diff',
    $zapisMeta['status'] . ' → ' . (string) $zapisMeta['location']);

ca_test_db($baza);
$rywal = Db::one("SELECT * FROM clubs WHERE name = 'GKS Nowy'");
check('nowy rywal powstał jako WIERSZ w clubs', $rywal !== null);
check('rywal ma is_own_team = 0', $rywal !== null && (int) $rywal['is_own_team'] === 0);
check('nazwa trafiła do aliasów — następny mecz rozpozna go sam',
    $rywal !== null && str_contains((string) $rywal['aliases_json'], 'GKS Nowy'));

$mecz = Db::one('SELECT * FROM matches WHERE id = :m', ['m' => $matchId]);
check('mecz wskazuje rywala przez club_away_id',
    (int) $mecz['club_away_id'] === (int) $rywal['id']);
check('data zapisana', substr((string) $mecz['played_at'], 0, 10) === '2026-09-14');
check('is_home zapisane', (int) $mecz['is_home'] === 1);
check('wynik zapisany w DWÓCH kolumnach',
    (int) $mecz['score_us'] === 2 && (int) $mecz['score_them'] === 1);
check('sezon wykryty z daty', $mecz['season_id'] !== null);

// ---------------------------------------------------------------- diff
echo "\n== krok 2: diff słownika ==\n";

$diff = http('GET', '/import/' . $importId . '/diff');
check('ekran diffu odpowiada', $diff['status'] === 200, 'status ' . $diff['status']);
check('nowe tagi są wymienione',
    str_contains($diff['body'], 'PRESSING WYSOKI')
    && str_contains($diff['body'], 'SBZ PODAJĄCY')
    && str_contains($diff['body'], 'DOŚRODKOWANIE'));
// Trzy nowe tagi (PRESSING WYSOKI, SBZ PODAJĄCY, DOŚRODKOWANIE) i cztery nowe
// etykiety (CELNY, SKUTECZNY, STRZAŁ, CELNE) — razem siedem pozycji, po trzy
// przyciski wyboru na każdą. Tagi STRZAŁ i STRATA są w templacie i NIE pytamy o nie.
check('tagi ZNANE templatowi nie zajmują miejsca na ekranie',
    !preg_match('/name="decyzja\[' . \CoachAnalyze\TemplateDiff::kluczHtml('tag', 'STRZAŁ') . '\]"/', $diff['body'])
    && !preg_match('/name="decyzja\[' . \CoachAnalyze\TemplateDiff::kluczHtml('tag', 'STRATA') . '\]"/', $diff['body']),
    'STRZAŁ i STRATA są w templacie — mapują się cicho');
check('widać licznik pozycji znanych', str_contains($diff['body'], 'Znanych templatowi'));
check('są trzy akcje per pozycja',
    str_contains($diff['body'], 'Dodaj do templatu')
    && str_contains($diff['body'], 'Pomiń w tym imporcie')
    && str_contains($diff['body'], 'Zignoruj na stałe'));

$csrfD = csrfZ($diff['body']);
preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $diff['body'], $mm);
$klucze = array_values(array_unique($mm[1]));
check('formularz niesie klucze wszystkich nowych pozycji', count($klucze) === 7,
    'trzy tagi + cztery etykiety, znaleziono: ' . count($klucze));

// Mapujemy skrót -> nazwa, żeby decyzje trafiły we właściwe pozycje.
$skrotDo = [];
foreach (['tag' => ['PRESSING WYSOKI', 'SBZ PODAJĄCY', 'DOŚRODKOWANIE'],
          'label' => ['SKUTECZNY', 'STRZAŁ', 'CELNE']] as $typ => $nazwy) {
    foreach ($nazwy as $n) {
        $skrotDo[$n] = \CoachAnalyze\TemplateDiff::kluczHtml($typ, $n);
    }
}

$decyzje = [];
$canon = [];
$sekcje = [];
foreach ($klucze as $k) {
    $decyzje[$k] = \CoachAnalyze\TemplateDiff::POMIN;
}
$decyzje[$skrotDo['PRESSING WYSOKI']] = \CoachAnalyze\TemplateDiff::DODAJ;
$canon[$skrotDo['PRESSING WYSOKI']] = 'press';
$sekcje[$skrotDo['PRESSING WYSOKI']] = ['bilans', 'tl_bilans'];
$decyzje[$skrotDo['DOŚRODKOWANIE']] = \CoachAnalyze\TemplateDiff::NA_STALE;

$zapisDiff = http('POST', '/import/' . $importId . '/diff', ['form' => [
    'csrf' => $csrfD, 'decyzja' => $decyzje, 'canon' => $canon, 'vsections' => $sekcje,
]]);
check('zatwierdzenie diffu wraca na pokrycie',
    $zapisDiff['status'] === 302 && $zapisDiff['location'] === '/import/' . $importId,
    $zapisDiff['status'] . ' → ' . (string) $zapisDiff['location']);

ca_test_db($baza);
check('powstała DOKŁADNIE JEDNA nowa wersja templatu',
    ReportTemplates::currentVersion(1) === 2,
    'wersja: ' . ReportTemplates::currentVersion(1));

$config2 = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
$nazwy = array_column(array_column($config2['variables'], 'source'), 'raw');
check('dopisany tag jest w templacie v2', in_array('PRESSING WYSOKI', $nazwy, true));
check('pominięty NIE jest w templacie', !in_array('SBZ PODAJĄCY', $nazwy, true));
check('zignorowany na stałe NIE jest w templacie', !in_array('DOŚRODKOWANIE', $nazwy, true));
check('zmienne z v1 zostały', in_array('STRZAŁ', $nazwy, true) && in_array('STRATA', $nazwy, true));

check('„zignoruj na stałe" trafiło do club_ignored_tags',
    !empty(\CoachAnalyze\IgnoredTags::lookup(1)['tag']['DOŚRODKOWANIE']));

// ---------------------------------------------------------------- pokrycie
echo "\n== krok 3: pokrycie templat × eksport PRZED generowaniem ==\n";

$pokrycie = http('GET', '/import/' . $importId);
check('pokrycie odpowiada — diff domknięty', $pokrycie['status'] === 200,
    $pokrycie['status'] . ' → ' . (string) $pokrycie['location']);
check('pokrycie wymienia pozycje POZA templatem',
    str_contains($pokrycie['body'], 'Poza templatem klubu'),
    'zero cichego wyrzucania danych');
check('zignorowany na stałe jest wyliczony z nazwy',
    str_contains($pokrycie['body'], 'DOŚRODKOWANIE'));
check('pominięty w tym imporcie też jest wyliczony',
    str_contains($pokrycie['body'], 'SBZ PODAJĄCY'));
check('widać przycisk generowania', str_contains($pokrycie['body'], '/generuj'));

// ---------------------------------------------------------------- generowanie
echo "\n== krok 4: generowanie na aktualnym templacie ==\n";

$csrfP = csrfZ($pokrycie['body']);
$generuj = http('POST', '/import/' . $importId . '/generuj', ['form' => ['csrf' => $csrfP]]);
check('generowanie przyjęte', $generuj['status'] === 302
    && preg_match('#^/zadania/(\d+)$#', (string) $generuj['location']) === 1,
    (string) $generuj['location']);

check('cron wygenerował raport', cron() === 0);

ca_test_db($baza);
$raport = Db::one('SELECT * FROM reports ORDER BY id DESC LIMIT 1');
check('raport zapisany', $raport !== null);
check('STEMPEL: raport niesie wersję templatu v2',
    $raport !== null && (int) $raport['template_version'] === 2,
    'template_version: ' . var_export($raport['template_version'] ?? null, true));

$html = is_file((string) $raport['html_path']) ? (string) file_get_contents((string) $raport['html_path']) : '';
check('plik raportu powstał', $html !== '');
check('stopka niesie wersję templatu', str_contains($html, 'templat v2'));

/*
 * WYBÓR RYWALA MUSI PRZEŻYĆ GENEROWANIE.
 *
 * USTERKA Z PRODUKCJI: `queueBuild()` woła `Imports::assignClubs()` tuż przed
 * renderem, a ta pisała obie kolumny klubów bezwarunkowo. Eksport niesie tylko
 * nazwę „KLUB A" (kolumna `team` bywa wypełniona wybiórczo — pułapka nr 5),
 * więc `club_away_id` wracało do NULL i raport pokazywał „nieznana" po stronie
 * przeciwnika — mimo że operator wybrał go w formularzu meta krok wcześniej.
 *
 * Asercje wyżej tego nie łapały, bo sprawdzały kolumnę ZARAZ PO ZAPISIE META,
 * a wybór ginął dopiero przy generowaniu. Dlatego sprawdzamy ją PONOWNIE tutaj,
 * po przejściu przez silnik.
 */
$meczPoRenderze = Db::one('SELECT club_home_id, club_away_id FROM matches WHERE id = :m',
    ['m' => $matchId]);
check('rywal z formularza meta PRZEŻYŁ generowanie',
    (int) $meczPoRenderze['club_away_id'] === (int) $rywal['id'],
    'club_away_id: ' . var_export($meczPoRenderze['club_away_id'], true)
    . ', oczekiwano: ' . (int) $rywal['id']);
check('nasza drużyna nadal przypisana',
    (int) $meczPoRenderze['club_home_id'] === 1);

// ---------------------------------------------------------------- badge
echo "\n== krok 5: badge wersji w bibliotece klubu ==\n";

$biblioteka = http('GET', '/klub/1/raporty');
check('biblioteka klubu odpowiada', $biblioteka['status'] === 200);
check('badge wersji templatu widoczny przy raporcie',
    str_contains($biblioteka['body'], 'templat v2'),
    'przygotowanie pod „Przelicz" z Sesji 7');

// ---------------------------------------------------------------- ponowny import
echo "\n== import kolejny: zignorowany na stałe NIE pyta ponownie ==\n";

$formularz = http('GET', '/klub/1/import');
$csrf2 = csrfZ($formularz['body']);
$upload2 = http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => $csrf2], ['csv' => ['mecz3.csv', $csv]]
)]);
check('drugi upload przyjęty', $upload2['status'] === 302);
check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import3 = (int) Db::one('SELECT id FROM imports ORDER BY id DESC LIMIT 1')['id'];

// Meta pomijamy — sprawdzamy sam diff.
http('POST', '/import/' . $import3 . '/meta', ['form' => [
    'csrf' => csrfZ(http('GET', '/import/' . $import3 . '/meta')['body']),
    'club_away_id' => (string) $rywal['id'],
    'played_at' => '2026-09-21',
    'is_home' => '0',
]]);

$diff3 = http('GET', '/import/' . $import3 . '/diff');
check('diff nadal pyta o pominięte w poprzednim imporcie',
    str_contains($diff3['body'], 'SBZ PODAJĄCY'),
    '„pomiń w tym imporcie" nie jest decyzją na stałe');
check('diff NIE pyta o zignorowane na stałe',
    !preg_match('/name="decyzja\[' . \CoachAnalyze\TemplateDiff::kluczHtml('tag', 'DOŚRODKOWANIE') . '\]"/', $diff3['body']),
    'to była decyzja „nie pytaj więcej"');
check('diff NIE pyta o tag dopisany do templatu',
    !preg_match('/name="decyzja\[' . \CoachAnalyze\TemplateDiff::kluczHtml('tag', 'PRESSING WYSOKI') . '\]"/', $diff3['body']),
    'jest już w templacie, mapuje się cicho');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
