<?php
declare(strict_types=1);

/**
 * Golden layout W4 — poprawki po odbiorze W0–W3 na produkcji.
 *
 *   1. regeneracja meczu klubu z templatem v9 zapisuje v9 (raport, config
 *      zadania); starsze rodzeństwo nietknięte; lista liczy „aktualny" z klubu
 *      MECZU i mówi, czyj to templat; rozjazd klubów widać przed kolejkowaniem,
 *   2. regeneracja masowa = jedno powiadomienie zbiorcze bez maili; pojedyncze
 *      „Przelicz" = chmurka + mail; dzwonek liczy tylko ważne; informacyjna
 *      chmurka raz na sesję,
 *   3. pulpit: data meczu, potem data IMPORTU — nigdy data renderu,
 *   4. Słownik: martwe ukryte, „Wliczane" po liczbie w sezonie; [op] „Usuń
 *      martwe" z podglądem i potwierdzeniem,
 *   5. Wgraj: jedna strefa `pliki[]`, rozdział CSV/JSON na serwerze,
 *   6. „Przelicz"/„Wygeneruj ponownie" jako przycisk drugorzędny,
 *   7. Drużyna: przypisani / bez przypisania, rywal poza listą,
 *   8. deploy.sh: blok `{ … exit; }` i podsumowanie w ostatnich 8 liniach.
 *
 * Uruchomienie:  PYTHONPATH=../../../engine php test_golden_w4_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Notifications;
use CoachAnalyze\Rebuilds;
use CoachAnalyze\ReportTemplates;
use CoachAnalyze\Stats;

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

$baza    = $here . '/golden_w4.sqlite';
$magazyn = $here . '/golden_w4_storage';
$logFile = $here . '/golden_w4.log';
$envFile = $here . '/.env.golden_w4';
$sock    = $here . '/golden_w4_redis.sock';
$port    = 9091;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);
mkdir($magazyn . '/reports', 0770, true);

$python = $root . '/venv/bin/python';
putenv('PYTHONPATH=' . $root . '/engine');

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'PYTHON_BIN=' . $python, 'ENGINE_TIMEOUT=60',
    'APP_URL=http://127.0.0.1:' . $port, 'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock, 'REDIS_PREFIX=golden_w4:',
    // Poczta „skonfigurowana" na zamknięty port: zadania `send_mail` POWSTAJĄ
    // (to sprawdzamy), a próba wysyłki pada od razu, bez czekania.
    'SMTP_HOST=127.0.0.1', 'SMTP_PORT=1', 'MAIL_FROM=panel@example.com',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);   // klub 1 „Klub A" (tenant), 2 „Klub B", 3 „Rywal C", 4 „Klub D" (tenant)
Db::run("UPDATE users SET role = 'admin' WHERE email = 'operator@example.com'");

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

/** Multipart z polem plikowym `pliki[]` (jedna strefa upuszczenia). @param list<array{0:string,1:string}> $pliki */
function multipartStrefa(array $pola, array $pliki): array
{
    $granica = '----ca' . bin2hex(random_bytes(8));
    $out = '';
    foreach ($pola as $n => $w) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$n}\"\r\n\r\n{$w}\r\n";
    }
    foreach ($pliki as [$plik, $tresc]) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"pliki[]\"; filename=\"{$plik}\"\r\n"
              . "Content-Type: application/octet-stream\r\n\r\n{$tresc}\r\n";
    }
    $out .= "--{$granica}--\r\n";
    return [$out, 'multipart/form-data; boundary=' . $granica];
}

function cron(): void
{
    global $root, $envFile;
    // Do skutku: run_job bierze najwyżej 5 zadań na przejście.
    for ($i = 0; $i < 12; $i++) {
        exec('CA_ENV_PATH=' . escapeshellarg($envFile)
            . ' PYTHONPATH=' . escapeshellarg($root . '/engine')
            . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1', $o);
        $zostalo = Db::one("SELECT COUNT(*) AS c FROM jobs WHERE type = :t AND status = 'queued'",
            ['t' => Rebuilds::JOB_TYPE]);
        if ((int) $zostalo['c'] === 0) {
            return;
        }
    }
}

function skrypt(string ...$argi): array
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' php '
        . escapeshellarg($root . '/app/repairs/regeneruj_raporty.php')
        . ' ' . implode(' ', array_map('escapeshellarg', $argi)) . ' 2>&1', $w, $kod);
    return [$kod, implode("\n", $w)];
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

