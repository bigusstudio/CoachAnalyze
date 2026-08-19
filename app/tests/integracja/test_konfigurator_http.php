<?php
declare(strict_types=1);

/**
 * Konfigurator raportu klubu — PEŁNA ŚCIEŻKA przez prawdziwy HTTP.
 *
 * Formularz → upload → kolejka → cron → PRAWDZIWY silnik → słownik z bloku
 * `dictionary` → edycja zmiennych → zapis templatu v1.
 *
 * Powód istnienia: model przetestowany osobno bywa zielony przy funkcji
 * nieosiągalnej z interfejsu — dokładnie tak wyglądał kreator mapowań na
 * produkcji (`test_mapowania_http.php`). Ten zestaw sprawdza, że konfigurator
 * da się PRZEKLIKAĆ, a draft przeżywa odświeżenie strony.
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_konfigurator_http.php
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

$baza    = $here . '/konfig_http.sqlite';
$magazyn = $here . '/konfig_http_storage';
$logFile = $here . '/konfig_http.log';
$envFile = $here . '/.env.konfig_http';
$sock    = $here . '/konfig_http_redis.sock';
$port    = 8971;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=konfig:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 = tenant „Klub A"

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

// ---------------------------------------------------------------- logowanie
echo "== logowanie ==\n";
$login = http('GET', '/login');
$zal = http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]]);
check('zalogowano', $zal['status'] === 302, 'status ' . $zal['status']);

// ---------------------------------------------------------------- wejście
echo "\n== wejście do konfiguratora ==\n";
$ekran = http('GET', '/klub/1/konfigurator');
check('konfigurator odpowiada', $ekran['status'] === 200, 'status ' . $ekran['status']);
check('pokazuje formularz importu założycielskiego',
    str_contains($ekran['body'], 'name="csv"'));
check('rywal nie ma konfiguratora (404)', http('GET', '/klub/2/konfigurator')['status'] === 404);

$csrf = csrfZ($ekran['body']);

// ---------------------------------------------------------------- import
echo "\n== import założycielski ==\n";
$csv = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,X 0;81,80,30\n"
     . "STRZAŁ,30,40,KLUB A,NIECELNY,,81,31\n"
     . "ZDOBYCIE SBZ,50,60,KLUB A,,,70,25\n"
     . "TAG WŁASNY KLUBU,70,80,KLUB A,SWOJA ETYKIETA,,,\n"
     . "TAG WŁASNY KLUBU,90,100,KLUB A,,,,\n";

$upload = http('POST', '/klub/1/konfigurator', ['multipart' => multipart(
    ['csrf' => $csrf], ['csv' => ['zalozycielski.csv', $csv]]
)]);
check('upload przyjęty i wraca do konfiguratora',
    $upload['status'] === 302 && $upload['location'] === '/klub/1/konfigurator',
    $upload['status'] . ' → ' . (string) $upload['location']);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
check('import zapisany', $import !== null);
$mecz = Db::one('SELECT club_id FROM matches WHERE id = :m', ['m' => (int) $import['match_id']]);
check('mecz dostał tenanta Z ADRESU, nie domyślnego',
    (int) $mecz['club_id'] === 1, (string) $mecz['club_id']);

$czekaj = http('GET', '/klub/1/konfigurator');
check('przed inspekcją ekran oczekiwania', $czekaj['status'] === 200
    && str_contains($czekaj['body'], 'Przetwarzamy'), 'status ' . $czekaj['status']);

check('cron wykonał inspekcję', cron() === 0);

// ---------------------------------------------------------------- słownik
echo "\n== słownik z bloku dictionary ==\n";
ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$meta = json_decode((string) $import['coverage_json'], true);
check('coverage_json niesie blok dictionary', isset($meta['dictionary']['tags']),
    'klucze: ' . implode(',', array_keys((array) $meta)));

$przekierowanie = http('GET', '/klub/1/konfigurator');
check('po inspekcji konfigurator prowadzi do słownika',
    $przekierowanie['status'] === 302
    && $przekierowanie['location'] === '/klub/1/konfigurator/slownik',
    $przekierowanie['status'] . ' → ' . (string) $przekierowanie['location']);

$slownik = http('GET', '/klub/1/konfigurator/slownik');
check('ekran słownika odpowiada', $slownik['status'] === 200, 'status ' . $slownik['status']);

/*
 * SEDNO ZMIANY W SILNIKU: STRZAŁ jest w domyślnym słowniku, więc NIE ma go
 * w `unmapped_tags`. Bez bloku `dictionary` nie byłoby go na tym ekranie.
 */
check('tag ROZPOZNANY przez silnik jest na ekranie',
    str_contains($slownik['body'], 'STRZAŁ'),
    'bez bloku dictionary ta pozycja byłaby niewidoczna');
check('tag nierozpoznany też jest na ekranie',
    str_contains($slownik['body'], 'TAG WŁASNY KLUBU'));
check('etykieta z eksportu jest zmienną', str_contains($slownik['body'], 'SWOJA ETYKIETA'));
check('widać liczby wystąpień', str_contains($slownik['body'], 'wystąpień'));
check('widać poziom pewności podpowiedzi', str_contains($slownik['body'], 'podpowiedź'));

