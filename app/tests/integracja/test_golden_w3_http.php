<?php
declare(strict_types=1);

/**
 * Golden layout W3 — ODBIÓR ŚCIEŻKI ANALITYKA, PRZELOT HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. Wgraj → Przygotuj → Postęp → Raport, BEZ ekranu różnic i bez pustej mety:
 *    rywal podpowiedziany z kolumny drużyn, ostrzeżenie o podobnym klubie,
 *    data wymagana, wynik opcjonalny.
 * 2. W3-b: wymyślony tag dopisuje się do templatu SAM przy imporcie — raport
 *    ma baner INFORMACYJNY i tag jest wierszem tabeli makro. Tag świadomie
 *    pominięty i zmienna bez znaczenia w osi SBZ dają baner OSTRZEGAWCZY;
 *    po „Wlicz jako…" w Słowniku klubu i odświeżeniu w tle — ostrzeżenia nie ma.
 * 3. Przestawienie sekcji 04 → 02 w Układzie raportu zmienia kolejność
 *    zakładek raportu po przeliczeniu. Przegląd zostaje pierwszy.
 * 4. Trener: bez Wgraj i bez Ustawień klubu (404), raport w trybie „trener".
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_golden_w3_http.php
 */

use CoachAnalyze\Auth;
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

$baza    = $here . '/golden_w3.sqlite';
$magazyn = $here . '/golden_w3_storage';
$logFile = $here . '/golden_w3.log';
$envFile = $here . '/.env.golden_w3';
$sock    = $here . '/golden_w3_redis.sock';
$port    = 9081;

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
    'PYTHON_BIN=' . $python, 'ENGINE_TIMEOUT=60', 'HTML_TEMPLATE=v21',
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=w3:',
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

/** Cron do skutku: każde uruchomienie bierze jedno zadanie. */
function cronDoKonca(int $max = 8): void
{
    for ($i = 0; $i < $max; $i++) {
        cron();
        ca_test_db($GLOBALS['baza']);
        $czeka = Db::one("SELECT COUNT(*) AS c FROM jobs WHERE status IN ('queued', 'running')");
        if ((int) ($czeka['c'] ?? 0) === 0) {
            return;
        }
    }
}

function zaloguj(string $email): bool
{
    global $ciasteczka;
    $ciasteczka = [];
    $login = http('GET', '/login');
    $r = http('POST', '/login', ['form' => ['email' => $email, 'password' => 'bardzo-dlugie-haslo-testowe',
        'csrf' => csrfZ($login['body'])]]);
    return $r['status'] === 302;
}

/** Kolejność kafli w raporcie — tak, jak ułożył je render. */
function kolejnosc(string $html): array
{
    preg_match_all('/<section[^>]*data-widget="([a-z_]+)"/', $html, $m);
    return array_values(array_unique($m[1]));
}

function eksport(): string
{
    $wiersze = [
        ['STRZAŁ',        '10', '20', 'KLUB A',        '', 'X 0,5', '80', '30'],
        ['STRZAŁ',        '25', '28', 'KLUB B ZAMOŚĆ', '', 'X 0,1', '85', '40'],
        ['STRATA',        '30', '40', 'KLUB B ZAMOŚĆ', '', '',      '50', '30'],
        ['WYMYŚLONY TAG', '50', '60', 'KLUB A',        '', '',      '60', '25'],
        ['WYMYŚLONY TAG', '70', '80', 'KLUB A',        '', '',      '62', '28'],
        ['POMIJANY',      '90', '91', 'KLUB A',        '', '',      '40', '20'],
        ['POMIJANY',      '95', '96', 'KLUB A',        '', '',      '41', '21'],
        ['POMIJANY',      '99', '99', 'KLUB A',        '', '',      '42', '22'],
        ['MOJE SBZ',     '120','121', 'KLUB A',        '', '',      '88', '34'],
    ];
    $out = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n";
    foreach ($wiersze as $w) {
        $fh = fopen('php://memory', 'r+');
        fputcsv($fh, $w);
        rewind($fh);
        $out .= (string) stream_get_contents($fh);
        fclose($fh);
    }
    return $out;
}

