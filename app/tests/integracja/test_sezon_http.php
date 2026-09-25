<?php
declare(strict_types=1);

/**
 * Menu sezonowe — PRZELOT HTTP (sesja 7 pivotu „viewer").
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CO TEN ZESTAW MA UDOWODNIĆ
 *
 * 1. LISTA KOLEJEK CZYTA SIĘ OD PIERWSZEJ. To nie jest „ostatnie mecze"
 *    odwrócone — sortowanie idzie po kolejce, a przy jej braku po dacie.
 * 2. SUMA ZGADZA SIĘ Z SUMĄ KOLEJEK. Zestawienie sezonu to ta sama definicja
 *    metryki bez filtra meczu; liczba, która nie sumuje się z wierszy
 *    widocznych obok, jest gorsza niż jej brak.
 * 3. ZAWODNICY SCALAJĄ SKŁAD ZE ZDARZENIAMI po pełnej nazwie, a brak składu
 *    daje KRESKĘ, nie zero minut.
 * 4. CUDZY KLUB TO 403. Zalogowany użytkownik i tak wie, że ekran istnieje —
 *    ukrywanie go niczego nie chroni, a 403 mówi wprost, o co chodzi.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_sezon_http.php
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

$baza    = $here . '/sezon.sqlite';
$magazyn = $here . '/sezon_storage';
$logFile = $here . '/sezon.log';
$envFile = $here . '/.env.sezon';
$sock    = $here . '/sezon_redis.sock';
$port    = 9041;

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
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=sezon:',
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







// ============================================================ A. logowanie
echo "== A. logowanie ==\n";
$form = http('GET', '/login');
$zaloguj = http('POST', '/login', ['form' => [
    'csrf' => csrfZ($form['body']),
    'email' => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe',
]]);
check('zalogowano', $zaloguj['status'] === 302, 'status ' . $zaloguj['status']);

// ============================================================ B. dane sezonu
echo "\n== B. sezon z dwoma meczami ==\n";

ca_test_db($baza);
/*
 * SEZON WŁASNY, ODRĘBNY OD SEEDOWEGO. Seed zakłada klubowi mecze w sezonie
 * bieżącym; gdybyśmy dopisali swoje do niego, każda suma w tym zestawie
 * liczyłaby też cudze wiersze i test sprawdzałby dane seeda, nie kod.
 */
Db::run("INSERT INTO seasons (label, date_from, date_to, is_current)
         VALUES ('2030/2031', '2030-07-01', '2031-06-30', 0)");
$sezonId = (int) Db::pdo()->lastInsertId();
$q = static fn(string $sciezka): string =>
    $sciezka . (str_contains($sciezka, '?') ? '&' : '?') . 'sezon=' . $GLOBALS['sezonId'];

$rywal = \CoachAnalyze\Clubs::create(1, [
    'name' => 'GKS Rywal', 'short_name' => 'GKS', 'color_primary' => '#2C6FE8',
    'color_secondary' => null, 'is_own_team' => false, 'aliases' => '',
]);

/*
 * KOLEJKA 2 WSTAWIONA JAKO PIERWSZA, z DATĄ PÓŹNIEJSZĄ. Lista kolejek ma ją
 * pokazać jako drugą — inaczej byłaby to lista „ostatnie mecze" odwrócona,
 * a nie kolejność rozgrywkowa.
 */
$mecze = [];
foreach ([['2', '2026-08-20'], ['1', '2026-08-06']] as [$kolejka, $data]) {
    Db::run("INSERT INTO matches (owner_id, club_id, club_home_id, club_away_id, season_id,
                                  played_at, round, status, is_home)
             VALUES (1, 1, 1, :away, :sid, :data, :round, 'done', 1)",
        ['away' => $rywal, 'sid' => $sezonId, 'data' => $data, 'round' => $kolejka]);
    $GLOBALS['sezonId'] = $sezonId;
    $mecze[$kolejka] = (int) Db::pdo()->lastInsertId();
}