$CSV = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
     . "STRZAŁ,10,20,KLUB A,CELNY,\"X 0,5\",80,30\n"
     . "STRATA,30,40,KLUB A,,,50,30\n"
     . "STRZAŁ,50,60,KLUB A,NIECELNY,\"X 0,2\",70,25\n";
$sciezkaCsv = $magazyn . '/uploads/w4.csv';
file_put_contents($sciezkaCsv, $CSV);

$zmienna = static fn(string $id, string $raw, ?string $canon, string $etykieta): array => [
    'id' => $id, 'source' => ['type' => 'tag', 'raw' => $raw], 'canon' => $canon,
    'display_label' => $etykieta, 'color' => '#E8590C', 'sections' => ['bilans'], 'visible' => true,
];
$konfig = static fn(string $etykieta) => [
    'schema_version' => 1,
    'team_us_rule' => ['markers' => ['NASZA', 'MASZA']],
    'sections_enabled' => ['bilans', 'mapy'],
    'variables' => [
        $zmienna('v_001', 'STRZAŁ', 'shot', $etykieta),
        $zmienna('v_002', 'STRATA', 'loss', 'STRATA'),
        // Martwa: nazwa nie wystąpi w żadnym imporcie klubu (zestaw z cudzego klubu).
        $zmienna('v_003', 'Posiadanie Stal', null, 'Posiadanie Stal'),
    ],
];

$sezon = (int) Db::one('SELECT id FROM seasons WHERE is_current = 1')['id'];
$now = Stats::now();

/** Mecz z importem i raportem (plik raportu istnieje — przeliczenie go podmienia). */
$mecz = static function (int $tenant, int $home, int $away, ?string $data, ?int $raportKlub = null)
        use ($sezon, $sciezkaCsv, $magazyn): array {
    Db::run("INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at, status)
             VALUES (1, :t, :s, :h, :a, :d, 'done')",
        ['t' => $tenant, 's' => $sezon, 'h' => $home, 'a' => $away, 'd' => $data]);
    $m = (int) Db::pdo()->lastInsertId();
    Db::run("INSERT INTO imports (match_id, csv_path, checksum_csv) VALUES (:m, :c, 'x')",
        ['m' => $m, 'c' => $sciezkaCsv]);
    return ['match' => $m, 'raport' => static function (int $wersja, string $silnik, string $kiedy)
            use ($m, $tenant, $raportKlub, $magazyn): int {
        $plik = $magazyn . '/reports/' . bin2hex(random_bytes(6)) . '.html';
        file_put_contents($plik, '<html>stary</html>');
        Db::run('INSERT INTO reports (match_id, club_id, html_path, engine_version, template_version, generated_at)
                 VALUES (:m, :k, :p, :v, :t, :g)',
            ['m' => $m, 'k' => $raportKlub ?? $tenant, 'p' => $plik, 'v' => $silnik, 't' => $wersja, 'g' => $kiedy]);
        return (int) Db::pdo()->lastInsertId();
    }];
};

// ===========================================================================
echo "== 1. regeneracja bierze AKTUALNY templat klubu meczu ==\n";

for ($v = 1; $v <= 9; $v++) {
    ReportTemplates::saveNewVersion(1, $konfig('Strzały v' . $v), 1);
}
ReportTemplates::saveNewVersion(4, $konfig('Strzały D'), 1);
check('Klub A ma templat v9, Klub D v1',
    ReportTemplates::currentVersion(1) === 9 && ReportTemplates::currentVersion(4) === 1);

