<?php
declare(strict_types=1);

/**
 * Golden layout W0 — nawigacja i karta meczu, PRZELOT HTTP.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ODBIÓR W0 Z ZAMÓWIENIA, KROK PO KROKU, BEZ PRZYCISKU „WSTECZ" PRZEGLĄDARKI:
 *   (a) Pulpit → Ostatni mecz → Otwórz raport → „← CA" → karta meczu → okruszki,
 *   (b) Sezon → k. 4 → Dane meczu → zmiana daty → Zapisz → nadal na karcie.
 *
 * Do tego to, czego odbiór ręczny nie złapie:
 *   - klips pod linkiem publicznym prowadzi na coachanalyze.pl, nie do panelu,
 *   - strona zadania i zakładki [op] tylko dla administratora; analityk
 *     dostaje 404 na /zadania/{id} — to samo, co przy nieistniejącym zadaniu,
 *   - lista raportów: wiersz = mecz, bez kolumn technicznych dla analityka,
 *   - `?powrot=` przepuszcza wyłącznie ścieżkę wewnętrzną, nigdy /kluby.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php test_golden_w0_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Powrot;

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

$baza    = $here . '/golden_w0.sqlite';
$magazyn = $here . '/golden_w0_storage';
$logFile = $here . '/golden_w0.log';
$envFile = $here . '/.env.golden_w0';
$sock    = $here . '/golden_w0_redis.sock';
$port    = 9061;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/reports', 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=w0:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 = tenant „Klub A", klub 2 = „Klub B"

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

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

function zaloguj(string $email, string $haslo): bool
{
    global $ciasteczka;
    $ciasteczka = [];
    $login = http('GET', '/login');
    $r = http('POST', '/login', ['form' => ['email' => $email, 'password' => $haslo, 'csrf' => csrfZ($login['body'])]]);
    return $r['status'] === 302 && $r['location'] === '/pulpit';
}

/** Okruszki z nagłówka strony. */
function okruszki(string $html): string
{
    return preg_match('#<nav class="crumbs".*?</nav>#s', $html, $m) === 1 ? $m[0] : '';
}