// ---------------------------------------------------------------- draft
echo "\n== draft przeżywa odświeżenie ==\n";
$csrfS = csrfZ($slownik['body']);

preg_match_all('/name="canon\[(v_\d+)\]"/', $slownik['body'], $mm);
$idy = $mm[1];
check('formularz niesie identyfikatory zmiennych', count($idy) >= 4, (string) count($idy));

$etykiety = [];
foreach ($idy as $vid) { $etykiety[$vid] = 'Nazwa ' . $vid; }

$zapisDraftu = http('POST', '/klub/1/konfigurator/slownik', ['form' => [
    'csrf' => $csrfS,
    'sections' => ['bilans', 'tl_bilans'],
    'label' => $etykiety,
    'visible' => array_fill_keys($idy, '1'),
]]);
check('zapis draftu przechodzi', $zapisDraftu['status'] === 302,
    $zapisDraftu['status'] . ' → ' . (string) $zapisDraftu['location']);

$poOdswiezeniu = http('GET', '/klub/1/konfigurator/slownik');
check('etykiety operatora PRZEŻYŁY odświeżenie',
    str_contains($poOdswiezeniu['body'], 'Nazwa ' . $idy[0]),
    'draft ma przeżyć odświeżenie strony');
check('wybór sekcji przeżył odświeżenie',
    !str_contains($poOdswiezeniu['body'], 'value="mapy" checked')
    || str_contains($poOdswiezeniu['body'], 'value="bilans" checked'));

// ---------------------------------------------------------------- walidacja
echo "\n== twarda walidacja przy zapisie ==\n";
$csrfS = csrfZ($poOdswiezeniu['body']);

/*
 * Próba obejścia interfejsu: zmienna BEZ pojęcia kanonicznego wpychana do map.
 * Ekran te pola blokuje, ale żądanie da się wysłać z konsoli — o tym, co wolno
 * zapisać, rozstrzyga serwer.
 */
$doMap = [];
foreach ($idy as $vid) { $doMap[$vid] = ['bilans', 'mapy']; }

$obejscie = http('POST', '/klub/1/konfigurator/zapisz', ['form' => [
    'csrf' => $csrfS,
    'sections' => ['bilans', 'mapy'],
    'label' => $etykiety,
    'vsections' => $doMap,
    'visible' => array_fill_keys($idy, '1'),
    // canon celowo pusty dla wszystkich
]]);
check('zapis z pogwałceniem twardej zasady ODBITY',
    $obejscie['status'] === 302
    && $obejscie['location'] === '/klub/1/konfigurator/slownik',
    $obejscie['status'] . ' → ' . (string) $obejscie['location']);

ca_test_db($baza);
check('żaden templat nie powstał', ReportTemplates::current(1) === null,
    'walidacja po stronie serwera jest jedyną, której nie da się ominąć');

// ---------------------------------------------------------------- zapis
echo "\n== zapis templatu v1 ==\n";
$ekranPoBledzie = http('GET', '/klub/1/konfigurator/slownik');
$csrfS = csrfZ($ekranPoBledzie['body']);

$sekcjeZmiennych = [];
foreach ($idy as $vid) { $sekcjeZmiennych[$vid] = ['bilans', 'tl_bilans']; }

$zapis = http('POST', '/klub/1/konfigurator/zapisz', ['form' => [
    'csrf' => $csrfS,
    'sections' => ['bilans', 'tl_bilans'],
    'label' => $etykiety,
    'vsections' => $sekcjeZmiennych,
    'visible' => array_fill_keys($idy, '1'),
]]);
check('zapis przechodzi i wraca do huba klubu',
    $zapis['status'] === 302 && $zapis['location'] === '/klub/1',
    $zapis['status'] . ' → ' . (string) $zapis['location']);

ca_test_db($baza);
$templat = ReportTemplates::current(1);
check('templat v1 istnieje', $templat !== null && (int) $templat['version'] === 1);

$config = ReportTemplates::decodeConfig($templat['config'] ?? '');
check('config niesie sekcje z formularza',
    ($config['sections_enabled'] ?? []) === ['bilans', 'tl_bilans'],
    json_encode($config['sections_enabled'] ?? null));
check('config niesie wszystkie zmienne', count($config['variables'] ?? []) === count($idy));
check('config niesie markery MASZA/NASZA',
    in_array('MASZA', $config['team_us_rule']['markers'] ?? [], true));
check('etykiety operatora trafiły do templatu',
    str_contains((string) $templat['config'], 'Nazwa ' . $idy[0]));

// ---------------------------------------------------------------- po zapisie
echo "\n== stan po zapisie ==\n";
$hub = http('GET', '/klub/1');
check('hub pokazuje wersję templatu', $hub['status'] === 200
    && str_contains($hub['body'], 'Templat v1'), 'status ' . $hub['status']);

$ponownie = http('GET', '/klub/1/konfigurator');
check('draft skasowany — konfigurator wraca do formularza',
    $ponownie['status'] === 200 && str_contains($ponownie['body'], 'name="csv"'));
check('konfigurator ostrzega o istniejącym templacie',
    str_contains($ponownie['body'], 'ma już templat'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