$hetman = $mecz(1, 1, 2, '2026-09-20');
$hetmanStary = ($hetman['raport'])(1, '0.15.0', '2026-08-01 10:00:00');   // starsze rodzeństwo
$hetmanNowy  = ($hetman['raport'])(8, '0.16.6', '2026-09-27 18:00:00');   // najnowszy
$klubD = $mecz(4, 4, 3, '2026-09-21');
$klubDRaport = ($klubD['raport'])(1, '0.16.6', '2026-09-27 18:05:00');
// Mecz przepięty na Klub A, raport został przy Klubie D (PorzadkiMeczow::planPrzepnij).
$przepiety = $mecz(1, 1, 3, '2026-09-22', 4);
$przepietyRaport = ($przepiety['raport'])(1, '0.16.6', '2026-09-27 18:10:00');

[$kod, $podglad] = skrypt('--nieaktualne', '--dry-run');
check('suchobieg: przy meczu Klubu A widać templat v9, który weźmie proces roboczy',
    preg_match('/raport ' . $hetmanNowy . '\s+mecz\s+' . $hetman['match'] . '\b.*templat v9/u', $podglad) === 1, $podglad);
check('suchobieg: mecz Klubu D → templat v1 (jego własny)',
    preg_match('/raport ' . $klubDRaport . '\s.*Klub D\s+→ templat v1/u', $podglad) === 1, $podglad);
check('suchobieg: starsze rodzeństwo pominięte (domyślnie najnowszy raport meczu)',
    !str_contains($podglad, 'raport ' . str_pad((string) $hetmanStary, 5)), $podglad);
check('suchobieg: rozjazd raport/mecz nazwany przed kolejkowaniem',
    str_contains($podglad, '! raport klubu 4') && str_contains($podglad, 'należy do innego klubu niż mecz'), $podglad);

// Lista raportów (administrator): klub templatu podpisany, rozjazd oznaczony.
$login = http('GET', '/login');
http('POST', '/login', ['form' => [
    'email' => 'operator@example.com', 'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf' => csrfZ($login['body']),
]]);
$lista = http('GET', '/raporty');
check('lista admina miesza kluby → przy wersji „templat klubu X"',
    str_contains($lista['body'], 'templat klubu Klub D') && str_contains($lista['body'], 'templat klubu Klub A'));
check('lista: raport Klubu A v8 przy aktualnym v9 = nieaktualny (aktualny z klubu meczu)',
    str_contains($lista['body'], 'wygenerowano z templatem v8 (aktualny v9)'));
check('lista: przepięty mecz porównany z templatem klubu MECZU, nie raportu',
    str_contains($lista['body'], 'wygenerowano z templatem v1 (aktualny v9)')
    && str_contains($lista['body'], 'raport innego klubu niż mecz'));

[$kod, $wyjscie] = skrypt('--nieaktualne');
check('regeneracja zakolejkowała 3 raporty jedną partią',
    $kod === 0 && str_contains($wyjscie, 'Zakolejkowano: 3') && str_contains($wyjscie, 'Partia '), $wyjscie);
$partie = array_unique(array_map(
    static fn(array $j): string => (string) (json_decode((string) $j['payload_json'], true)['batch'] ?? ''),
    Db::all("SELECT payload_json FROM jobs WHERE type = 'rebuild_report'")
));
check('wszystkie zadania w jednej partii', count($partie) === 1 && $partie[0] !== '', json_encode($partie));

cron();
$niedokonczone = Db::all("SELECT id, status, error_text FROM jobs WHERE type = 'rebuild_report' AND status <> 'done'");
check('wszystkie przeliczenia zakończone', $niedokonczone === [], json_encode($niedokonczone, JSON_UNESCAPED_UNICODE));

$r = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $hetmanNowy]);
check('reports.template_version = 9 po regeneracji meczu klubu z templatem v9',
    (int) $r['template_version'] === 9, var_export($r['template_version'], true));