// ---------------------------------------------------------------- przygotowanie
echo "== klub z templatem (schemat 2, pięć zakładek) ==\n";

$uklad = [];
foreach (['przeglad', 'makro', 'bilans', 'mapy', 'tl_bilans'] as $i => $w) {
    $uklad[] = ['id' => 's' . ($i + 1), 'size' => '1', 'widgets' => [$w], 'title' => ''];
}
$konfig = \CoachAnalyze\Configurator::config([
    ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'STRZAŁ'], 'canon' => null,
     'display_label' => 'Strzały', 'color' => '#E8590C', 'sections' => ['bilans', 'mapy'], 'visible' => true],
    ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'STRATA'], 'canon' => null,
     'display_label' => 'Straty', 'color' => '#8899AA', 'sections' => ['bilans', 'tl_bilans'], 'visible' => true],
    // Bez kanonu w osi SBZ i niewbudowana — przypadek „–" z W1.
    ['id' => 'v_003', 'source' => ['type' => 'tag', 'raw' => 'MOJE SBZ'], 'canon' => null,
     'display_label' => 'Moje SBZ', 'color' => '#A8780A', 'sections' => ['bilans', 'tl_sbz'], 'visible' => true],
], ['bilans', 'mapy', 'tl_bilans', 'tl_sbz'], ['NASZA', 'MASZA'], $uklad);
check('templat v1 klubu 1', ReportTemplates::saveNewVersion(1, $konfig, 1) === 1);
\CoachAnalyze\IgnoredTags::add(1, 'tag', 'POMIJANY', 1);   // świadome „pomiń" sprzed importu

$hash = Auth::hashPassword('bardzo-dlugie-haslo-testowe');
Db::run("INSERT INTO users (email, pass_hash, display_name, role, club_id) VALUES ('trener@example.com', :h, 'Trener', 'viewer', 1)", ['h' => $hash]);

check('analityk się loguje', zaloguj('operator@example.com'));

// ---------------------------------------------------------------- 1. Wgraj
echo "\n== 1. Wgraj: jeden ekran, walidacja w data-*, bez diffu ==\n";

$form = http('GET', '/import');
check('ekran Wgraj odpowiada', $form['status'] === 200);
check('kroki „1 Wgraj · 2 Raport"', str_contains($form['body'], 'class="kroki"')
    && str_contains($form['body'], 'Wgraj') && str_contains($form['body'], 'Raport'));
check('podpowiedź LiveTag.Pro → Eksport → CSV', str_contains($form['body'], 'LiveTag.Pro → Eksport → CSV'));
check('walidacja pliku przez data-*, komunikaty po polsku z pl.php',
    str_contains($form['body'], 'data-wgraj') && str_contains($form['body'], 'data-blad-typu-csv="To nie jest plik CSV'));
check('„Wstecz" to odnośnik, nie przycisk', preg_match('#<a [^>]*>\s*Wstecz\s*</a>#u', $form['body']) === 1);

$upload = http('POST', '/import', ['multipart' => multipart(
    ['csrf' => csrfZ($form['body'])], ['csv' => ['mecz.csv', eksport()]]
)]);
check('upload prowadzi na dokończenie kroku 1 (nie na /zadania, nie na diff)',
    $upload['status'] === 302 && preg_match('#^/import/(\d+)/przygotuj$#', (string) $upload['location'], $mi) === 1,
    (string) $upload['location']);
$importId = (int) ($mi[1] ?? 0);

$czyta = http('GET', '/import/' . $importId . '/przygotuj');
check('przed inspekcją: „Czytam plik", bez formularza', $czyta['status'] === 200
    && str_contains($czyta['body'], 'Czytam plik') && !str_contains($czyta['body'], 'name="played_at"'));

cronDoKonca();

