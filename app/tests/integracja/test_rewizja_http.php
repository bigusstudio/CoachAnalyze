<?php
declare(strict_types=1);

/**
 * Rewizja mapowania z ekranu pokrycia — PRZELOT HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. Sekcja „poza analizą" daje DROGĘ WYJŚCIA: przycisk przy liście i klikalny
 *    chip prowadzący wprost do tej pozycji. Dotąd mówiła tylko, że zdarzenia
 *    nie wchodzą do liczb, i na tym kończyła.
 * 2. Rewizja pokazuje TAKŻE pozycje zignorowane na stałe — zwykły diff je
 *    pomija, bo operator prosił, żeby o nie nie pytać. Tu przychodzi sam.
 * 3. Zatwierdzenie tworzy DOKŁADNIE JEDNĄ nową wersję templatu, jak w Sesji 6.
 * 4. „Cofnij na stałe" usuwa wpis z `club_ignored_tags`.
 * 5. Pełna pętla: rewizja → nowa wersja → Generuj ponownie → zdarzenia
 *    dopisanego taga są w raporcie (mechanika Sesji 7).
 * 6. Sekcje niedostępne z braku danych NIE dostają akcji — nie ma tam czego
 *    mapować, więc przycisk byłby obietnicą bez pokrycia.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_rewizja_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\IgnoredTags;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\TemplateDiff;

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

$baza    = $here . '/rewizja.sqlite';
$magazyn = $here . '/rewizja_storage';
$logFile = $here . '/rewizja.log';
$envFile = $here . '/.env.rewizja';
$sock    = $here . '/rewizja_redis.sock';
$port    = 9001;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=rew:',
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

/*
 * Eksport z tagiem, który operator najpierw odrzuci, a potem się rozmyśli.
 * PRESSING WYSOKI występuje trzy razy — po dopisaniu do templatu jego zdarzenia
 * mają wejść do liczb, a przed nim nie.
 */
$CSV = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n"
     . "PRESSING WYSOKI,50,60,KLUB A,,,60,25\n"
     . "PRESSING WYSOKI,70,80,KLUB A,,,62,28\n"
     . "PRESSING WYSOKI,90,100,KLUB A,,,58,22\n"
     . "DOŚRODKOWANIE,110,120,KLUB A,,,80,10\n";

// ============================================================ A. przygotowanie
echo "== A. klub z templatem, import, dwie decyzje odrzucające ==\n";

$konfig = [
    'schema_version'   => 1,
    'team_us_rule'     => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'tl_bilans', 'mapy'],
    'variables' => [
        ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'],
         'canon' => 'shot', 'display_label' => 'Strzały', 'color' => '#E8590C',
         'sections' => ['bilans', 'mapy'], 'visible' => true],
        ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRATA'],
         'canon' => 'loss', 'display_label' => 'Straty', 'color' => '#8899AA',
         'sections' => ['bilans'], 'visible' => true],
    ],
];
check('templat v1 zapisany', ReportTemplates::saveNewVersion(1, $konfig, 1) === 1);

$login = http('GET', '/login');
check('zalogowano', http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]])['status'] === 302);

$formularz = http('GET', '/klub/1/import');
check('upload przyjęty', http('POST', '/klub/1/import', ['multipart' => multipart(
    ['csrf' => csrfZ($formularz['body'])], ['csv' => ['mecz.csv', $CSV]]
)])['status'] === 302);
check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$importId = (int) $import['id'];
$matchId  = (int) $import['match_id'];

$meta = http('GET', '/import/' . $importId . '/meta');
http('POST', '/import/' . $importId . '/meta', ['form' => [
    'csrf' => csrfZ($meta['body']), 'nowy_rywal' => 'GKS Rewizyjny', 'played_at' => '2026-09-07',
]]);

// Diff: PRESSING WYSOKI pomijamy jednorazowo, DOŚRODKOWANIE na stałe.
$diff = http('GET', '/import/' . $importId . '/diff');
check('ekran nowych tagów odpowiada', $diff['status'] === 200);

$kluczPress = TemplateDiff::kluczHtml('tag', 'PRESSING WYSOKI');
$kluczDosr  = TemplateDiff::kluczHtml('tag', 'DOŚRODKOWANIE');

preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $diff['body'], $mm);
$decyzje = [];
foreach (array_unique($mm[1]) as $k) { $decyzje[$k] = TemplateDiff::POMIN; }
$decyzje[$kluczDosr] = TemplateDiff::NA_STALE;

http('POST', '/import/' . $importId . '/diff', ['form' => [
    'csrf' => csrfZ($diff['body']), 'decyzja' => $decyzje,
]]);