// ---------------------------------------------------------------- dane
$sezon = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];
Db::run("INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at, round, status)
         VALUES (1, 1, :s, 1, 2, '2026-08-16', '4', 'done')", ['s' => $sezon]);
$mecz = (int) Db::pdo()->lastInsertId();
$html = $magazyn . '/reports/' . bin2hex(random_bytes(6)) . '.html';
// Raport w kształcie v21 — klips ze znacznikiem serwowania, jak z silnika 0.16.4.
file_put_contents($html, '<!DOCTYPE html><html><body><a class="ca-klips" href="__POWROT_URL__">'
    . '<span class="ca-klips__strz">←</span>CA</a><p>RAPORT-TESTOWY</p></body></html>');
Db::run("INSERT INTO reports (match_id, club_id, template_version, html_path, engine_version, generated_at)
         VALUES (:m, 1, 1, :h, '0.16.4', '2026-08-17 10:00:00')", ['m' => $mecz, 'h' => $html]);
$raport = (int) Db::pdo()->lastInsertId();
Db::run("INSERT INTO share_links (report_id, club_id, token) VALUES (:r, 1, :t)",
    ['r' => $raport, 't' => str_repeat('ab', 16)]);
Db::run("INSERT INTO jobs (type, payload_json, status) VALUES ('build_report', :p, 'done')",
    ['p' => json_encode(['match_id' => $mecz])]);
$zadanie = (int) Db::pdo()->lastInsertId();

check('analityk (rola operator) się loguje i ląduje na pulpicie',
    zaloguj('operator@example.com', 'bardzo-dlugie-haslo-testowe'));

// ===========================================================================
echo "\n== (a) Pulpit → ostatni mecz → raport → „← CA\" → karta → okruszki ==\n";

$pulpit = http('GET', '/pulpit');
check('pulpit odpowiada', $pulpit['status'] === 200);
check('pulpit prowadzi do karty ostatniego meczu', str_contains($pulpit['body'], 'href="/mecze/' . $mecz . '"'));

$karta = http('GET', '/mecze/' . $mecz);
check('karta meczu odpowiada', $karta['status'] === 200, 'status ' . $karta['status']);
check('nagłówek karty: k. 4 · data', str_contains($karta['body'], 'k. 4') && str_contains($karta['body'], '2026-08-16'));
check('karta: pastylka „Raport gotowy"', str_contains($karta['body'], 'Raport gotowy'));
check('karta: „Otwórz raport" prowadzi do raportu', str_contains($karta['body'], 'href="/raport/' . $raport . '"'));
check('karta: „Slajdy" otwiera raport z #slajdy', str_contains($karta['body'], '/raport/' . $raport . '#slajdy'));

$raportHtml = http('GET', '/raport/' . $raport);
check('raport odpowiada', $raportHtml['status'] === 200 && str_contains($raportHtml['body'], 'RAPORT-TESTOWY'));
check('klips „← CA" wraca na kartę meczu', str_contains($raportHtml['body'], 'class="ca-klips" href="/mecze/' . $mecz . '"'));
check('w raporcie nie zostaje znacznik serwowania', !str_contains($raportHtml['body'], '__POWROT_URL__'));

$okr = okruszki($karta['body']);
check('okruszki karty: Pulpit → Sezon → k. 4',
    str_contains($okr, 'href="/pulpit"') && str_contains($okr, 'href="/sezon') && str_contains($okr, 'k. 4'), $okr);
check('okruszki nigdy do /kluby ani /zadania', !str_contains($okr, '/kluby') && !str_contains($okr, '/zadania'));

// ===========================================================================
echo "\n== (b) Sezon → k. 4 → Dane meczu → zmiana daty → Zapisz ==\n";

$sezonEkran = http('GET', '/sezon');
check('sezon odpowiada', $sezonEkran['status'] === 200);
check('wiersz k. 4 prowadzi na kartę meczu', str_contains($sezonEkran['body'], 'href="/mecze/' . $mecz . '"'));

$dane = http('GET', '/mecze/' . $mecz . '?zakladka=dane');
check('zakładka „Dane meczu" ma formularz z polem daty', str_contains($dane['body'], 'name="played_at"'));
$zapis = http('POST', '/mecze/' . $mecz . '/meta', ['form' => [
    'csrf' => csrfZ($dane['body']), 'powrot' => '/mecze/' . $mecz . '?zakladka=dane',
    'played_at' => '2026-08-23', 'round' => '4', 'season_id' => (string) $sezon,
    'akcja' => 'zapisz',
]]);
check('Zapisz zostaje na karcie meczu (zakładka „Dane meczu")',
    $zapis['status'] === 302 && $zapis['location'] === '/mecze/' . $mecz . '?zakladka=dane', (string) $zapis['location']);
$po = http('GET', (string) $zapis['location']);
check('nowa data widoczna na karcie', str_contains($po['body'], '2026-08-23'));
check('zapis mety nie ruszył składu (osobne formularze)',
    (int) Db::one('SELECT COUNT(*) AS c FROM match_players WHERE match_id = :m', ['m' => $mecz])['c'] === 0);

// ===========================================================================
echo "\n== link publiczny: klips na coachanalyze.pl ==\n";

$publiczny = http('GET', '/r/HUT7K2QX/' . str_repeat('ab', 16));
check('raport publiczny odpowiada', $publiczny['status'] === 200, 'status ' . $publiczny['status']);
check('klips prowadzi na coachanalyze.pl', str_contains($publiczny['body'], 'class="ca-klips" href="https://coachanalyze.pl"'));
check('klips publiczny nie prowadzi do panelu', !str_contains($publiczny['body'], 'href="/mecze/'));

// ===========================================================================
echo "\n== [op]: strona zadania i zakładki techniczne ==\n";

check('analityk: /zadania/{id} daje 404', http('GET', '/zadania/' . $zadanie)['status'] === 404);
check('analityk: karta bez zakładek Pokrycie/Wersje/Zadania',
    !str_contains($karta['body'], 'zakladka=zadania') && !str_contains($karta['body'], 'zakladka=wersje'));
check('analityk: ?zakladka=zadania schodzi na „Dane meczu"',
    !str_contains(http('GET', '/mecze/' . $mecz . '?zakladka=zadania')['body'], 'Historia meczu'));
check('analityk: karta nie linkuje do /zadania', !str_contains($karta['body'], 'href="/zadania/'));
check('stara historia meczu przekierowuje analityka na kartę',
    http('GET', '/mecze/' . $mecz . '/historia')['location'] === '/mecze/' . $mecz);

$lista = http('GET', '/raporty');
check('lista raportów: nazwa meczu → karta meczu', str_contains($lista['body'], 'href="/mecze/' . $mecz . '"'));
check('lista raportów: „Otwórz raport" w wierszu', str_contains($lista['body'], 'href="/raport/' . $raport . '"'));
check('lista raportów: analityk nie widzi „Wygeneruj ponownie" ani kolumny silnika',
    !str_contains($lista['body'], '/ponow')
    && !str_contains($lista['body'], '<th scope="col">' . \CoachAnalyze\View::t('reports.col.engine') . '</th>'));
check('lista raportów: filtry bez przycisku „Pokaż"', !str_contains($lista['body'], '>Pokaż<') && str_contains($lista['body'], 'class="fchip'));

Db::run("UPDATE users SET role = 'admin' WHERE email = 'operator@example.com'");
check('administrator się loguje', zaloguj('operator@example.com', 'bardzo-dlugie-haslo-testowe'));
check('administrator: /zadania/{id} odpowiada', http('GET', '/zadania/' . $zadanie)['status'] === 200);
$kartaOp = http('GET', '/mecze/' . $mecz . '?zakladka=zadania');
check('administrator: zakładka Zadania z odsyłaczem do zadania', str_contains($kartaOp['body'], 'href="/zadania/' . $zadanie . '"'));
check('administrator: zakładka Wersje raportu', str_contains(http('GET', '/mecze/' . $mecz . '?zakladka=wersje')['body'], '0.16.4'));
check('administrator: lista raportów z „Wygeneruj ponownie"', str_contains(http('GET', '/raporty')['body'], '/raport/' . $raport . '/ponow'));

// ===========================================================================
echo "\n== ?powrot= — wyłącznie ścieżka wewnętrzna ==\n";

check('ścieżka wewnętrzna przechodzi', Powrot::bezpieczna('/mecze/1?zakladka=dane') === '/mecze/1?zakladka=dane');
foreach (['//evil.com', '/\\evil.com', 'https://evil.com', 'mecze/1', '/kluby', '/kluby/3', '/zadania/5', "/x\n"] as $zly) {
    check('odrzucone: ' . json_encode($zly), Powrot::bezpieczna($zly) === null);
}
check('„/" przekierowuje na /pulpit', http('GET', '/')['location'] === '/pulpit');

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