$przyg = http('GET', '/import/' . $importId . '/przygotuj');
check('po inspekcji: formularz rywal/data/wynik', str_contains($przyg['body'], 'name="played_at"')
    && str_contains($przyg['body'], 'name="club_away_id"'));
$rywal = Db::one("SELECT * FROM clubs WHERE name = 'KLUB B ZAMOŚĆ'");
check('rywal podpowiedziany z kolumny drużyn (wybrany na liście)', $rywal !== null
    && preg_match('#<option value="' . (int) $rywal['id'] . '"\s+selected#', $przyg['body']) === 1);
check('ostrzeżenie „podobny klub już istnieje" (Klub B ⊂ KLUB B ZAMOŚĆ)',
    str_contains($przyg['body'], 'Podobny klub już istnieje') && str_contains($przyg['body'], 'Klub B'));
check('jeden przycisk „Wgraj i przygotuj raport" + „około 2 minuty"',
    substr_count($przyg['body'], 'Wgraj i przygotuj raport') === 1
    && str_contains($przyg['body'], 'Raport będzie gotowy za około 2 minuty, możesz zamknąć tę stronę'));
check('wynik opcjonalny „z tagów"', str_contains($przyg['body'], 'z tagów'));
check('skład poza ścieżką', !str_contains($przyg['body'], 'name="sklad'));

$bezDaty = http('POST', '/import/' . $importId . '/przygotuj', ['form' => [
    'csrf' => csrfZ($przyg['body']), 'club_away_id' => (string) $rywal['id'], 'played_at' => '',
]]);
check('bez daty — z powrotem na formularz, z komunikatem',
    $bezDaty['status'] === 302 && $bezDaty['location'] === '/import/' . $importId . '/przygotuj');
check('komunikat o dacie po polsku',
    str_contains(http('GET', '/import/' . $importId . '/przygotuj')['body'], 'Podaj datę meczu'));

$idzie = http('POST', '/import/' . $importId . '/przygotuj', ['form' => [
    'csrf' => csrfZ($przyg['body']), 'club_away_id' => (string) $rywal['id'], 'played_at' => '2026-09-20',
]]);
check('„Wgraj i przygotuj raport" → Postęp',
    $idzie['status'] === 302 && $idzie['location'] === '/import/' . $importId . '/postep',
    (string) $idzie['location']);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports WHERE id = :i', ['i' => $importId]);
$mecz = Db::one('SELECT * FROM matches WHERE id = :m', ['m' => (int) $import['match_id']]);
check('ekran różnic nie był odwiedzony', empty($import['diff_done_at']));
check('meta nie jest pusta: rywal i data zapisane', (int) $mecz['club_away_id'] === (int) $rywal['id']
    && substr((string) $mecz['played_at'], 0, 10) === '2026-09-20');

// ---------------------------------------------------------------- 2. Postęp
echo "\n== 2. Postęp: „czytam plik → buduję raport”, potem raport ==\n";

$postep = http('GET', '/import/' . $importId . '/postep');
check('Postęp odpowiada', $postep['status'] === 200);
check('pasek „Czytam plik → Buduję raport"', str_contains($postep['body'], 'Czytam plik')
    && str_contains($postep['body'], 'Buduję raport'));
check('„Wróć na pulpit"', str_contains($postep['body'], 'Wróć na pulpit'));
check('nagłówek meczu: rywal', str_contains($postep['body'], 'KLUB B ZAMOŚĆ'));

cronDoKonca();

$poBuildzie = http('GET', '/import/' . $importId . '/postep');
check('po zbudowaniu Postęp przenosi na raport',
    $poBuildzie['status'] === 302 && preg_match('#^/raport/(\d+)$#', (string) $poBuildzie['location'], $mr) === 1,
    $poBuildzie['status'] . ' → ' . (string) $poBuildzie['location']);
$raportId = (int) ($mr[1] ?? 0);

// ---------------------------------------------------------------- 3. Raport
echo "\n== 3. Raport: tryb, zakładki, baner informacyjny i ostrzegawczy ==\n";

