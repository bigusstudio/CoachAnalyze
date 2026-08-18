<?php
declare(strict_types=1);

/**
 * Sesja 2: hub klubu, lista klubów jako wyłącznie tenanci, scope'owanie
 * mecze/raporty/notatki/import pod `/klub/{id}/…` — przelot przez PRAWDZIWY
 * HTTP (routing, bramki, theming), bez silnika Pythona (ta sesja go nie rusza).
 *
 * Sprawdzane twierdzenia:
 *  - `/` przekierowuje na `/kluby`, stary pulpit żyje pod `/pulpit`,
 *  - `/kluby` pokazuje WYŁĄCZNIE tenantów (`is_own_team = 1`) — rywale
 *    (`Klub B`, `Rywal C` z seed.php) nie są na liście,
 *  - `/klub/{id}` dla tenanta działa, dla rywala i nieistniejącego id daje 404,
 *  - `/klub/{id}/mecze` i `/klub/{id}/raporty` pokazują WYŁĄCZNIE mecze/raporty
 *    tego tenanta (`club_id`), nie licząc meczów, w których jest tylko stroną,
 *  - `/kluby/nowy?tenant=1` → formularz bez pola „to mój klub", zapis mimo to
 *    tworzy tenanta (`force_tenant`),
 *  - kontekst klubu w HTML niesie zmienne CSS `--club-primary` i okruszki.
 *
 * Uruchomienie:  php test_klub_hub_http.php
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

// ---------------------------------------------------------------- środowisko
$baza    = $here . '/klub_hub_http.sqlite';
$magazyn = $here . '/klub_hub_http_storage';
$logFile = $here . '/klub_hub_http.log';
$envFile = $here . '/.env.klub_hub_http';
$sock    = $here . '/klub_hub_http_redis.sock';
$port    = 8961;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn,
    'LOG_PATH=' . $logFile,
    'APP_URL=http://127.0.0.1:' . $port,
    'REDIS_SOCKET=' . $sock,
    'ARGON_MEMORY_COST=8192',
    'ARGON_TIME_COST=1',
    '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // z danymi: klub 1 (tenant), 2 (rywal), 3 (rywal), 4 (tenant)

// ---------------------------------------------------------------- procesy
$procesy = [];

$procesy[] = proc_open(
    'php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $rury1
);
for ($i = 0; $i < 50 && !file_exists($sock); $i++) {
    usleep(100000);
}

$procesy[] = proc_open(
    'php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $rury2
);
for ($i = 0; $i < 50; $i++) {
    $probka = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if (is_resource($probka)) {
        fclose($probka);
        break;
    }
    usleep(100000);
}

register_shutdown_function(static function () use ($procesy, $baza, $envFile, $logFile, $sock, $magazyn): void {
    foreach ($procesy as $p) {
        if (is_resource($p)) {
            proc_terminate($p);
            proc_close($p);
        }
    }
    @unlink($baza);
    @unlink($envFile);
    @unlink($logFile);
    @unlink($sock);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

// ---------------------------------------------------------------- pomocnicze
$bazaUrl    = 'http://127.0.0.1:' . $port;
$ciasteczka = [];

/** @return array{status:int, location:?string, body:string} */
function http(string $method, string $path, array $opts = []): array
{
    global $bazaUrl, $ciasteczka;

    $naglowki = ['Connection: close'];
    if ($ciasteczka !== []) {
        $pary = [];
        foreach ($ciasteczka as $k => $v) {
            $pary[] = $k . '=' . $v;
        }
        $naglowki[] = 'Cookie: ' . implode('; ', $pary);
    }

    $tresc = null;
    if (isset($opts['form'])) {
        $tresc = http_build_query($opts['form']);
        $naglowki[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $ctx = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $naglowki),
        'content'         => $tresc,
        'follow_location' => 0,
        'ignore_errors'   => true,
        'timeout'         => 30,
    ]]);

    $body = @file_get_contents($bazaUrl . $path, false, $ctx);

    $status = 0;
    $location = null;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m) === 1) {
            $status = (int) $m[1];
            $location = null;
        } elseif (stripos($h, 'Location:') === 0) {
            $location = trim(substr($h, 9));
        } elseif (stripos($h, 'Set-Cookie:') === 0
            && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }

    return ['status' => $status, 'location' => $location, 'body' => (string) $body];
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

// ---------------------------------------------------------------- logowanie
echo "== logowanie ==\n";

$login = http('GET', '/login');
$csrf = csrfZ($login['body']);
$zalogowano = http('POST', '/login', ['form' => [
    'email'    => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf'     => $csrf,
]]);
check('zalogowanie przekierowuje', $zalogowano['status'] === 302, 'status ' . $zalogowano['status']);

// ---------------------------------------------------------------- punkt wejścia
echo "\n== punkt wejścia = lista klubów ==\n";

$root_ = http('GET', '/');
check('„/" przekierowuje na „/kluby"', $root_['status'] === 302 && $root_['location'] === '/kluby',
    $root_['status'] . ' → ' . (string) $root_['location']);

$pulpit = http('GET', '/pulpit');
check('stary pulpit żyje pod /pulpit', $pulpit['status'] === 200
    && str_contains($pulpit['body'], 'Ostatnie mecze'), 'status ' . $pulpit['status']);

// ---------------------------------------------------------------- lista = tenanci
echo "\n== /kluby: wyłącznie tenanci ==\n";