/*
 * ZDARZENIA PO SUROWYCH NAZWACH TAGÓW — tak samo jak liczy je raport.
 * Kolejka 1: 2 strzały (1 gol, xG 0,60), kolejka 2: 3 strzały (1 gol, xG 0,90).
 */
$t = 0;
$dodaj = function (int $matchId, string $tag, string $side, ?float $xg = null, int $gol = 0)
    use (&$t): void {
    $t += 1000;
    Db::run('INSERT INTO events (match_id, import_id, tag_name, labels_json, team, team_side,
                                 t_ms, half, minute, xg, xg_source, is_goal, player)
             VALUES (:m, NULL, :tag, :lab, NULL, :side, :t, 1, 1, :xg, :src, :gol, :pl)',
        ['m' => $matchId, 'tag' => $tag, 'lab' => '[]', 'side' => $side, 't' => $t,
         'xg' => $xg, 'src' => $xg !== null ? 'analyst' : null, 'gol' => $gol,
         'pl' => $side === 'us' ? 'Kowalski Jan' : null]);
};
$dodaj($mecze['1'], 'STRZAŁ', 'us', 0.40, 1);
$dodaj($mecze['1'], 'STRZAŁ', 'us', 0.20, 0);
$dodaj($mecze['1'], 'ZDOBYCIE SBZ', 'us');
$dodaj($mecze['2'], 'STRZAŁ', 'us', 0.50, 1);
$dodaj($mecze['2'], 'STRZAŁ', 'us', 0.30, 0);
$dodaj($mecze['2'], 'STRZAŁ', 'us', 0.10, 0);
$dodaj($mecze['2'], 'ZDOBYCIE SBZ', 'us');
$dodaj($mecze['2'], 'ZDOBYCIE SBZ', 'us');