$zadanie = Db::one("SELECT id FROM jobs WHERE type = 'rebuild_report' AND payload_json LIKE :w",
    ['w' => '%"report_id":' . $hetmanNowy . ',%']);
$cfg = json_decode((string) @file_get_contents($magazyn . '/jobs/' . (int) $zadanie['id'] . '/config.json'), true);
check('config zadania (to, co dostał silnik) niesie template_version 9',
    is_array($cfg) && (int) ($cfg['template_version'] ?? 0) === 9, json_encode($cfg['template_version'] ?? null));
$tpl = json_decode((string) @file_get_contents($magazyn . '/jobs/' . (int) $zadanie['id'] . '/template.json'), true);
check('templat przekazany silnikowi to v9 (etykieta „Strzały v9")',
    ($tpl['variables'][0]['display_label'] ?? '') === 'Strzały v9');
check('plik raportu podmieniony (nie „stary")', !str_contains((string) file_get_contents((string) $r['html_path']), 'stary'));

$stary = Db::one('SELECT * FROM reports WHERE id = :id', ['id' => $hetmanStary]);
check('starsze rodzeństwo nietknięte (v1, data sprzed regeneracji)',
    (int) $stary['template_version'] === 1 && $stary['generated_at'] === '2026-08-01 10:00:00');
check('raport Klubu D przeliczony własnym templatem v1',
    (int) Db::one('SELECT template_version FROM reports WHERE id = :id', ['id' => $klubDRaport])['template_version'] === 1);
check('przepięty mecz: templat klubu MECZU (v9), reports.club_id bez zmian (4)',
    (int) Db::one('SELECT template_version FROM reports WHERE id = :id', ['id' => $przepietyRaport])['template_version'] === 9
    && (int) Db::one('SELECT club_id FROM reports WHERE id = :id', ['id' => $przepietyRaport])['club_id'] === 4);
check('rozjazd zapisany w dzienniku (template_club ≠ report_club)',
    Db::one("SELECT id FROM audit_log WHERE action = 'report.rebuilt' AND entity_id = :r AND meta_json LIKE '%\"report_club\":4%'",
        ['r' => $przepietyRaport]) !== null);

// ===========================================================================
echo "\n== 2. powiadomienia przy regeneracji ==\n";

$powiadomienia = Db::all('SELECT * FROM notifications WHERE user_id = 1 ORDER BY id');
$typy = array_count_values(array_column($powiadomienia, 'type'));
check('masowa regeneracja: JEDNO powiadomienie zbiorcze', ($typy[Notifications::TYP_BATCH] ?? 0) === 1, json_encode($typy));
check('masowa regeneracja: zero powiadomień per raport',
    ($typy[Notifications::TYP_REBUILT] ?? 0) === 0 && ($typy[Notifications::TYP_READY] ?? 0) === 0, json_encode($typy));
$zbiorcze = array_values(array_filter($powiadomienia, static fn($n) => $n['type'] === Notifications::TYP_BATCH))[0] ?? [];
check('treść: „Przeliczono raportów: 3" (administrator)', ($zbiorcze['title'] ?? '') === 'Przeliczono raportów: 3', (string) ($zbiorcze['title'] ?? ''));
check('partia dwóch klubów prowadzi na listę raportów', ($zbiorcze['url'] ?? '') === '/raporty');
check('masowa regeneracja: bez maili (mail_status none, zero zadań send_mail)',
    ($zbiorcze['mail_status'] ?? '') === 'none'
    && (int) Db::one("SELECT COUNT(*) AS c FROM jobs WHERE type = 'send_mail'")['c'] === 0);
check('dzwonek nie liczy podsumowania partii', Notifications::unreadCount(1) === 0);

// Pojedyncze „Przelicz" — jak dotąd: jedna chmurka i jeden mail.
Rebuilds::queue($klubDRaport, 1);
cron();
$pojedyncze = Db::all("SELECT * FROM notifications WHERE user_id = 1 AND type = :t", ['t' => Notifications::TYP_REBUILT]);
check('pojedyncze „Przelicz": jedna chmurka', count($pojedyncze) === 1);
check('pojedyncze „Przelicz": jeden mail w kolejce',
    ($pojedyncze[0]['mail_status'] ?? '') !== 'none'
    && (int) Db::one("SELECT COUNT(*) AS c FROM jobs WHERE type = 'send_mail'")['c'] === 1);