$raport = http('GET', '/raport/' . $raportId);
check('raport odpowiada', $raport['status'] === 200);
check('tryb odbiorcy: analityk', str_contains($raport['body'], 'data-tryb="analityk"'));
check('znaczniki serwowania wypełnione', !str_contains($raport['body'], '__TRYB__')
    && !str_contains($raport['body'], '__KARTA_URL__') && !str_contains($raport['body'], '__POWROT_URL__'));
ca_test_db($baza);
$v2 = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
$nazwy2 = array_column(array_column($v2['variables'], 'source'), 'raw');
// Golden layout W5: „nie pytaj" (club_ignored_tags) nie zatrzymuje zmiennej —
// POMIJANY też wchodzi do słownika sam.
check('import dopisał WYMYŚLONY TAG i POMIJANY sam (wersja 2)',
    ReportTemplates::currentVersion(1) === 2 && in_array('WYMYŚLONY TAG', $nazwy2, true)
    && in_array('POMIJANY', $nazwy2, true), implode(', ', $nazwy2));

$info = preg_match('#<div class="baner baner--info[^>]*>(.*?)</div>#s', $raport['body'], $mi2) === 1 ? $mi2[1] : '';
check('baner INFORMACYJNY: dodany automatycznie + Słownik + zamknięcie',
    str_contains($info, '2 nowe rodzaje zdarzeń dodane automatycznie:') && str_contains($info, 'WYMYŚLONY TAG')
    && str_contains($info, 'POMIJANY')
    && str_contains($info, 'sprawdź etykiety w Słowniku klubu')
    && str_contains($info, '/klub/ustawienia?zakladka=slownik') && str_contains($info, 'baner__zamknij'), $info);
check('zamknięcie zapamiętane per raport', str_contains($raport['body'], "'ca-baner-info:'+location.pathname"));

// Tabela makro ma wiersz na każdy klucz VARS obecny w zdarzeniach; templat klubu
// dokłada klucze przez `__VARS_TEMPLATU__`.
$vars = preg_match('#Object\.entries\((\{.*?\})\)\.forEach\(\(\[k,v\]\)=>\{VARS\[k\]#s', $raport['body'], $mv) === 1
    ? json_decode($mv[1], true) : null;
$dane = preg_match('#const DATA = (\{.*?\});\n#s', $raport['body'], $md) === 1 ? json_decode($md[1], true) : null;
check('WYMYŚLONY TAG wliczony w tabeli makro (klucz VARS + zdarzenia w danych)',
    is_array($vars) && isset($vars['WYMYŚLONY TAG'])
    && is_array($dane) && count(array_filter($dane['events'], static fn($e) => ($e['tag'] ?? '') === 'WYMYŚLONY TAG')) === 2);

$ostrz = preg_match('#<div class="baner baner--niewliczone[^>]*>(.*?)</div>#s', $raport['body'], $mo) === 1 ? $mo[1] : '';
check('baner OSTRZEGAWCZY: wyłącznie zmienna bez znaczenia (W5: bez „pominiętych")',
    !str_contains($ostrz, 'POMIJANY') && str_contains($ostrz, 'MOJE SBZ — bez znaczenia: oś SBZ')
    && str_contains($ostrz, 'Wlicz w Słowniku klubu'), $ostrz);
check('nowy (wliczony) tag NIE jest w ostrzeżeniu', !str_contains($ostrz, 'WYMYŚLONY'));
check('kolejność zakładek z Układu', kolejnosc($raport['body']) === ['przeglad', 'makro', 'bilans', 'mapy', 'tl_bilans'],
    implode(',', kolejnosc($raport['body'])));

// ---------------------------------------------------------------- 4. Słownik
echo "\n== 4. Słownik klubu: Nierozpoznane = wyłącznie bez znaczenia (W5) ==\n";