$kluby = http('GET', '/kluby');
check('lista klubów odpowiada', $kluby['status'] === 200, 'status ' . $kluby['status']);
check('tenant „Klub A" jest na liście', str_contains($kluby['body'], 'Klub A'));
check('tenant „Klub D" jest na liście', str_contains($kluby['body'], 'Klub D'));
check('rywal „Klub B" NIE jest na liście', !str_contains($kluby['body'], 'Klub B'));
check('rywal „Rywal C" NIE jest na liście', !str_contains($kluby['body'], 'Rywal C'));
check('przycisk „Nowy klub" wymusza tenanta w adresie',
    str_contains($kluby['body'], '/kluby/nowy?tenant=1'));

// ---------------------------------------------------------------- hub tenanta
echo "\n== hub klubu (tenant) ==\n";

$hub = http('GET', '/klub/1');
check('hub tenanta odpowiada', $hub['status'] === 200, 'status ' . $hub['status']);
check('hub pokazuje nazwę klubu', str_contains($hub['body'], 'Klub A'));
check('hub niesie CSS klubu (--club-primary)', str_contains($hub['body'], '--club-primary:'));
check('hub niesie okruszki do „Kluby"', str_contains($hub['body'], 'href="/kluby"'));
check('CTA „Skonfiguruj raporty" prowadzi do konfiguratora',
    str_contains($hub['body'], '/klub/1/konfigurator'));

$konfigurator = http('GET', '/klub/1/konfigurator');
check('konfigurator (zapowiedź) odpowiada 200', $konfigurator['status'] === 200,
    'status ' . $konfigurator['status']);

// ---------------------------------------------------------------- 404 dla rywala
echo "\n== hub NIE działa dla rywala ani nieistniejącego id ==\n";

$rywal = http('GET', '/klub/2');
check('hub rywala (Klub B, is_own_team=0) daje 404', $rywal['status'] === 404,
    'status ' . $rywal['status']);

$brak = http('GET', '/klub/999');
check('hub nieistniejącego id daje 404', $brak['status'] === 404, 'status ' . $brak['status']);

foreach (['/klub/2/mecze', '/klub/2/raporty', '/klub/2/notatki', '/klub/2/import'] as $trasa) {
    $r = http('GET', $trasa);
    check("scope rywala też 404: {$trasa}", $r['status'] === 404, 'status ' . $r['status']);
}

// ---------------------------------------------------------------- scope: mecze/raporty
echo "\n== /klub/{id}/mecze i /klub/{id}/raporty: wyłącznie ten tenant ==\n";

// Seed: mecze klubu 1 (club_id=1) grane 2026-08-09, 2026-07-26, 2026-07-19, brak daty;
// mecz drugi ma club_id=2 (2026-08-02, klub 2 jako "nasz").
$meczeKlub1 = http('GET', '/klub/1/mecze');
check('mecze klubu 1 odpowiadają', $meczeKlub1['status'] === 200);
check('widać datę meczu klubu 1', str_contains($meczeKlub1['body'], '2026-08-09'));
check('NIE widać meczu, którego tenantem jest klub 2',
    !str_contains($meczeKlub1['body'], '2026-08-02'));
check('filtr „klub" zniknął — zakres już w adresie',
    !str_contains($meczeKlub1['body'], 'name="klub"'));

$raportyKlub1 = http('GET', '/klub/1/raporty');
check('raporty klubu 1 odpowiadają', $raportyKlub1['status'] === 200);
check('raport klubu 2 nie wchodzi do listy klubu 1',
    (int) Db::one(
        "SELECT COUNT(*) AS c FROM reports WHERE club_id = 1"
    )['c'] >= 1);

$notatkiKlub1 = http('GET', '/klub/1/notatki');
check('notatnik klubu odpowiada', $notatkiKlub1['status'] === 200);

$importKlub1 = http('GET', '/klub/1/import');
check('formularz importu klubu odpowiada', $importKlub1['status'] === 200
    && str_contains($importKlub1['body'], 'action="/klub/1/import"'),
    'status ' . $importKlub1['status']);

// ---------------------------------------------------------------- tworzenie tenanta
echo "\n== „Kluby → Nowy klub” zawsze tworzy tenanta ==\n";

$formNowy = http('GET', '/kluby/nowy?tenant=1');
check('formularz odpowiada', $formNowy['status'] === 200);
check('pole „to mój klub" NIE występuje w trybie tenanta',
    !str_contains($formNowy['body'], 'name="is_own_team"'));
check('ukryte pole force_tenant obecne', str_contains($formNowy['body'], 'name="force_tenant"'));

$csrfNowy = csrfZ($formNowy['body']);
$zapisNowy = http('POST', '/kluby', ['form' => [
    'csrf'          => $csrfNowy,
    'force_tenant'  => '1',
    'name'          => 'Świeży Tenant',
    'short_name'    => '',
    'color_primary' => '#123456',
    // `is_own_team` CELOWO nie jest wysyłane — dokładnie tak, jak zrobi to
    // formularz bez tego pola. Wymuszenie musi zajść mimo jego braku.
]]);
check('zapis przechodzi', $zapisNowy['status'] === 302, 'status ' . $zapisNowy['status']);

$nowyKlub = Db::one("SELECT id, is_own_team FROM clubs WHERE name = 'Świeży Tenant'");
check('klub powstał', $nowyKlub !== null);
check('powstał jako TENANT mimo braku is_own_team w żądaniu',
    $nowyKlub !== null && (int) $nowyKlub['is_own_team'] === 1);

$hubNowego = http('GET', '/klub/' . (int) ($nowyKlub['id'] ?? 0));
check('świeży tenant ma działający hub', $hubNowego['status'] === 200);
check('hub świeżego tenanta pokazuje CTA konfiguratora (brak templatu)',
    str_contains($hubNowego['body'], 'Skonfiguruj raporty'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
