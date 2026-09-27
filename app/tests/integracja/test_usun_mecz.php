<?php
declare(strict_types=1);

/**
 * `app/repairs/usun_mecz.php` — porządki w meczach i klubach.
 *
 * Odwzorowuje zlecenie produkcyjne z 2026-09-27: usunięcie meczów testowych
 * razem z rywalem testowym, przepięcie meczów na inny klub, a obok — mecz,
 * którego raporty MUSZĄ zostać (na produkcji: raporty 27, 28 i mecz 3).
 *
 * SQLite w testach NIE MA kluczy obcych produkcji. Zamiast liczyć na błąd bazy
 * sprawdzamy wprost, że po usunięciu nic nie wskazuje na usunięte wiersze —
 * dokładnie te kolumny, które na MySQL-u wywróciłyby transakcję
 * (`tag_catalog.*_seen_import_id`, `events.import_id`, `match_players.match_id`).
 *
 * Uruchomienie:  php test_usun_mecz.php
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

$baza    = $here . '/usun_mecz.sqlite';
$envFile = $here . '/.env.usun_mecz';
$magazyn = $here . '/usun_mecz_storage';
$obcy    = $here . '/usun_mecz_poza_magazynem.html';

@unlink($baza);
@unlink($obcy);
exec('rm -rf ' . escapeshellarg($magazyn));
foreach (['reports', 'uploads/2026/09', 'jobs'] as $d) {
    mkdir($magazyn . '/' . $d, 0770, true);
}

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza, 'DB_NAME=serwer_test',
    'STORAGE_PATH=' . $magazyn, 'APP_URL=http://127.0.0.1',
    'ARGON_MEMORY_COST=8192', 'ARGON_TIME_COST=1', '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);

register_shutdown_function(static function () use ($baza, $envFile, $magazyn, $obcy): void {
    @unlink($baza);
    @unlink($envFile);
    @unlink($obcy);
    exec('rm -rf ' . escapeshellarg($magazyn));
});

function wstaw(string $sql, array $p = []): int
{
    Db::run($sql, $p);
    return (int) Db::pdo()->lastInsertId();
}

function ile(string $sql, array $p = []): int
{
    return (int) Db::one($sql, $p)['c'];
}

function plik(string $sciezka): string
{
    file_put_contents($sciezka, 'x');
    return $sciezka;
}

function skrypt(string ...$argi): array
{
    global $root, $envFile;
    exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' php '
        . escapeshellarg($root . '/app/repairs/usun_mecz.php')
        . ' ' . implode(' ', array_map('escapeshellarg', $argi)) . ' 2>&1', $w, $kod);
    return [$kod, implode("\n", $w)];
}

/** Mecz z kompletem zależności. @return array<string,mixed> */
function mecz(int $klub, ?int $home, ?int $away, int $raportow): array
{
    global $magazyn;
    $m = wstaw("INSERT INTO matches (owner_id, club_id, club_home_id, club_away_id, status) VALUES (1, :c, :h, :a, 'done')",
        ['c' => $klub, 'h' => $home, 'a' => $away]);
    $csv = plik($magazyn . '/uploads/2026/09/' . bin2hex(random_bytes(4)) . '.csv');
    $i = wstaw('INSERT INTO imports (match_id, csv_path, checksum_csv) VALUES (:m, :c, :s)',
        ['m' => $m, 'c' => $csv, 's' => 'x']);
    $raporty = [];
    $htmle = [];
    for ($n = 0; $n < $raportow; $n++) {
        $html = $htmle[] = plik($magazyn . '/reports/' . bin2hex(random_bytes(4)) . '.html');
        $raporty[] = wstaw('INSERT INTO reports (match_id, club_id, html_path, generated_at) VALUES (:m, :c, :h, :g)',
            ['m' => $m, 'c' => $klub, 'h' => $html, 'g' => '2026-09-2' . $n . ' 10:00:00']);
    }
    $j = wstaw("INSERT INTO jobs (type, payload_json, status) VALUES ('build_report', :p, 'done')",
        ['p' => json_encode(['import_id' => $i, 'match_id' => $m])]);
    mkdir($magazyn . '/jobs/' . $j);
    plik($magazyn . '/jobs/' . $j . '/meta.json');
    Db::run("INSERT INTO events (match_id, import_id, tag_name, team_side, t_ms, half, minute) VALUES (:m, :i, 'STRZAŁ', 'us', 1000, 1, 1)",
        ['m' => $m, 'i' => $i]);
    Db::run("INSERT INTO match_players (match_id, club_id, player) VALUES (:m, :c, 'Kowalski')", ['m' => $m, 'c' => $klub]);
    return ['mecz' => $m, 'import' => $i, 'raporty' => $raporty, 'html' => $htmle, 'zadanie' => $j, 'csv' => $csv];
}

