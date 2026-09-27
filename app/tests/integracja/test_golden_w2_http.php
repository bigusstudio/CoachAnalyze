<?php
declare(strict_types=1);

/**
 * Golden layout W2 — role i porządek, PRZELOT HTTP NA TRZECH ROLACH.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *   Administrator (admin)  — widzi wszystko, w tym [op],
 *   Analityk (operator)    — kluby swojego konta (`clubs.owner_id`), bez [op],
 *   Trener (viewer)        — DOKŁADNIE jeden klub (`users.club_id`), odczyt.
 *
 * Cudzy zasób = 404 (to samo co nieistniejący). Filtr klubu siedzi w zapytaniu
 * i w strażniku routera, nie w menu — dlatego test wchodzi adresem wprost.
 *
 * Konto klubowe (analityk, trener) NIE WIDZI na żadnym swoim ekranie słów:
 * silnik, templat, szablon vN, kolejka (zadań), dysk, pojęcie kanoniczne,
 * Przelicz, Wygeneruj ponownie, kod klubu.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  php test_golden_w2_http.php
 */

use CoachAnalyze\Auth;
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

$baza    = $here . '/golden_w2.sqlite';
$magazyn = $here . '/golden_w2_storage';
$logFile = $here . '/golden_w2.log';
$envFile = $here . '/.env.golden_w2';
$sock    = $here . '/golden_w2_redis.sock';
$port    = 9071;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/reports', 0770, true);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=w2:',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 i 4 = tenanci konta 1 (analityk), 2 i 3 = rywale