ca_test_db($baza);
check('templat bez zmian — nic nie dopisano', ReportTemplates::currentVersion(1) === 1);
check('DOŚRODKOWANIE zignorowane na stałe',
    !empty(IgnoredTags::lookup(1)['tag']['DOŚRODKOWANIE']));

// ============================================================ B. pokrycie daje wyjście
echo "\n== B. ekran pokrycia daje drogę wyjścia, nie tylko informację ==\n";

$pokrycie = http('GET', '/import/' . $importId);
check('pokrycie odpowiada', $pokrycie['status'] === 200, 'status ' . $pokrycie['status']);
check('jest przycisk „Zmień mapowanie"', str_contains($pokrycie['body'], 'Zmień mapowanie'));
check('przycisk prowadzi do rewizji na tym imporcie',
    str_contains($pokrycie['body'], '/import/' . $importId . '/diff?rewizja=1'));

check('chip pominiętego taga jest klikalny',
    str_contains($pokrycie['body'], 'tag=' . $kluczPress),
    'chip ma prowadzić wprost do tej pozycji, nie do listy');
check('chip zignorowanego na stałe też',
    str_contains($pokrycie['body'], 'tag=' . $kluczDosr));
check('chip prowadzi do kotwicy pozycji',
    str_contains($pokrycie['body'], '#poz-' . $kluczPress));

/*
 * SEKCJE NIEDOSTĘPNE Z BRAKU DANYCH NIE DOSTAJĄ AKCJI — nie ma tam czego
 * mapować, a przycisk byłby obietnicą, której ekran nie spełni.
 */
$sekcjaSekcji = '';
if (preg_match('#Sekcje raportu.*?</section>#s', $pokrycie['body'], $ms) === 1) {
    $sekcjaSekcji = $ms[0];
}
check('przy sekcjach bez danych nie ma akcji mapowania',
    $sekcjaSekcji !== '' && !str_contains($sekcjaSekcji, 'rewizja=1'),
    'zostaje sam powód niedostępności');

// ============================================================ C. rewizja
echo "\n== C. rewizja pokazuje TAKŻE zignorowane na stałe ==\n";

$rew = http('GET', '/import/' . $importId . '/diff?rewizja=1&tag=' . $kluczPress);
check('rewizja odpowiada', $rew['status'] === 200, 'status ' . $rew['status']);
check('tytuł mówi o rewizji', str_contains($rew['body'], 'Rewizja mapowania'));

check('pozycja pominięta jednorazowo jest na liście',
    str_contains($rew['body'], 'PRESSING WYSOKI')
    && str_contains($rew['body'], 'name="decyzja[' . $kluczPress . ']"'));
check('pozycja zignorowana NA STAŁE też jest na liście',
    str_contains($rew['body'], 'DOŚRODKOWANIE')
    && str_contains($rew['body'], 'name="decyzja[' . $kluczDosr . ']"'),
    'zwykły diff ją pomija — o to właśnie prosił operator; tu przychodzi sam');

check('widać obecny stan pozycji',
    str_contains($rew['body'], 'zignorowana na stałe')
    && str_contains($rew['body'], 'pominięta w tym imporcie'));
// Licznik stoi w główce pozycji, zaraz za nazwą i typem źródła.
check('widać licznik zdarzeń',
    preg_match('/PRESSING WYSOKI.{0,400}wystąpień:\s*3/su', $rew['body']) === 1,
    'trzy wystąpienia w eksporcie');

check('pozycja z chipa jest wyróżniona',
    str_contains($rew['body'], 'id="poz-' . $kluczPress . '"')
    && preg_match('/class="zmienna is-fokus" id="poz-' . $kluczPress . '"/', $rew['body']) === 1);

check('zignorowana na stałe ma akcję cofnięcia',
    preg_match('/name="decyzja\[' . $kluczDosr . '\]"\s+value="' . TemplateDiff::COFNIJ . '"/', $rew['body']) === 1);
check('pominięta jednorazowo NIE ma akcji cofnięcia',
    preg_match('/name="decyzja\[' . $kluczPress . '\]"\s+value="' . TemplateDiff::COFNIJ . '"/', $rew['body']) !== 1,
    'nie ma czego cofać — nic nie zapisano');

// ============================================================ D. zatwierdzenie
echo "\n== D. zatwierdzenie: JEDNA nowa wersja templatu ==\n";

$csrfR = csrfZ($rew['body']);
preg_match_all('/name="decyzja\[([a-f0-9]+)\]"/', $rew['body'], $mr);
$dec = [];
foreach (array_unique($mr[1]) as $k) { $dec[$k] = TemplateDiff::POMIN; }
$dec[$kluczPress] = TemplateDiff::DODAJ;
$dec[$kluczDosr]  = TemplateDiff::COFNIJ;