// --------------------------------------------------------------- przygotowanie
$tenant = 1;
$nowyTenant = 4;
$rywalTestowy = wstaw("INSERT INTO clubs (owner_id, club_key, name, is_own_team) VALUES (1, 'TSTUX123', 'TEST UX RYWAL', 0)");

$usuwany = mecz($tenant, $tenant, $rywalTestowy, 2);
$usuwany2 = mecz($tenant, $tenant, 2, 1);
$zostaje = mecz($tenant, $tenant, 2, 2);          // jak raporty 27/28 — nietykalne
$przepinany = mecz($tenant, $tenant, 2, 1);
$przycinany = mecz($tenant, $tenant, 2, 3);

$raportUsuwany = $usuwany['raporty'][0];
Db::run("INSERT INTO share_links (report_id, club_id, token) VALUES (:r, :c, 'tok')", ['r' => $raportUsuwany, 'c' => $tenant]);
Db::run("INSERT INTO notifications (user_id, type, title, entity, entity_id) VALUES (1, 'report_ready', 't', 'report', :r)", ['r' => $raportUsuwany]);
Db::run("INSERT INTO notifications (user_id, type, title, entity, entity_id) VALUES (1, 'job_failed', 't', 'job', :j)", ['j' => $usuwany['zadanie']]);
Db::run("INSERT INTO notes (owner_id, scope, match_id, club_id) VALUES (1, 'match', :m, :c)", ['m' => $usuwany['mecz'], 'c' => $tenant]);
Db::run('INSERT INTO xg_manual_shots (user_id, match_id, x, y, xg) VALUES (1, :m, 90, 34, 0.2)', ['m' => $usuwany['mecz']]);
Db::run("INSERT INTO tag_catalog (club_id, kind, name, first_seen_import_id, last_seen_import_id) VALUES (:c, 'tag', 'STRZAŁ', :i, :j)",
    ['c' => $tenant, 'i' => $usuwany['import'], 'j' => $zostaje['import']]);
// Raport, którego plik leży POZA magazynem — skrypt nie ma prawa go ruszyć.
Db::run('UPDATE reports SET html_path = :h WHERE id = :r', ['h' => plik($obcy), 'r' => $usuwany['raporty'][1]]);

$stan = static fn(): string => json_encode([
    ile('SELECT COUNT(*) AS c FROM matches'), ile('SELECT COUNT(*) AS c FROM reports'),
    ile('SELECT COUNT(*) AS c FROM imports'), ile('SELECT COUNT(*) AS c FROM clubs'),
    Db::one('SELECT club_id FROM matches WHERE id = :m', ['m' => $przepinany['mecz']])['club_id'],
]);

// ===========================================================================
echo "== podgląd niczego nie zmienia ==\n";

$przed = $stan();
$argi = ['--match', (string) $usuwany['mecz'], '--match=' . $usuwany2['mecz'],
         '--przepnij-klub', (string) $przepinany['mecz'], '--na', (string) $nowyTenant,
         '--usun-klub', (string) $rywalTestowy];
