<?php
declare(strict_types=1);

/**
 * Własne hasła klubowe w indeksie współczynników (M1) — PRZELOT HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. Klub może DODAĆ własne hasło, nie tylko nadpisać systemowe. To była realna
 *    luka: `IndexTerms::all()` iterowało wyłącznie po slugach systemowych, więc
 *    wiersz z własnym slugiem leżał w tabeli i nigdzie się nie pokazywał.
 * 2. Lista scala jedno z drugim, z czytelnym oznaczeniem: systemowe / klubowe /
 *    nadpisuje systemowe. Trzy stany, nie dwa.
 * 3. Slug kolidujący z systemowym wymaga JAWNEGO potwierdzenia — nadpisanie
 *    jest funkcją, ale nie ma się zdarzać po cichu.
 * 4. Usunięcie wersji klubowej przywraca hasło systemowe; usunięcie hasła
 *    własnego zdejmuje je z indeksu.
 * 5. Wersja publiczna hasła (`/r/{club_key}/i/{slug}`) pokazuje hasła klubowe
 *    i mówi, że to definicja klubu — czytelnik nie ma brać jej za metodykę
 *    produktu.
 * 6. Nowe hasło trafia do odsyłaczy raportu BEZ zmian w silniku: PHP przekazuje
 *    gotowe `options.index_links`, więc „Przelicz" podnosi je samo.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_hasla_indeksu_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\IndexTerms;

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

$baza    = $here . '/hasla.sqlite';
$magazyn = $here . '/hasla_storage';
$logFile = $here . '/hasla.log';
$envFile = $here . '/.env.hasla';
$sock    = $here . '/hasla_redis.sock';
$port    = 9011;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=hasla:',
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


$CSV = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n";

// ============================================================ A. stan wyjsciowy
echo "== A. bez migracji: tabela i model już to unoszą ==\n";

$kolumny = array_column(Db::all('PRAGMA table_info(index_terms)'), 'name');
foreach (['club_id', 'slug', 'version', 'created_by'] as $kol) {
    check("index_terms ma kolumnę {$kol}", in_array($kol, $kolumny, true));
}

$migracje = glob($root . '/app/migrations/*.sql') ?: [];
$numery = [];
foreach ($migracje as $plik) {
    if (preg_match('/(\d{3})_/', basename($plik), $m) === 1) { $numery[] = (int) $m[1]; }
}
check('nie dołożono migracji 014', !in_array(14, $numery, true),
    'tabela z migracji 010 już trzyma wersje klubowe');

$login = http('GET', '/login');
check('zalogowano', http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]])['status'] === 302);

$lista = http('GET', '/indeks?klub=1');
check('indeks odpowiada', $lista['status'] === 200);
check('hasła systemowe są na liście', str_contains($lista['body'], 'xG (gole oczekiwane)'));
check('systemowe są oznaczone', str_contains($lista['body'], 'systemowe'));
check('jest wejście do nowego hasła', str_contains($lista['body'], '/indeks/nowe'));

// ============================================================ B. wlasne haslo
echo "\n== B. klub dodaje WŁASNE hasło ==\n";

$formularz = http('GET', '/indeks/nowe?klub=1');
check('formularz nowego hasła odpowiada', $formularz['status'] === 200);
check('formularz ma pole identyfikatora', str_contains($formularz['body'], 'name="slug"'));
check('formularz ma pole pojęcia', str_contains($formularz['body'], 'name="concept"'));

$zapis = http('POST', '/indeks/nowe?klub=1', ['form' => [
    'csrf'       => csrfZ($formularz['body']),
    'name'       => 'Wskaźnik przełamań',
    'concept'    => 'press',
    'definition' => 'Liczba akcji przełamujących pierwszą linię pressingu rywala.',
    'formula'    => 'przełamania / podania w fazie budowania',
    'interpretation' => 'Wysoka wartość oznacza odwagę w wyprowadzeniu piłki.',
]]);
check('zapis przyjęty', $zapis['status'] === 302, (string) $zapis['location']);
check('adres hasła wyznaczony z nazwy',
    $zapis['location'] === '/indeks/wskaznik-przelaman?klub=1',
    'polskie znaki mają zejść do łacińskich: ' . (string) $zapis['location']);

ca_test_db($baza);
$slugi = IndexTerms::clubSlugs(1);
check('hasło zapisane w tabeli klubu', in_array('wskaznik-przelaman', $slugi, true),
    implode(', ', $slugi));

$listaPo = http('GET', '/indeks?klub=1');
check('WŁASNE hasło jest na liście', str_contains($listaPo['body'], 'Wskaźnik przełamań'),
    'to była luka: wiersz leżał w tabeli i nie pokazywał się nigdzie');
check('oznaczone jako klubowe', str_contains($listaPo['body'], 'klubowe'));
check('systemowe nadal są', str_contains($listaPo['body'], 'xG (gole oczekiwane)'),
    'dopisanie własnego nie może zjeść systemowych');

$haslo = http('GET', '/indeks/wskaznik-przelaman?klub=1');
check('hasło otwiera się', $haslo['status'] === 200);
check('treść na miejscu', str_contains($haslo['body'], 'pierwszą linię pressingu'));
check('jest akcja usunięcia', str_contains($haslo['body'], '/indeks/wskaznik-przelaman/usun'));

// ============================================================ C. kolizja
echo "\n== C. slug systemowy wymaga świadomego potwierdzenia ==\n";

$f2 = http('GET', '/indeks/nowe?klub=1');
$proba = http('POST', '/indeks/nowe?klub=1', ['form' => [
    'csrf'       => csrfZ($f2['body']),
    'name'       => 'Nasze xG',
    'slug'       => 'xg',
    'definition' => 'Nasza definicja xG.',
]]);
check('zapis bez potwierdzenia wraca na formularz',
    $proba['status'] === 302 && str_contains((string) $proba['location'], '/indeks/nowe'),
    (string) $proba['location']);

ca_test_db($baza);
check('nic nie zapisano po cichu', !in_array('xg', IndexTerms::clubSlugs(1), true),
    'ciche nadpisanie hasła systemowego byłoby najgorszym wariantem');

$zOstrzezeniem = http('GET', '/indeks/nowe?klub=1');
check('formularz wraca z ostrzeżeniem',
    str_contains($zOstrzezeniem['body'], 'NADPISZE'));
check('jest pole potwierdzenia',
    str_contains($zOstrzezeniem['body'], 'name="potwierdzam_nadpisanie"'));
check('treść formularza nie przepadła',
    str_contains($zOstrzezeniem['body'], 'Nasza definicja xG'),
    'operator nie ma wpisywać wszystkiego od nowa');

$zPotwierdzeniem = http('POST', '/indeks/nowe?klub=1', ['form' => [
    'csrf'       => csrfZ($zOstrzezeniem['body']),
    'name'       => 'Nasze xG',
    'slug'       => 'xg',
    'definition' => 'Nasza definicja xG.',
    'potwierdzam_nadpisanie' => '1',
]]);
check('z potwierdzeniem zapis przechodzi',
    $zPotwierdzeniem['status'] === 302
    && $zPotwierdzeniem['location'] === '/indeks/xg?klub=1',
    (string) $zPotwierdzeniem['location']);

ca_test_db($baza);
check('nadpisanie zapisane', in_array('xg', IndexTerms::clubSlugs(1), true));

$xg = http('GET', '/indeks/xg?klub=1');
check('hasło pokazuje definicję klubu', str_contains($xg['body'], 'Nasza definicja xG'));
check('oznaczone jako nadpisujące', str_contains($xg['body'], 'nadpisuje hasło systemowe'));
check('widać podgląd wersji systemowej',
    str_contains($xg['body'], 'Hasło systemowe, które to nadpisuje')
    && str_contains($xg['body'], 'Suma prawdopodobieństw'),
    'bez tego nie da się porównać własnej metodyki z metodyką produktu');

// ============================================================ D. usuniecie
echo "\n== D. usunięcie: nadpisanie wraca do systemowego ==\n";

$usun = http('POST', '/indeks/xg/usun?klub=1', ['form' => ['csrf' => csrfZ($xg['body'])]]);
check('usunięcie przyjęte', $usun['status'] === 302);

ca_test_db($baza);
check('wersja klubowa zniknęła z tabeli', !in_array('xg', IndexTerms::clubSlugs(1), true));

$xgPo = http('GET', '/indeks/xg?klub=1');
check('hasło systemowe WRÓCIŁO', str_contains($xgPo['body'], 'Suma prawdopodobieństw'));
check('oznaczone znów jako systemowe', str_contains($xgPo['body'], 'systemowe'));
check('nie ma już podglądu nadpisania',
    !str_contains($xgPo['body'], 'Hasło systemowe, które to nadpisuje'));

// Hasło własne po usunięciu znika z indeksu w całości.
$wlasne = http('GET', '/indeks/wskaznik-przelaman?klub=1');
$usun2 = http('POST', '/indeks/wskaznik-przelaman/usun?klub=1', [
    'form' => ['csrf' => csrfZ($wlasne['body'])],
]);
check('usunięcie hasła własnego wraca na listę',
    $usun2['status'] === 302 && str_contains((string) $usun2['location'], '/indeks?klub=1'),
    (string) $usun2['location']);
check('hasła własnego już nie ma',
    http('GET', '/indeks/wskaznik-przelaman?klub=1')['status'] === 404);

// ============================================================ E. publiczna
echo "\n== E. wersja publiczna: klubowe widoczne i oznaczone ==\n";

$f3 = http('GET', '/indeks/nowe?klub=1');
http('POST', '/indeks/nowe?klub=1', ['form' => [
    'csrf'       => csrfZ($f3['body']),
    'name'       => 'Indeks transformacji',
    'concept'    => 'transition',
    'definition' => 'Tempo przejścia z odbioru do podania w SBZ.',
]]);

$pub = http('GET', '/r/HUT7K2QX/i/indeks-transformacji');
check('publiczne hasło klubowe odpowiada', $pub['status'] === 200,
    'status ' . $pub['status']);
check('treść widoczna dla czytelnika', str_contains($pub['body'], 'Tempo przejścia'));
check('czytelnik wie, że to definicja klubu',
    str_contains($pub['body'], 'definicja klubu'),
    'inaczej weźmie ją za metodykę produktu');
check('strona publiczna nie zdradza nazwy klubu',
    !str_contains($pub['body'], 'Klub A'));

$pubSys = http('GET', '/r/HUT7K2QX/i/xg');
check('publiczne hasło systemowe nadal działa', $pubSys['status'] === 200);
check('oznaczone jako systemowe', str_contains($pubSys['body'], 'systemowe'));

// ============================================================ F. raport
echo "\n== F. nowe hasło trafia do raportu bez zmian w silniku ==\n";

$wersjaSilnika = trim((string) file_get_contents($root . '/engine/coachanalyze/__init__.py'));
check('wersja silnika NIE została podbita',
    str_contains($wersjaSilnika, '__version__ = "0.11.0"'),
    'silnik nie ma słownika haseł — dostaje gotowe index_links z PHP');

$linki = IndexTerms::linksFor(1);
$slugiLinkow = array_column($linki, 'slug');
check('odsyłacze raportu zawierają hasło klubowe',
    in_array('indeks-transformacji', $slugiLinkow, true),
    implode(', ', $slugiLinkow));
check('odsyłacze raportu nadal zawierają systemowe',
    in_array('xg', $slugiLinkow, true));
check('każdy odsyłacz ma etykietę i flagę szacowania',
    $linki !== [] && array_key_exists('label', $linki[0])
    && array_key_exists('estimated', $linki[0]));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