check('dzwonek nie liczy przeliczenia (nie jest nowym raportem)', Notifications::unreadCount(1) === 0);

// Ważne: raport gotowy z nowego importu i błąd.
$gotowy = Notifications::create(1, ['type' => Notifications::TYP_READY, 'title' => 'Raport gotowy: A — B',
    'entity' => 'report', 'entity_id' => $hetmanNowy, 'url' => '/raport/' . $hetmanNowy, 'mail' => false]);
$blad = Notifications::create(1, ['type' => Notifications::TYP_FAILED, 'title' => 'Błąd',
    'entity' => 'match', 'entity_id' => $hetman['match'], 'url' => '/mecze/' . $hetman['match'], 'mail' => false]);
check('dzwonek liczy ważne: raport gotowy i błąd', Notifications::unreadCount(1) === 2);

[$pierwsze, $pokazane] = Notifications::naChmurki(1, []);
[$drugie] = Notifications::naChmurki(1, $pokazane);
$idy = static fn(array $l): array => array_map(static fn($n) => (int) $n['id'], $l);
check('pierwsze wejście: chmurki informacyjne i ważne', in_array((int) $zbiorcze['id'], $idy($pierwsze), true)
    && in_array($gotowy, $idy($pierwsze), true));
check('drugie wejście: informacyjne nie wracają, ważne wracają do zamknięcia',
    !in_array((int) $zbiorcze['id'], $idy($drugie), true)
    && in_array($gotowy, $idy($drugie), true) && in_array($blad, $idy($drugie), true));
check('pokazanie chmurki NIE oznacza jej jako odczytanej (CLAUDE.md §9)',
    Db::one('SELECT read_at FROM notifications WHERE id = :id', ['id' => (int) $zbiorcze['id']])['read_at'] === null);

$strona1 = http('GET', '/pulpit');
$strona2 = http('GET', '/pulpit');
check('panel: informacyjna chmurka narysowana raz na sesję',
    str_contains($strona1['body'], 'data-id="' . (int) $zbiorcze['id'] . '"')
    && !str_contains($strona2['body'], 'data-id="' . (int) $zbiorcze['id'] . '"'));
check('panel: chmurka błędu zostaje na kolejnych stronach',
    str_contains($strona2['body'], 'data-id="' . $blad . '"'));

// ===========================================================================
echo "\n== 3. pulpit: data meczu, potem data importu — nie renderu ==\n";