$slownik = http('GET', '/klub/ustawienia?zakladka=slownik');
check('Słownik odpowiada', $slownik['status'] === 200);
check('Nierozpoznane (1): MOJE SBZ, z powodem', str_contains($slownik['body'], 'Nierozpoznane (1)')
    && preg_match('#name="wlicz_nazwa\[\d+\]" value="POMIJANY"#', $slownik['body']) !== 1
    && str_contains($slownik['body'], 'bez znaczenia w: Oś SBZ'));
check('nowy tag NIE jest „nierozpoznany" — jest w Wliczanych', str_contains($slownik['body'], 'Wliczane (5)')
    && preg_match('#name="wlicz_nazwa\[\d+\]" value="WYMYŚLONY TAG"#', $slownik['body']) !== 1
    && str_contains($slownik['body'], 'value="WYMYŚLONY TAG"'));
check('kontynuacja zmiennej jako opcja', str_contains($slownik['body'], 'kontynuacja zmiennej Strzały'));
check('[op] Zaawansowane niewidoczne dla analityka', !str_contains($slownik['body'], 'zakladka=zaawansowane'));
check('analityk nie wejdzie w Zaawansowane (spada na Układ)',
    !str_contains(http('GET', '/klub/ustawienia?zakladka=zaawansowane')['body'], 'Historia wersji'));

$iWym = (string) array_search('WYMYŚLONY TAG', $nazwy2, true);
$zapis = http('POST', '/klub/ustawienia/slownik', ['form' => [
    'csrf' => csrfZ($slownik['body']),
    'wlicz' => ['0' => 'bilans', '1' => 'nowa'],
    'wlicz_nazwa' => ['0' => 'MOJE SBZ', '1' => 'PODRZUCONY'],   // spoza listy — bez skutku
    'etykieta' => [$iWym => 'Wymyślony'],
    'sekcje' => [$iWym => ['bilans']],
]]);
check('zapis → Słownik z partią odświeżania',
    $zapis['status'] === 302 && str_starts_with((string) $zapis['location'], '/klub/ustawienia?zakladka=slownik&partia='),
    (string) $zapis['location']);
ca_test_db($baza);
$v3 = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
$poRaw = [];
foreach ($v3['variables'] as $z) { $poRaw[$z['source']['raw']] = $z; }
check('wersja 3: POMIJANY w słowniku, MOJE SBZ bez osi SBZ, etykieta „Wymyślony"',
    ReportTemplates::currentVersion(1) === 3
    && isset($poRaw['POMIJANY'])
    && ($poRaw['MOJE SBZ']['sections'] ?? null) === ['bilans']
    && ($poRaw['WYMYŚLONY TAG']['display_label'] ?? '') === 'Wymyślony'
    && !isset($poRaw['PODRZUCONY']));
// W5: „nie pytaj" zostaje jako wyciszenie pytań — i niczego już nie chowa.
check('„nie pytaj" o POMIJANY zostaje, bez wpływu na raport', !empty(\CoachAnalyze\IgnoredTags::lookup(1)['tag']['POMIJANY']));

cronDoKonca();
$raport2 = http('GET', '/raport/' . $raportId);
check('po odświeżeniu w tle ostrzeżenia nie ma', $raport2['status'] === 200
    && !str_contains($raport2['body'], 'class="baner baner--niewliczone'));
check('baner informacyjny zostaje (zamyka go analityk)', str_contains($raport2['body'], 'class="baner baner--info'));
$chmurka = Db::one("SELECT * FROM notifications WHERE entity = 'club' AND entity_id = 1 ORDER BY id DESC LIMIT 1");
check('chmurka po zakończeniu odświeżania, adres dostępny dla analityka (nie [op])',
    $chmurka !== null && str_starts_with((string) $chmurka['url'], '/klub/ustawienia?partia='),
    (string) ($chmurka['url'] ?? ''));
check('wskaźnik partii z chmurki otwiera się analitykowi',
    $chmurka !== null && http('GET', (string) $chmurka['url'])['status'] === 200);