Db::run("INSERT INTO tag_catalog (club_id, kind, name, seen_matches, seen_events)
         VALUES (1, 'tag', 'STRZAŁ', 2, 5)");
Db::run("INSERT INTO tag_catalog (club_id, kind, name, seen_matches, seen_events)
         VALUES (1, 'tag', 'ZDOBYCIE SBZ', 2, 3)");

// Skład WYŁĄCZNIE dla kolejki 1 — drugi mecz ma pokazać kreskę, nie zero minut.
Db::run("INSERT INTO match_players (match_id, club_id, player, number, position, minutes, is_starter)
         VALUES (:m, 1, 'Kowalski Jan', 9, 'NAP', 90, 1)", ['m' => $mecze['1']]);
Db::run("INSERT INTO match_players (match_id, club_id, player, number, position, minutes, is_starter)
         VALUES (:m, 1, 'Cichy Marek', 17, 'POM', 12, 0)", ['m' => $mecze['1']]);

check('dane przygotowane',
    (int) Db::one('SELECT COUNT(*) AS c FROM events')['c'] === 8
    && (int) Db::one('SELECT COUNT(*) AS c FROM match_players')['c'] === 2);

// ============================================================ C. lista kolejek
echo "\n== C. lista kolejek ==\n";

$lista = http('GET', $q('/sezon'));
check('ekran kolejek odpowiada', $lista['status'] === 200, 'status ' . $lista['status']);
check('na liście są DOKŁADNIE mecze tego sezonu',
    substr_count($lista['body'], '/sezon/mecz/') === 2,
    'sezon filtruje, a nie tylko sortuje');

$poz1 = strpos($lista['body'], '/sezon/mecz/' . $mecze['1']);
$poz2 = strpos($lista['body'], '/sezon/mecz/' . $mecze['2']);
check('KOLEJNOŚĆ ROZGRYWKOWA: kolejka 1 przed kolejką 2',
    $poz1 !== false && $poz2 !== false && $poz1 < $poz2,
    'to nie jest lista „ostatnie mecze" odwrócona — sortujemy po kolejce');

check('kolejka ma prefiks, nie gołą liczbę',
    str_contains($lista['body'], 'k. 1') && str_contains($lista['body'], 'k. 2'),
    'goła liczba myli się z numerem porządkowym meczu (sesja 6)');
check('odsyłacz do SUMY jest na ekranie', str_contains($lista['body'], '/sezon/suma'));

// ============================================================ D. SUMA
echo "\n== D. SUMA sezonu ==\n";

$suma = http('GET', $q('/sezon/suma'));
check('ekran SUMY odpowiada', $suma['status'] === 200, 'status ' . $suma['status']);

/*
 * SUMA MA SIĘ ZGADZAĆ Z SUMĄ KOLEJEK. Pięć strzałów (2 + 3) i xG 1,50
 * (0,60 + 0,90) — liczba, która nie sumuje się z wierszy widocznych obok,
 * jest gorsza niż jej brak.
 */
check('suma strzałów z obu kolejek', str_contains($suma['body'], '5 : 0'),
    'stopka tabeli sumuje kolumnę, którą widać na ekranie');
check('suma xG z obu kolejek', str_contains($suma['body'], '1,50 : 0,00'));
check('rozbicie na kolejki jest pod sumą',
    substr_count($suma['body'], '/sezon/mecz/') >= 2);

$metrykaStrzaly = preg_match('/Strzały[^<]*<\/td>\s*<td class="num">5</s', $suma['body']) === 1;
check('metryka „Strzały" liczy 5 dla całego sezonu', $metrykaStrzaly,
    'Metrics::computeAll dla zakresu sezonu');

// ============================================================ E. karta meczu
echo "\n== E. karta meczu ==\n";

$karta = http('GET', '/sezon/mecz/' . $mecze['1']);
check('karta meczu odpowiada', $karta['status'] === 200, 'status ' . $karta['status']);
check('karta niesie meta meczu', str_contains($karta['body'], '2026-08-06'));
check('karta niesie skład z minutami',
    str_contains($karta['body'], 'Kowalski Jan') && str_contains($karta['body'], '90'));
check('karta niesie odsyłacze do działań',
    str_contains($karta['body'], '/mecze/' . $mecze['1'] . '/meta')
    && str_contains($karta['body'], '/mecze/' . $mecze['1'] . '/historia'));

$kartaBezSkladu = http('GET', '/sezon/mecz/' . $mecze['2']);
check('mecz bez składu mówi to wprost, zamiast pustej tabeli',
    str_contains($kartaBezSkladu['body'], 'Nie wpisano składu'));

// ============================================================ F. zawodnicy
echo "\n== F. zawodnicy ==\n";

$zawodnicy = http('GET', $q('/zawodnicy'));
check('ekran zawodników odpowiada', $zawodnicy['status'] === 200, 'status ' . $zawodnicy['status']);
check('zawodnik ze składu jest na liście', str_contains($zawodnicy['body'], 'Kowalski Jan'));
check('zawodnik BEZ zdarzeń też jest — rozegrał minuty',
    str_contains($zawodnicy['body'], 'Cichy Marek'),
    'eksport o nim nie wie, protokół owszem');

// ============================================================ G. kalendarz
echo "\n== G. kalendarz ==\n";

$kalendarz = http('GET', '/kalendarz?m=2026-08');
check('kalendarz odpowiada', $kalendarz['status'] === 200, 'status ' . $kalendarz['status']);
check('mecze sierpnia są w siatce',
    str_contains($kalendarz['body'], '/sezon/mecz/' . $mecze['1'])
    && str_contains($kalendarz['body'], '/sezon/mecz/' . $mecze['2']));
check('przejście między miesiącami bez skryptu',
    str_contains($kalendarz['body'], 'm=2026-07') && str_contains($kalendarz['body'], 'm=2026-09'));

$pustyMiesiac = http('GET', '/kalendarz?m=2026-12');
check('miesiąc bez meczów nie jest błędem', $pustyMiesiac['status'] === 200);

// ============================================================ H. cudzy klub
echo "\n== H. cudzy klub ==\n";

foreach (['/sezon', '/sezon/suma', '/zawodnicy', '/kalendarz'] as $sciezka) {
    $obcy = http('GET', $sciezka . '?klub=' . $rywal);
    check('403 na ' . $sciezka, $obcy['status'] === 403, 'status ' . $obcy['status']);
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
