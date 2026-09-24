<?php
declare(strict_types=1);

/**
 * Punkt końcowy metryk `/api/metryki` — PRZELOT HTTP (sesja 3 pivotu).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. BEZ SESJI 401, nie 200 i nie 302. Trasa stoi ZA `requireLogin()`, bo jest
 *    wołana świadomie — inaczej niż punkty końcowe chmurek, które skrypt odpytuje
 *    na każdej stronie i którym przekierowanie na `/login` dawałoby stronę
 *    logowania jako „odpowiedź JSON".
 * 2. CUDZY ZAKRES 403. Klub, który nie jest tenantem, nie jest zakresem metryk.
 *    403, nie 404: zalogowany użytkownik i tak wie, że trasa istnieje, więc
 *    ukrywanie jej niczego nie chroni, a 403 mówi wprost, o co chodzi.
 * 3. ODPOWIEDŹ NIESIE POKRYCIE KATALOGU. Metryka bez taga w katalogu klubu ma
 *    `value: null` i wpis w `catalog_coverage` — to zasila „Wymaga uwagi"
 *    i jest jedyną różnicą między „policzono zero" a „nie ma czego liczyć".
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_api_metryki_http.php
 */

use CoachAnalyze\Db;

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

$baza    = $here . '/apimetryki.sqlite';
$magazyn = $here . '/apimetryki_storage';
$logFile = $here . '/apimetryki.log';
$envFile = $here . '/.env.apimetryki';
$sock    = $here . '/apimetryki_redis.sock';
$port    = 9021;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=apimetryki:',
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



// ============================================================ A. bez sesji
echo "== A. bez sesji: 401, nie 302 ==\n";

$bezSesji = http('GET', '/api/metryki?club=1');
check('bez sesji 401', $bezSesji['status'] === 401,
    'status ' . $bezSesji['status'] . ' — 302 dałoby klientowi stronę logowania jako JSON');

// ============================================================ B. logowanie
echo "\n== B. logowanie ==\n";
$form = http('GET', '/login');
$zaloguj = http('POST', '/login', ['form' => [
    'csrf' => csrfZ($form['body']),
    'email' => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe',
]]);
check('zalogowano', $zaloguj['status'] === 302, 'status ' . $zaloguj['status']);

// ============================================================ C. dane
echo "\n== C. zdarzenia i katalog ==\n";

ca_test_db($baza);
$t = 0;
foreach ([
    ['ZDOBYCIE SBZ', 'us', ['STRZAŁ']],
    ['ZDOBYCIE SBZ', 'us', []],
    ['STRZAŁ', 'us', ['CELNY']],
] as [$tag, $side, $labels]) {
    $t += 1000;
    Db::run('INSERT INTO events (match_id,import_id,tag_name,labels_json,team,team_side,
                                 t_ms,half,minute,xg,xg_source,is_goal)
             VALUES (1,NULL,:tag,:lab,NULL,:side,:t,1,1,NULL,NULL,0)',
        ['tag' => $tag, 'lab' => json_encode($labels, JSON_UNESCAPED_UNICODE),
         'side' => $side, 't' => $t]);
}
Db::run("INSERT INTO tag_catalog (club_id,kind,name,seen_matches,seen_events)
         VALUES (1,'tag','ZDOBYCIE SBZ',1,2)");
check('dane przygotowane', (int) Db::one('SELECT COUNT(*) AS c FROM events')['c'] === 3);

// ============================================================ D. odpowiedź
echo "\n== D. odpowiedź endpointu ==\n";

$odp = http('GET', '/api/metryki?club=1');
check('z sesją 200', $odp['status'] === 200, 'status ' . $odp['status']);

$json = json_decode($odp['body'], true);
check('odpowiedź to poprawny JSON', is_array($json), substr($odp['body'], 0, 120));
check('niesie zakres', ($json['scope'] ?? '') === 'club:1', (string) ($json['scope'] ?? ''));
check('niesie listę metryk', !empty($json['metrics']) && is_array($json['metrics']));

$poId = array_column($json['metrics'] ?? [], null, 'id');
check('metryka SBZ policzona z katalogu', ($poId['sbz']['value'] ?? null) === 2,
    var_export($poId['sbz']['value'] ?? null, true));
check('wskaźnik niesie licznik i mianownik',
    ($poId['sbz_ze_strzalem']['n'] ?? null) === 1 && ($poId['sbz_ze_strzalem']['d'] ?? null) === 2);

check('metryka bez taga w katalogu ma NULL',
    array_key_exists('value', $poId['duele_def_wygrane'] ?? [])
    && $poId['duele_def_wygrane']['value'] === null);

$pokrycie = array_column($json['catalog_coverage'] ?? [], 'missing_tags', 'metric');
check('brak pokrycia wymieniony w catalog_coverage', isset($pokrycie['duele_def_wygrane']),
    implode(', ', array_keys($pokrycie)));
check('metryka z aliasem NIE zgłasza braku', !isset($pokrycie['sbz']),
    'klub używa `ZDOBYCIE SBZ` i to wystarcza');

// ============================================================ E. zakres meczu
echo "\n== E. mecz kontra SUMA ==\n";

$kolejka = json_decode(http('GET', '/api/metryki?club=1&match=1')['body'], true);
check('zakres meczu opisany', ($kolejka['scope'] ?? '') === 'match:1');
$kolejkaPoId = array_column($kolejka['metrics'] ?? [], null, 'id');
check('kolejka daje tę samą liczbę co suma jednego meczu',
    ($kolejkaPoId['sbz']['value'] ?? null) === 2);

$innyMecz = json_decode(http('GET', '/api/metryki?club=1&match=2')['body'], true);
$innyPoId = array_column($innyMecz['metrics'] ?? [], null, 'id');
// `??` reaguje TAKŻE na null, więc `$x['value'] ?? 'brak'` nigdy nie zwróci
// null — obecność klucza sprawdzamy osobno od jego wartości.
check('mecz bez zdarzeń daje NULL, nie zero',
    array_key_exists('sbz', $innyPoId)
    && array_key_exists('value', $innyPoId['sbz'])
    && $innyPoId['sbz']['value'] === null,
    json_encode($innyPoId['sbz'] ?? null, JSON_UNESCAPED_UNICODE));

// ============================================================ F. cudzy zakres
echo "\n== F. cudzy zakres ==\n";

$obcy = http('GET', '/api/metryki?club=2');
check('klub niebędący tenantem daje 403', $obcy['status'] === 403, 'status ' . $obcy['status']);

$brakKlubu = http('GET', '/api/metryki');
check('brak parametru klubu daje 400', $brakKlubu['status'] === 400, 'status ' . $brakKlubu['status']);

$nieistniejacy = http('GET', '/api/metryki?club=99999');
check('nieistniejący klub daje 403', $nieistniejacy['status'] === 403,
    'status ' . $nieistniejacy['status']);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