Db::run('DELETE FROM matches WHERE club_id = 3');
$wstaw = static function (?string $data, string $importAt, string $raportAt) use ($sezon): int {
    Db::run("INSERT INTO matches (owner_id, club_id, season_id, club_home_id, club_away_id, played_at, status, created_at)
             VALUES (1, 3, :s, 3, 2, :d, 'done', '2026-01-01 00:00:00')", ['s' => $sezon, 'd' => $data]);
    $id = (int) Db::pdo()->lastInsertId();
    Db::run("INSERT INTO imports (match_id, csv_path, checksum_csv, created_at) VALUES (:m, '/x', 'x', :c)",
        ['m' => $id, 'c' => $importAt]);
    Db::run("INSERT INTO reports (match_id, club_id, html_path, generated_at) VALUES (:m, 3, '/x', :g)",
        ['m' => $id, 'g' => $raportAt]);
    return $id;
};
// A wgrany wcześniej, ale przeliczony (masowo) najpóźniej; B wgrany później.
$a = $wstaw(null, '2026-09-10 10:00:00', '2026-09-28 08:17:00');
$b = $wstaw(null, '2026-09-20 10:00:00', '2026-09-21 10:00:00');
$c = $wstaw('2026-09-25', '2026-09-26 10:00:00', '2026-09-26 11:00:00');
$kolejnosc = array_map(static fn($r) => (int) $r['id'], Stats::seasonMatches($sezon, 10, 3));
check('kolejność: data meczu, potem data IMPORTU (nie renderu)', $kolejnosc === [$c, $b, $a], json_encode($kolejnosc));
check('„Ostatni mecz" nie skacze po masowej regeneracji', (int) Stats::lastFinishedMatch(3)['id'] === $c);
$wiersz = array_values(array_filter(Stats::seasonMatches($sezon, 10, 3), static fn($r) => (int) $r['id'] === $a))[0];
check('podpis „raport z…" dalej z daty raportu', str_starts_with((string) $wiersz['report_at'], '2026-09-28 08:17'));

// ===========================================================================
echo "\n== 4. Słownik klubu: martwe ukryte, kolejność po liczbie w sezonie ==\n";

$slownik = http('GET', '/klub/ustawienia?zakladka=slownik');
check('Słownik odpowiada', $slownik['status'] === 200);
$wliczane = (string) strstr($slownik['body'], 'W sezonie');
check('martwa zmienna („Posiadanie Stal") ukryta w Słowniku', !str_contains($wliczane, 'Posiadanie Stal'));
$pozStrzal = strpos($wliczane, '<code>STRZAŁ</code>');
$pozStrata = strpos($wliczane, '<code>STRATA</code>');
check('„Wliczane" malejąco po liczbie w sezonie (STRZAŁ przed STRATA)',
    $pozStrzal !== false && $pozStrata !== false && $pozStrzal < $pozStrata);
check('liczba wystąpień w sezonie w wierszu (kolumna „W sezonie")',
    str_contains($slownik['body'], 'W sezonie') && preg_match('#<code>STRZAŁ</code>.*?<td class="num">(\d+)</td>#s', $slownik['body'], $mm) === 1
    && (int) $mm[1] >= 4, $mm[1] ?? '');

$zaaw = http('GET', '/klub/ustawienia?zakladka=zaawansowane');
check('[op] Zaawansowane: martwa zmienna widoczna z przyciskiem „Usuń martwe"',
    str_contains($zaaw['body'], 'Posiadanie Stal') && str_contains($zaaw['body'], 'podglad=martwe'));
$podgladM = http('GET', '/klub/ustawienia?zakladka=zaawansowane&podglad=martwe');
check('podgląd: lista zmian i przycisk potwierdzenia',
    str_contains($podgladM['body'], 'usunięto martwą v_003') && str_contains($podgladM['body'], 'usun-martwe'));
$przed = ReportTemplates::currentVersion(1);
$zle = http('POST', '/klub/ustawienia/zaawansowane/usun-martwe',
    ['form' => ['csrf' => csrfZ($podgladM['body']), 'wersja' => (string) ($przed - 1)]]);
check('potwierdzenie z nieaktualnej wersji odrzucone', ReportTemplates::currentVersion(1) === $przed);
$tak = http('POST', '/klub/ustawienia/zaawansowane/usun-martwe',
    ['form' => ['csrf' => csrfZ($podgladM['body']), 'wersja' => (string) $przed]]);
$poUsunieciu = ReportTemplates::decodeConfig(ReportTemplates::current(1)['config']);
check('„Usuń martwe" zapisało nową wersję bez martwej zmiennej',
    ReportTemplates::currentVersion(1) === $przed + 1
    && !in_array('Posiadanie Stal', array_map(static fn($z) => $z['source']['raw'], $poUsunieciu['variables']), true));
check('nowa wersja z notką naprawy — nie unieważnia raportów',
    ReportTemplates::autoNote($poUsunieciu) === \CoachAnalyze\NaprawaTemplatu::NOTKA
    && !ReportTemplates::isOutdated(1, 9));

// Analityk nie ma Zaawansowanych ani trasy usuwania.
Db::run("UPDATE users SET role = 'operator' WHERE id = 1");
Db::run('UPDATE clubs SET owner_id = 1');
$analityk = http('POST', '/klub/ustawienia/zaawansowane/usun-martwe', ['form' => ['csrf' => csrfZ($podgladM['body'])]]);
check('analityk: „Usuń martwe" = 404', $analityk['status'] === 404);
Db::run("UPDATE users SET role = 'admin' WHERE id = 1");

// ===========================================================================
echo "\n== 5. Wgraj: jedna strefa upuszczenia ==\n";

$form = http('GET', '/import');
check('jedno pole plików z multiple, bez dwóch „Choose File"',
    str_contains($form['body'], 'name="pliki[]"') && str_contains($form['body'], 'multiple')
    && !str_contains($form['body'], 'name="csv"') && !str_contains($form['body'], 'name="json"'));
check('komunikaty walidacji z pl.php przez data-*',
    str_contains($form['body'], 'data-blad-dwa-csv="Wybrano dwa pliki CSV')
    && str_contains($form['body'], 'data-blad-typu-csv="To nie jest plik CSV'));
$JSON = '{"project":{"name":"Mecz"},"tags":[]}';
$up = http('POST', '/import', ['multipart' => multipartStrefa(['csrf' => csrfZ($form['body'])],
    // W7: $CSV jest już meczem w tym klubie — inne zdarzenia, żeby nie był duplikatem.
    [['projekt.json', $JSON], ['mecz.csv', rtrim($CSV) . "\n" . substr((string) strrchr(rtrim($CSV), "\n"), 1) . "\n"]])]);
// Administrator po wgraniu trafia na stronę zadania (W3) — analityk na „Przygotuj".
check('CSV + JSON w jednej strefie przyjęte', $up['status'] === 302
    && preg_match('#^(/import/\d+/przygotuj|/zadania/\d+)$#', (string) $up['location']) === 1, (string) $up['location']);
$imp = Db::one('SELECT csv_path, json_path FROM imports ORDER BY id DESC LIMIT 1');
check('serwer rozdzielił pliki: CSV jako csv_path, JSON jako json_path',
    $imp !== null && str_ends_with((string) $imp['csv_path'], '.csv') && str_ends_with((string) $imp['json_path'], '.json'));
$ileImportow = (int) Db::one('SELECT COUNT(*) AS c FROM imports')['c'];
$dwa = http('POST', '/import', ['multipart' => multipartStrefa(['csrf' => csrfZ($form['body'])],
    [['a.csv', $CSV], ['b.csv', $CSV]])]);
check('dwa CSV odrzucone z polskim komunikatem', $dwa['status'] === 302
    && str_contains(http('GET', '/import')['body'], 'Wybrano dwa pliki CSV')
    && (int) Db::one('SELECT COUNT(*) AS c FROM imports')['c'] === $ileImportow);
$txt = http('POST', '/import', ['multipart' => multipartStrefa(['csrf' => csrfZ($form['body'])],
    [['mecz.txt', $CSV]])]);
check('zły typ (.txt) odrzucony', str_contains(http('GET', '/import')['body'], 'Dozwolone są wyłącznie pliki .csv oraz .json'));
$js = (string) file_get_contents($root . '/app/public/assets/powiadomienia.js');
// Zakaz innerHTML pilnuje test_chmurki.php (na kodzie bez komentarzy).
check('skrypt: pastylki przez textContent, nagłówek przez FileReader',
    str_contains($js, "li.className = 'pastylka'") && str_contains($js, 'li.textContent = pliki[i].name')
    && str_contains($js, 'FileReader'));

// ===========================================================================
echo "\n== 6. przyciski drugorzędne ==\n";

$lista = http('GET', '/raporty');
check('„Wygeneruj ponownie" jako przycisk drugorzędny',
    preg_match('#<button class="btn s drugi" type="submit">Wygeneruj ponownie</button>#', $lista['body']) === 1);
check('kolumna Akcje z klasą szerokości', str_contains($lista['body'], 'class="akcje kol-akcje"'));
$css = (string) file_get_contents($root . '/app/public/assets/app.css');
check('--akcent-drugi w jasnym (#E4EFE9) i obu ciemnych blokach',
    str_contains($css, '--akcent-drugi:   #E4EFE9;') && substr_count($css, '--akcent-drugi:') === 3);
check('.btn.s.drugi: tło --acc-soft, tekst akcentem', str_contains($css, '.btn.s.drugi { background: var(--acc-soft)'));
check('„Otwórz raport" nie łamie się', str_contains($css, '.akcje .btn.s { white-space: nowrap; }'));
foreach (['match_card.php', 'club_recalc.php', 'reports_list.php'] as $widok) {
    $zrodlo = (string) file_get_contents($root . '/app/src/Views/' . $widok);
    check($widok . ': „Przelicz" bez przycisku-odnośnika',
        !preg_match('#<button class="link" type="submit"[^>]*>\s*<\?= View::e\(View::t\(\'(recalc\.act|card\.wersje\.(recalc|regen)|reports\.act\.regen)#', $zrodlo));
}

// ===========================================================================
echo "\n== 7. Drużyna: przypisani / bez przypisania ==\n";

$zd = static function (int $m, string $gracz, string $strona): void {
    Db::run("INSERT INTO events (match_id, tag_name, team_side, player, t_ms, half, minute)
             VALUES (:m, 'STRATA', :s, :p, 1000, 1, 1)", ['m' => $m, 's' => $strona, 'p' => $gracz]);
};
$zd($hetman['match'], 'Nasz Jan', 'us');
$zd($hetman['match'], 'Nasz Jan', 'none');
$zd($hetman['match'], 'Nieznany Adam', 'none');
$zd($hetman['match'], 'Rywal Piotr', 'them');
Db::run('DELETE FROM match_players');
$druzyna = http('GET', '/druzyna');
check('Drużyna odpowiada', $druzyna['status'] === 200);
$nasi = (string) strstr((string) strstr($druzyna['body'], 'przypisani do naszej drużyny'), 'bez przypisania', true);
$bez  = (string) strstr($druzyna['body'], 'bez przypisania do drużyny');
check('zawodnik z team = nasza drużyna w „przypisani"', str_contains($nasi, 'Nasz Jan'));
check('zawodnik z pustym team w „bez przypisania"', str_contains($bez, 'Nieznany Adam') && !str_contains($nasi, 'Nieznany Adam'));
check('zawodnik rywala nie trafia do listy', !str_contains($druzyna['body'], 'Rywal Piotr'));
check('liczba zdarzeń przy zawodniku', str_contains($nasi, 'zdarzeń: 1') && str_contains($nasi, 'bez drużyny: 1'));

// ===========================================================================
echo "\n== 8. deploy.sh: podsumowanie w ostatnich 8 liniach ==\n";

$deploy = (string) file_get_contents($root . '/deploy/deploy.sh');
check('skrypt w bloku { … exit; } — bash wczytuje całość przed git pull',
    preg_match('/set -euo pipefail\n(\s*\n|#[^\n]*\n)*\{\n/', $deploy) === 1 && preg_match('/\nexit 0\n\}\s*$/', $deploy) === 1);
$poGotowe = (string) strstr($deploy, 'echo "==> Gotowe:');
$echa = preg_match_all('/^\s*echo /m', $poGotowe);
check('po „==> Gotowe" najwyżej 6 linii wyjścia (mieszczą się w tail -8)', $echa > 0 && $echa <= 6, (string) $echa);
check('werdykt o regeneracji zawsze: potrzebna / niepotrzebna / nie wiem',
    str_contains($deploy, 'POTRZEBNA') && str_contains($deploy, 'niepotrzebna') && str_contains($deploy, 'nie wiem')
    && str_contains($poGotowe, 'regeneracja: $REGENERACJA'));
check('„Gotowe" po werdykcie, nie przed nim (podsumowanie na samym końcu)',
    strpos($deploy, 'REGENERACJA="nie wiem') < strpos($deploy, 'echo "==> Gotowe:'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