[$kod, $out] = skrypt(...$argi);
check('podgląd kończy się zerem', $kod === 0, $out);
check('podgląd: baza bez zmian', $stan() === $przed);
check('podgląd: pliki na miejscu', is_file($usuwany['csv']) && is_file($obcy));
check('podgląd wypisuje komendę mysqldump z nazwą bazy', str_contains($out, "mysqldump --single-transaction") && str_contains($out, "'serwer_test'"), $out);
check('podgląd wymienia pliki raportów i uploadów', str_contains($out, $usuwany['csv']), $out);
check('podgląd ostrzega o aktywnym linku publicznym', str_contains($out, 'AKTYWNY link publiczny'), $out);
check('podgląd mówi, że raport przepinanego meczu zostaje przy starym klubie',
    str_contains($out, "zostaje przy klubie {$tenant}"), $out);
check('klub testowy da się usunąć, bo jego jedyny mecz idzie do kosza w tym przebiegu',
    !str_contains($out, 'NIE MOŻNA'), $out);

// ===========================================================================
echo "\n== zapis bez potwierdzenia zrzutu ==\n";

[$kod, $out] = skrypt(...array_merge($argi, ['--zapisz']));
check('bez --mam-zrzut: kod 1, baza bez zmian', $kod === 1 && $stan() === $przed, $out);

// ===========================================================================
echo "\n== zapis ==\n";

[$kod, $out] = skrypt(...array_merge($argi, ['--zapisz', '--mam-zrzut']));
check('zapis kończy się zerem', $kod === 0, $out);

foreach ([$usuwany, $usuwany2] as $u) {
    $m = $u['mecz'];
    check("mecz {$m}: nie ma meczu, raportów, importów, zdarzeń, składu",
        ile('SELECT COUNT(*) AS c FROM matches WHERE id = :m', ['m' => $m]) === 0
        && ile('SELECT COUNT(*) AS c FROM reports WHERE match_id = :m', ['m' => $m]) === 0
        && ile('SELECT COUNT(*) AS c FROM imports WHERE match_id = :m', ['m' => $m]) === 0
        && ile('SELECT COUNT(*) AS c FROM events WHERE match_id = :m', ['m' => $m]) === 0
        && ile('SELECT COUNT(*) AS c FROM match_players WHERE match_id = :m', ['m' => $m]) === 0);
    check("mecz {$m}: zadanie kolejki i jego katalog usunięte",
        ile('SELECT COUNT(*) AS c FROM jobs WHERE id = :j', ['j' => $u['zadanie']]) === 0
        && !is_dir($magazyn . '/jobs/' . $u['zadanie']));
    check("mecz {$m}: plik uploadu usunięty", !is_file($u['csv']));
}
check('pliki usuniętych raportów w magazynie usunięte',
    !is_file($usuwany['html'][0]) && !is_file($usuwany2['html'][0]));
check('pliki raportów pozostałych meczów na miejscu',
    count(array_filter(array_merge($zostaje['html'], $przepinany['html'], $przycinany['html']), 'is_file')) === 6);
check('plik POZA magazynem nietknięty i zgłoszony', is_file($obcy) && str_contains($out, 'poza STORAGE_PATH'), $out);
check('link publiczny i powiadomienia usuniętego raportu/zadania usunięte',
    ile('SELECT COUNT(*) AS c FROM share_links WHERE report_id = :r', ['r' => $raportUsuwany]) === 0
    && ile("SELECT COUNT(*) AS c FROM notifications WHERE entity IN ('report', 'job')") === 0);
check('notatka meczu usunięta, strzał z kalkulatora xG zostaje odpięty',
    ile('SELECT COUNT(*) AS c FROM notes WHERE match_id = :m', ['m' => $usuwany['mecz']]) === 0
    && ile('SELECT COUNT(*) AS c FROM xg_manual_shots WHERE match_id IS NULL') === 1);