$procesy = [];
$procesy[] = proc_open('php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r1);
for ($i = 0; $i < 50 && !file_exists($sock); $i++) { usleep(100000); }
$procesy[] = proc_open('php -S 127.0.0.1:' . $port . ' ' . escapeshellarg($root . '/app/public/index.php'),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $r2);
for ($i = 0; $i < 50; $i++) {
    $p = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
    if (is_resource($p)) { fclose($p); break; }
    usleep(100000);
}
register_shutdown_function(static function () use ($procesy, $baza, $envFile, $logFile, $sock, $magazyn): void {
    foreach ($procesy as $p) { if (is_resource($p)) { proc_terminate($p); proc_close($p); } }
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
        elseif (stripos($h, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $h, $m) === 1) {
            $ciasteczka[trim($m[1])] = trim($m[2]);
        }
    }
    return ['status' => $status, 'location' => $location, 'body' => (string) $body];
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

function zaloguj(string $email): bool
{
    global $ciasteczka;
    $ciasteczka = [];
    $login = http('GET', '/login');
    $r = http('POST', '/login', ['form' => ['email' => $email, 'password' => 'bardzo-dlugie-haslo-testowe',
        'csrf' => csrfZ($login['body'])]]);
    return $r['status'] === 302 && $r['location'] === '/pulpit';
}

/** Tekst widoczny na stronie: bez skryptów, stylów i znaczników. */
function tekst(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $html) ?? $html;
    return mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

const ZAKAZANE = ['silnik', 'templat', 'szablon v', 'kolejce', 'kolejka zadań', 'dysk',
                  'pojęcie kanoniczne', 'przelicz', 'wygeneruj ponownie', 'kod klubu'];

/** @return list<string> zakazane słowa znalezione na stronie */
function zakazane(string $html): array
{
    $t = tekst($html);
    return array_values(array_filter(ZAKAZANE, static fn(string $s): bool => str_contains($t, $s)));
}

// ---------------------------------------------------------------- dane
$hash = Auth::hashPassword('bardzo-dlugie-haslo-testowe');
Db::run("INSERT INTO users (email, pass_hash, display_name, role) VALUES ('admin@example.com', :h, 'Tomas', 'admin')", ['h' => $hash]);
Db::run("INSERT INTO users (email, pass_hash, display_name, role, club_id) VALUES ('trener@example.com', :h, 'Trener', 'viewer', 1)", ['h' => $hash]);
Db::run("INSERT INTO users (email, pass_hash, display_name, role) VALUES ('obcy@example.com', :h, 'Obcy', 'operator')", ['h' => $hash]);
$sezon = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];

$mecz = static function (int $klub, string $round, string $data) use ($sezon, $magazyn): array {
    Db::run("INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at, round, is_home, status)
             VALUES (1, :k, :s, :k2, 2, :d, :r, 1, 'done')", ['k' => $klub, 'k2' => $klub, 's' => $sezon, 'd' => $data, 'r' => $round]);
    $m = (int) Db::pdo()->lastInsertId();
    $html = $magazyn . '/reports/' . bin2hex(random_bytes(5)) . '.html';
    file_put_contents($html, '<html><body><a class="ca-klips" href="__POWROT_URL__">CA</a>RAPORT ' . $m . '</body></html>');
    Db::run("INSERT INTO reports (match_id, club_id, html_path, engine_version, generated_at) VALUES (:m, :k, :h, '0.16.5', :g)",
        ['m' => $m, 'k' => $klub, 'h' => $html, 'g' => $data . ' 20:00:00']);
    $r = (int) Db::pdo()->lastInsertId();
    foreach ([[1, 'STRZAŁ', 0.3, 0], [2, 'STRZAŁ', 0.2, 1], [3, 'ZDOBYCIE SBZ', null, 0]] as [$t, $tag, $xg, $gol]) {
        Db::run("INSERT INTO events (match_id, tag_name, team_side, t_ms, half, minute, xg, is_goal, player)
                 VALUES (:m, :t, 'us', :ms, 1, 1, :xg, :g, 'Kowalski Jan')", ['m' => $m, 't' => $tag, 'ms' => $t * 1000, 'xg' => $xg, 'g' => $gol]);
    }
    Db::run("INSERT INTO match_players (match_id, club_id, player, number, position, minutes, is_starter)
             VALUES (:m, :k, 'Kowalski Jan', 9, 'NAP', 90, 1)", ['m' => $m, 'k' => $klub]);
    return ['mecz' => $m, 'raport' => $r];
};
$a = $mecz(1, '4', '2026-08-16');
$d = $mecz(4, '2', '2026-08-09');
Db::run("INSERT INTO notifications (user_id, type, title, entity, entity_id, url) VALUES (1, 'report.ready', 'Raport gotowy', 'report', :r, :u)",
    ['r' => $a['raport'], 'u' => '/raport/' . $a['raport']]);
Db::run("INSERT INTO notifications (user_id, type, title, entity, entity_id, url) VALUES (1, 'import.pending', 'Przetwarzanie', 'match', :m, :u)",
    ['m' => $a['mecz'], 'u' => '/mecze/' . $a['mecz']]);

// ===========================================================================
echo "== Trener klubu 1: tylko jego klub ==\n";
check('trener się loguje', zaloguj('trener@example.com'));
check('trener: swój mecz odpowiada', http('GET', '/mecze/' . $a['mecz'])['status'] === 200);
check('trener: mecz klubu 4 daje 404', http('GET', '/mecze/' . $d['mecz'])['status'] === 404);
check('trener: raport klubu 4 daje 404', http('GET', '/raport/' . $d['raport'])['status'] === 404);
check('trener: ?klub=4 na sezonie daje 404', http('GET', '/sezon?klub=4')['status'] === 404);
check('trener: nieistniejący mecz — to samo 404', http('GET', '/mecze/99999')['status'] === 404);
foreach (['/import', '/klub/ustawienia', '/kluby', '/uzytkownicy', '/zadania', '/sezony', '/klub/wybierz', '/mecze/' . $a['mecz'] . '/wgraj'] as $sc) {
    check("trener: {$sc} daje 404", http('GET', $sc)['status'] === 404, (string) http('GET', $sc)['status']);
}
$pulpitT = http('GET', '/pulpit');
check('trener: pulpit bez „Wgraj eksport" i „Ustawień klubu" w szynie',
    !str_contains($pulpitT['body'], 'href="/import"') && !str_contains($pulpitT['body'], 'href="/klub/ustawienia"'));
check('trener: kontekst klubu bez przełączania', !str_contains($pulpitT['body'], 'href="/klub/wybierz"'));
$listaT = http('GET', '/raporty');
check('trener: lista raportów bez meczu klubu 4',
    str_contains($listaT['body'], '/mecze/' . $a['mecz']) && !str_contains($listaT['body'], '/mecze/' . $d['mecz']));
check('trener: wyszukiwarka meczów bez klubu 4', !str_contains(http('GET', '/mecze')['body'], 'href="/mecze/' . $d['mecz'] . '"'));

$ekranyKlubu = ['/pulpit', '/sezon', '/sezon/suma', '/mecze/' . $a['mecz'], '/mecze/' . $a['mecz'] . '?zakladka=sklad',
    '/mecze/' . $a['mecz'] . '?zakladka=pliki', '/raporty', '/zawodnicy', '/druzyna', '/kalendarz', '/notatki',
    '/indeks', '/xg', '/linki', '/powiadomienia', '/konto', '/mecze'];
foreach ($ekranyKlubu as $sc) {
    $z = zakazane(http('GET', $sc)['body']);
    check("trener: {$sc} bez słów technicznych", $z === [], implode(', ', $z));
}

// ===========================================================================
echo "\n== Analityk: oba kluby swojego konta, bez [op] ==\n";
check('analityk się loguje', zaloguj('operator@example.com'));
check('analityk: mecz klubu 1 odpowiada', http('GET', '/mecze/' . $a['mecz'])['status'] === 200);
check('analityk: mecz klubu 4 odpowiada (drugi klub konta)', http('GET', '/mecze/' . $d['mecz'])['status'] === 200);
$wybor = http('GET', '/klub/wybierz');
check('analityk: wybór klubu pokazuje oba kluby', str_contains($wybor['body'], 'Klub A') && str_contains($wybor['body'], 'Klub D'));
$prz = http('POST', '/klub/4/wybierz', ['form' => ['csrf' => csrfZ($wybor['body'])]]);
check('analityk: przełączenie na klub 4', $prz['status'] === 302 && $prz['location'] === '/pulpit');
$pulpitA = http('GET', '/pulpit');
check('analityk: szyna mówi o klubie 4 w jednej linii', str_contains($pulpitA['body'], 'class="ctx__linia"><b>Klub D</b>'));
check('analityk: pulpit klubu 4 prowadzi do jego meczu', str_contains($pulpitA['body'], 'href="/mecze/' . $d['mecz'] . '"'));
check('analityk: bez „Wymaga uwagi" i bez stopki technicznej',
    !str_contains($pulpitA['body'], 'Wymaga uwagi') && !str_contains(tekst($pulpitA['body']), 'silnik'));
check('analityk: szyna bez „Mecze" (dubluje Sezon)', !preg_match('#class="it[^"]*" href="/mecze"#', $pulpitA['body']));
check('analityk: szyna z Ustawieniami klubu i Drużyną',
    str_contains($pulpitA['body'], 'href="/klub/ustawienia"') && str_contains($pulpitA['body'], 'href="/druzyna"'));
foreach (['/kluby', '/uzytkownicy', '/zadania', '/klub/1/templaty'] as $sc) {
    check("analityk: [op] {$sc} daje 404", http('GET', $sc)['status'] === 404);
}
check('analityk: hub klubu przełącza klub i wraca na pulpit', http('GET', '/klub/1')['location'] === '/pulpit');
$ust = http('GET', '/klub/ustawienia');
check('analityk: Ustawienia klubu bez Zaawansowanych [op]', $ust['status'] === 200 && !str_contains($ust['body'], 'Zaawansowane'));
foreach (array_merge($ekranyKlubu, ['/klub/ustawienia', '/import']) as $sc) {
    $z = zakazane(http('GET', $sc)['body']);
    check("analityk: {$sc} bez słów technicznych", $z === [], implode(', ', $z));
}

echo "\n== Analityk innego konta: nic z klubów 1 i 4 ==\n";
check('obcy analityk się loguje', zaloguj('obcy@example.com'));
check('obcy analityk: mecz klubu 1 daje 404', http('GET', '/mecze/' . $a['mecz'])['status'] === 404);
check('obcy analityk: lista raportów pusta', !str_contains(http('GET', '/raporty')['body'], 'href="/raport/'));

// ===========================================================================
echo "\n== Administrator: [op] ==\n";
check('administrator się loguje', zaloguj('admin@example.com'));
$pulpitO = http('GET', '/pulpit');
check('administrator: ADMINISTRACJA — Kluby, Użytkownicy, Zadania',
    str_contains($pulpitO['body'], 'href="/kluby"') && str_contains($pulpitO['body'], 'href="/uzytkownicy"')
    && str_contains($pulpitO['body'], 'href="/zadania"'));
check('administrator: stopka z silnikiem, szablonem, kolejką i dyskiem',
    preg_match('#class="foot">.*Silnik.*szablon v21.*W kolejce.*dysk#s', $pulpitO['body']) === 1);
check('administrator: „Wymaga uwagi" na pulpicie', str_contains($pulpitO['body'], 'Wymaga uwagi'));
check('administrator: lista zadań', http('GET', '/zadania')['status'] === 200);
check('administrator: mecz dowolnego klubu', http('GET', '/mecze/' . $d['mecz'])['status'] === 200);
$uz = http('GET', '/uzytkownicy');
check('ekran Użytkownicy: nazwy ról Administrator / Analityk / Trener',
    str_contains($uz['body'], 'Administrator') && str_contains($uz['body'], 'Analityk') && str_contains($uz['body'], 'Trener'));
$bezKlubu = http('POST', '/uzytkownicy', ['form' => ['csrf' => csrfZ($uz['body']), 'email' => 't2@example.com',
    'display_name' => 'Trener 2', 'role' => 'viewer', 'club_id' => '']]);
check('trener bez klubu: odmowa', Db::one("SELECT id FROM users WHERE email = 't2@example.com'") === null);
http('POST', '/uzytkownicy', ['form' => ['csrf' => csrfZ(http('GET', '/uzytkownicy')['body']), 'email' => 't3@example.com',
    'display_name' => 'Trener 3', 'role' => 'viewer', 'club_id' => '4']]);
check('trener z klubem: konto ma club_id', (int) (Db::one("SELECT club_id FROM users WHERE email = 't3@example.com'")['club_id'] ?? 0) === 4);

// ===========================================================================
echo "\n== Chmurki, Drużyna, Sezon ==\n";
zaloguj('operator@example.com');
http('POST', '/klub/1/wybierz', ['form' => ['csrf' => csrfZ(http('GET', '/klub/wybierz')['body'])]]);
http('GET', '/raport/' . $a['raport']);
$nowe = json_decode(http('GET', '/powiadomienia/nowe')['body'], true);
$tytuly = array_column($nowe['items'] ?? [], 'title');
check('„Raport gotowy" znika po otwarciu raportu', !in_array('Raport gotowy', $tytuly, true), json_encode($tytuly));
check('„w toku" znika, gdy praca się skończyła', !in_array('Przetwarzanie', $tytuly, true), json_encode($tytuly));
$js = (string) file_get_contents($root . '/app/public/assets/powiadomienia.js');
check('chmurki informacyjne znikają po 8 s, błąd zostaje',
    str_contains($js, 'var ZYCIE_CHMURKI = 8000;') && str_contains($js, "var ZOSTAJE = 'chmurka--failed';"));

$druzyna = http('GET', '/druzyna');
check('Drużyna: kadra z numerem, pozycją i minutami',
    str_contains($druzyna['body'], 'Kowalski Jan') && str_contains($druzyna['body'], 'NAP') && str_contains($druzyna['body'], '>90<'));
$sezonEkran = http('GET', '/sezon');
check('Sezon: kolumny SBZ, pressing i stan raportu', str_contains($sezonEkran['body'], 'Pressing') && str_contains($sezonEkran['body'], 'Raport gotowy'));
check('Sezon: wiersz SUMA', str_contains($sezonEkran['body'], 'SUMA'));
check('Sezon: „k. 4" i klik w kartę meczu', str_contains($sezonEkran['body'], 'k. 4') && str_contains($sezonEkran['body'], 'href="/mecze/' . $a['mecz'] . '"'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