$zapis = http('POST', '/import/' . $importId . '/diff?rewizja=1', ['form' => [
    'csrf'      => $csrfR,
    'decyzja'   => $dec,
    'canon'     => [$kluczPress => 'press'],
    'vsections' => [$kluczPress => ['bilans', 'tl_bilans']],
]]);
check('zapis rewizji wraca na pokrycie',
    $zapis['status'] === 302 && $zapis['location'] === '/import/' . $importId,
    $zapis['status'] . ' → ' . (string) $zapis['location']);

ca_test_db($baza);
check('powstała DOKŁADNIE JEDNA nowa wersja templatu',
    ReportTemplates::currentVersion(1) === 2,
    'wersja: ' . ReportTemplates::currentVersion(1));

$config2 = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
$nazwy = array_column(array_column($config2['variables'], 'source'), 'raw');
check('dopisany tag jest w templacie v2', in_array('PRESSING WYSOKI', $nazwy, true));
check('zmienne z v1 zostały',
    in_array('STRZAŁ', $nazwy, true) && in_array('STRATA', $nazwy, true));
check('cofnięte „na stałe" NIE jest w templacie', !in_array('DOŚRODKOWANIE', $nazwy, true),
    'cofnięcie przywraca pytanie, nie dopisuje zmiennej');
check('cofnięcie usunęło wpis z club_ignored_tags',
    empty(IgnoredTags::lookup(1)['tag']['DOŚRODKOWANIE']));

// ============================================================ E. podpowiedź
echo "\n== E. powrót na pokrycie z podpowiedzią o przeliczeniu ==\n";

$poRewizji = http('GET', '/import/' . $importId);
check('pokrycie odpowiada po rewizji', $poRewizji['status'] === 200);
check('podział jest odświeżony — PRESSING WYSOKI już nie jest poza templatem',
    !str_contains($poRewizji['body'], 'tag=' . $kluczPress),
    'wszedł do templatu, więc znika z sekcji „poza templatem"');
check('cofnięty tag wrócił do pytania',
    str_contains($poRewizji['body'], 'DOŚRODKOWANIE'));
check('jest podpowiedź o ponownym wygenerowaniu',
    str_contains($poRewizji['body'], 'Wygeneruj ponownie')
    || str_contains($poRewizji['body'], 'wygeneruj go ponownie'),
    'raport nadal stoi na poprzedniej wersji templatu');
check('podpowiedź podaje numer nowej wersji',
    preg_match('/wersję 2|wersj[ęe]\s*2/u', $poRewizji['body']) === 1
    || str_contains($poRewizji['body'], 'wersję 2'));

// ============================================================ F. pętla domknięta
echo "\n== F. Generuj ponownie: zdarzenia dopisanego taga w raporcie ==\n";

$gen = http('POST', '/import/' . $importId . '/generuj', ['form' => [
    'csrf' => csrfZ($poRewizji['body']),
]]);
check('generowanie przyjęte', $gen['status'] === 302, (string) $gen['location']);
check('cron wygenerował raport', cron() === 0);

ca_test_db($baza);
$raport = Db::one('SELECT * FROM reports WHERE match_id = :m ORDER BY id DESC LIMIT 1',
    ['m' => $matchId]);
check('raport powstał', $raport !== null);
check('STEMPEL: raport stoi na templacie v2',
    $raport !== null && (int) $raport['template_version'] === 2,
    'template_version: ' . var_export($raport['template_version'] ?? null, true));

$html = is_file((string) $raport['html_path']) ? (string) file_get_contents((string) $raport['html_path']) : '';
check('plik raportu powstał', $html !== '');
check('stopka niesie wersję templatu', str_contains($html, 'templat v2'));

/*
 * SEDNO CAŁEJ PĘTLI: zdarzenia taga, który przed rewizją wypadał z analizy,
 * są teraz w raporcie. Bez tego cała reszta byłaby zmianą kosmetyczną.
 */
check('zdarzenia dopisanego taga weszły do raportu',
    str_contains($html, 'PRESSING WYSOKI') || str_contains($html, 'Pressing wysoki'),
    'przed rewizją tag był poza templatem i jego zdarzenia nie wchodziły do liczb');

$importPo = Db::one('SELECT * FROM imports WHERE id = :i', ['i' => $importId]);
$pokrycieJson = (string) ($importPo['coverage_json'] ?? '');
check('pokrycie odświeżone przy generowaniu', $pokrycieJson !== '');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