$kat = Db::one("SELECT first_seen_import_id, last_seen_import_id FROM tag_catalog WHERE name = 'STRZAŁ'");
check('katalog tagów zostaje; wskazanie na usunięty import wyzerowane, na żywy nietknięte',
    $kat !== null && $kat['first_seen_import_id'] === null && (int) $kat['last_seen_import_id'] === $zostaje['import']);

check('mecz, który zostaje: raporty, import i pliki nietknięte',
    ile('SELECT COUNT(*) AS c FROM reports WHERE match_id = :m', ['m' => $zostaje['mecz']]) === 2
    && ile('SELECT COUNT(*) AS c FROM imports WHERE match_id = :m', ['m' => $zostaje['mecz']]) === 1
    && is_file($zostaje['csv']));

$p = Db::one('SELECT club_id, club_home_id, club_away_id FROM matches WHERE id = :m', ['m' => $przepinany['mecz']]);
check('przepięcie: club_id i club_home_id → nowy klub, club_away_id (rywal) bez zmian',
    (int) $p['club_id'] === $nowyTenant && (int) $p['club_home_id'] === $nowyTenant && (int) $p['club_away_id'] === 2,
    json_encode($p));
check('przepięcie: raport zostaje przy starym klubie',
    (int) Db::one('SELECT club_id FROM reports WHERE match_id = :m', ['m' => $przepinany['mecz']])['club_id'] === $tenant);
check('klub testowy usunięty', ile('SELECT COUNT(*) AS c FROM clubs WHERE id = :k', ['k' => $rywalTestowy]) === 0);

// ===========================================================================
echo "\n== raporty tylko najnowszy ==\n";

$najnowszy = end($przycinany['raporty']);
[$kod, $out] = skrypt('--raporty-tylko-najnowszy', (string) $przycinany['mecz'], '--zapisz', '--mam-zrzut');
check('przycięte raporty: pliki usunięte, plik najnowszego zostaje',
    !is_file($przycinany['html'][0]) && !is_file($przycinany['html'][1]) && is_file($przycinany['html'][2]));
check('zostaje wyłącznie najnowszy raport', $kod === 0
    && array_column(Db::all('SELECT id FROM reports WHERE match_id = :m', ['m' => $przycinany['mecz']]), 'id') == [$najnowszy], $out);
check('przycięcie nie usuwa meczu ani importu',
    ile('SELECT COUNT(*) AS c FROM matches WHERE id = :m', ['m' => $przycinany['mecz']]) === 1
    && ile('SELECT COUNT(*) AS c FROM imports WHERE match_id = :m', ['m' => $przycinany['mecz']]) === 1);

// ===========================================================================
echo "\n== zabezpieczenia ==\n";

[$kod, $out] = skrypt('--usun-klub', '2', '--zapisz', '--mam-zrzut');
check('klub z meczami: odmowa, kod 1', $kod === 1 && str_contains($out, 'ma mecze')
    && ile('SELECT COUNT(*) AS c FROM clubs WHERE id = 2') === 1, $out);
[$kod, $out] = skrypt('--match', (string) $usuwany['mecz'], '--zapisz', '--mam-zrzut');
check('powtórzenie: mecz nie istnieje, kod 1, bez zmian', $kod === 1 && str_contains($out, 'nie istnieje'), $out);
[$kod, $out] = skrypt('--przepnij-klub', (string) $przepinany['mecz']);
check('--przepnij-klub bez --na: błąd użycia', $kod === 2, $out);
[$kod, $out] = skrypt('--match', (string) $zostaje['mecz'], '--przepnij-klub', (string) $zostaje['mecz'], '--na', '4');
check('ten sam mecz usuwany i przepinany: błąd użycia', $kod === 2, $out);
[$kod, $out] = skrypt('--przepnij-klub', (string) $przepinany['mecz'], '--na', (string) $nowyTenant, '--zapisz', '--mam-zrzut');
check('powtórne przepięcie: nic do zrobienia', $kod === 0 && str_contains($out, 'Nic do zrobienia'), $out);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