$zakazaneW3 = [];
foreach (['/import', '/import/' . $importId . '/postep', '/klub/ustawienia?zakladka=uklad',
          '/klub/ustawienia?zakladka=slownik', (string) ($chmurka['url'] ?? '/pulpit')] as $adres) {
    $t = mb_strtolower(html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#si', ' ',
        http('GET', $adres)['body']) ?? ''), ENT_QUOTES, 'UTF-8'));
    foreach (['silnik', 'templat', 'szablon v', 'kolejce', 'kolejka zadań', 'dysk', 'pojęcie kanoniczne',
              'przelicz', 'wygeneruj ponownie', 'kod klubu'] as $slowo) {
        if (str_contains($t, $slowo)) { $zakazaneW3[] = $adres . ': ' . $slowo; }
    }
}
check('ekrany W3 analityka bez słów technicznych (lista z W2)', $zakazaneW3 === [], implode('; ', $zakazaneW3));

// ---------------------------------------------------------------- 5. Układ
echo "\n== 5. Układ raportu: sekcja 04 → 02 ==\n";

$ukl = http('GET', '/klub/ustawienia?zakladka=uklad');
check('Układ odpowiada, numery 01–05', $ukl['status'] === 200
    && str_contains($ukl['body'], '>01<') && str_contains($ukl['body'], '>05<'));
check('Przegląd bez strzałek i bez „Ukryj"', str_contains($ukl['body'], 'zawsze pierwszy i widoczny')
    && !str_contains($ukl['body'], 'value="ukryj:0"'));
// W5: „Inne zdarzenia" jak każdy kafel — do pokazania zawsze, decyduje Układ.
check('„Inne zdarzenia" do pokazania w Układzie (W5)', str_contains($ukl['body'], 'Inne zdarzenia'));

$c = csrfZ($ukl['body']);
http('POST', '/klub/ustawienia/uklad', ['form' => ['csrf' => $c, 'akcja' => 'gora:3']]);
http('POST', '/klub/ustawienia/uklad', ['form' => ['csrf' => $c, 'akcja' => 'gora:2']]);
http('POST', '/klub/ustawienia/uklad', ['form' => ['csrf' => $c, 'akcja' => 'gora:1']]);   // Przegląd nie ustępuje
http('POST', '/klub/ustawienia/uklad', ['form' => ['csrf' => $c, 'akcja' => 'ukryj:0']]);  // Przegląd nie znika
$zUkl = http('POST', '/klub/ustawienia/uklad', ['form' => ['csrf' => $c, 'akcja' => 'zapisz']]);
check('zapis układu → partia przeliczeń',
    $zUkl['status'] === 302 && str_contains((string) $zUkl['location'], 'partia='), (string) $zUkl['location']);
ca_test_db($baza);
$v3 = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
check('templat v4: mapy na 02, Przegląd na 01',
    ReportTemplates::currentVersion(1) === 4
    && \CoachAnalyze\ReportLayout::widgety($v3['sections']) === ['przeglad', 'mapy', 'makro', 'bilans', 'tl_bilans'],
    implode(',', \CoachAnalyze\ReportLayout::widgety($v3['sections'])));

cronDoKonca();
$raport3 = http('GET', '/raport/' . $raportId);
check('po przeliczeniu zakładki raportu w nowej kolejności',
    kolejnosc($raport3['body']) === ['przeglad', 'mapy', 'makro', 'bilans', 'tl_bilans'],
    implode(',', kolejnosc($raport3['body'])));

// ---------------------------------------------------------------- 6. Trener
echo "\n== 6. Trener: odczyt ==\n";

check('trener się loguje', zaloguj('trener@example.com'));
check('trener: /import = 404', http('GET', '/import')['status'] === 404);
check('trener: Ustawienia klubu = 404', http('GET', '/klub/ustawienia')['status'] === 404);
check('trener: zapis słownika = 404', http('POST', '/klub/ustawienia/slownik', ['form' => ['csrf' => 'x']])['status'] === 404);
$rt = http('GET', '/raport/' . $raportId);
check('trener: raport w trybie „trener"', $rt['status'] === 200 && str_contains($rt['body'], 'data-tryb="trener"'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
