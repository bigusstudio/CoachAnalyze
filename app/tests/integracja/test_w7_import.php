<?php
declare(strict_types=1);

/**
 * W7 — metoda importu v3 po stronie panelu.
 *
 *   A/G  skrót zdarzeń: bliźniak PHP == silnik (plik z pułapkami CSV),
 *        deduplikacja meczów w klubie,
 *   D    profil analityka: Jaccard UUID, zapasowo nazw; UUID do Słownika,
 *   C    Słownik: znaczenie i strona, przenoszone przez zapis templatu,
 *   F/G/H przelot importu przez cron: skrót i profil w `imports`, wynik ręczny
 *        i znane drużyny w config.json, anomalie i niezmienniki w pokryciu,
 *        alert admina przy naruszeniu.
 *
 * Dane wyłącznie syntetyczne (CLAUDE.md §7). Wymaga venv Pythona (sekcja 3
 * `uruchom.sh`).
 */

use CoachAnalyze\Alerts;
use CoachAnalyze\Config;
use CoachAnalyze\Configurator;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\KontrolaImportu;
use CoachAnalyze\NazwaZmiennej;
use CoachAnalyze\ProfilAnalityka;
use CoachAnalyze\Upload;
use CoachAnalyze\UstawieniaKlubu;

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

$baza    = $here . '/w7.sqlite';
$magazyn = $here . '/w7_storage';
$envFile = $here . '/.env.w7';
@unlink($baza);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);
mkdir($magazyn . '/reports', 0770, true);
$python = $root . '/venv/bin/python';
putenv('PYTHONPATH=' . $root . '/engine');

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza, 'STORAGE_PATH=' . $magazyn,
    'LOG_PATH=' . $here . '/w7.log', 'PYTHON_BIN=' . $python, 'ENGINE_TIMEOUT=60',
    'HTML_TEMPLATE=v21', 'APP_URL=http://localhost', 'SESSION_NAME=ca_test', 'REDIS_SOCKET=', '',
]));
putenv('CA_ENV_PATH=' . $envFile);
require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';
ca_test_db($baza);

register_shutdown_function(static function () use ($baza, $magazyn, $envFile, $here): void {
    @unlink($baza);
    @unlink($envFile);
    @unlink($here . '/w7.log');
    exec('rm -rf ' . escapeshellarg($magazyn));
});

function plik(string $tresc, string $rozszerzenie = 'csv'): string
{
    global $magazyn;
    $p = $magazyn . '/uploads/' . bin2hex(random_bytes(6)) . '.' . $rozszerzenie;
    file_put_contents($p, $tresc);
    return $p;
}

function skrotSilnika(string $sciezka): string
{
    global $python, $root;
    $kod = 'import sys; from coachanalyze.sources.livetag import parse; '
         . 'print(parse.sha256_zdarzen(parse.read_rows(sys.argv[1])[0]))';
    return trim((string) shell_exec('PYTHONPATH=' . escapeshellarg($root . '/engine') . ' '
        . escapeshellarg($python) . ' -c ' . escapeshellarg($kod) . ' ' . escapeshellarg($sciezka)));
}

function cron(?int $jobId = null): string
{
    global $root, $envFile;
    exec('PYTHONPATH=' . escapeshellarg($root . '/engine') . ' CA_ENV_PATH=' . escapeshellarg($envFile)
        . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ($jobId ? ' ' . $jobId : '') . ' 2>&1', $wyj);
    return implode("\n", $wyj);
}

// =========================================================== A/G skrót zdarzeń
echo "== A/G: skrót zdarzeń — PHP i silnik to ta sama liczba ==\n";

$naglowek = "tag_name,begin,end,players,labels,team,comment,pos_x_meters,pos_y_meters\r\n";
$pulapki = [
    'BOM, CRLF, cudzysłowy i przecinki w etykietach' =>
        "\xEF\xBB\xBF" . $naglowek
        . "Strzał,10.5,20,\"Nowak, Jan\",\"Pozycyjny, CELNY\",ALFA,\"xG 0,31\",90.1,30\r\n"
        . "\"Tag \"\"cytat\"\"\",30,40,,,BETA,\"a\\b/c\",,\r\n",
    'znaki sterujące, tabulator, U+2028, nowa linia w polu' =>
        $naglowek
        . "P2 Podanie,1,2,,\"linia1\nlinia2\",ALFA,\"x\ty\xE2\x80\xA8z\",,\n"
        . "Ż-ółć,3,4,,,,\"\x01\",,\n",
    'pusta linia, brak pól, nadmiar pól' =>
        $naglowek . "\n" . "Krótki,5,6\n" . "Długi,7,8,,,,,,,nadmiar1,nadmiar2\n",
];
foreach ($pulapki as $opis => $tresc) {
    $p = plik($tresc);
    $php = Upload::sha256Zdarzen($p);
    check("zgodne z silnikiem: {$opis}", $php !== null && $php === skrotSilnika($p), (string) $php);
}
$a = plik($naglowek . "A,1,2,,,,,,\nB,3,4,,,,,,\n");
$b = plik($naglowek . "B,3,4,,,,,,\nA,1,2,,,,,,\n");
$c = plik($naglowek . "A,1,2,,,,,,\n");
check('kolejność wierszy nie zmienia skrótu', Upload::sha256Zdarzen($a) === Upload::sha256Zdarzen($b));
check('inny zbiór zdarzeń — inny skrót', Upload::sha256Zdarzen($a) !== Upload::sha256Zdarzen($c));

$skrotA = (string) Upload::sha256Zdarzen($a);
$impA = Imports::create(1, $a, null, hash_file('sha256', $a), 1, $skrotA);
$meczA = (int) Imports::find($impA)['match_id'];
check('duplikat w tym samym klubie wykryty (inna kolejność wierszy)',
    Imports::duplikat((string) Upload::sha256Zdarzen($b), hash_file('sha256', $b), 1) === $meczA);
check('ten sam plik w INNYM klubie to nie duplikat',
    Imports::duplikat($skrotA, hash_file('sha256', $a), 4) === null);
check('ponowne wgranie do TEGO meczu to nie duplikat',
    Imports::duplikat($skrotA, hash_file('sha256', $a), 1, $meczA) === null);
Db::run('UPDATE imports SET sha256_zdarzen = NULL WHERE id = :id', ['id' => $impA]);
check('import sprzed W7 (bez skrótu) dopasowany po skrócie pliku',
    Imports::duplikat('inny', hash_file('sha256', $a), 1) === $meczA);

// ============================================================ D profil analityka
echo "\n== D: profil analityka ==\n";

$p5 = ['a', 'b', 'c', 'd', 'e'];
check('pokrycie mniejszego zbioru: 5 z 5 zawartych w 8 = 1,0',
    ProfilAnalityka::pokrycie($p5, array_merge($p5, ['f', 'g', 'h'])) === 1.0);
check('pokrycie 4 z 5 = 0,8 (próg włącznie)', ProfilAnalityka::pokrycie($p5, ['a', 'b', 'c', 'd', 'x', 'y']) === 0.8
    && ProfilAnalityka::dopasowane(0.8, 0.0));
check('mniejszy zbiór < 5 elementów — pokrycie 0 (za mało, żeby orzec)',
    ProfilAnalityka::pokrycie(['a', 'b', 'c', 'd'], $p5) === 0.0);
check('dwa puste = 0 (nic nie wiadomo)', ProfilAnalityka::pokrycie([], []) === 0.0);
check('normalizacja nazw jak w silniku (kropki, wielkość liter, spacje)',
    NazwaZmiennej::klucz(' 1x1  DEF. ') === NazwaZmiennej::klucz('1x1 def')
    && NazwaZmiennej::klucz('SBZ PODAJĄCY/OTRZYMUJĄCY') !== NazwaZmiennej::klucz('SBZ PODAJĄCY'));

$uuid = static fn(int $i): string => sprintf('00000000-0000-0000-0000-%012d', $i);
$nazwy10 = array_map(static fn(int $i): string => 'tag ' . $i, range(1, 10));
$profilStary = ['uuid' => array_map($uuid, range(1, 10)), 'nazwy' => $nazwy10,
                'nazwa_uuid' => array_combine(array_map(static fn($n) => strtoupper($n), $nazwy10),
                                              array_map($uuid, range(1, 10)))];
Db::run('UPDATE imports SET profil_json = :p WHERE id = :id', ['p' => json_encode($profilStary), 'id' => $impA]);

$impB = Imports::create(1, $c, null, 'x', 1, 'y');
// 7 z 10 UUID wspólnych (pokrycie 0,7 < 0,8), ale NAZWY te same — dopasowanie zapasowe.
Db::run('UPDATE imports SET profil_json = :p WHERE id = :id', ['p' => json_encode([
    'uuid' => array_merge(array_map($uuid, range(1, 7)), array_map($uuid, range(51, 53))),
    'nazwy' => $nazwy10, 'nazwa_uuid' => []]), 'id' => $impB]);
$ocena = ProfilAnalityka::ocen($impB);
check('profil znany zapasowo po nazwach, choć UUID < 0,8',
    $ocena !== null && $ocena['nowy'] === false && $ocena['import_id'] === $impA
    && $ocena['pokrycie_uuid'] < 0.8 && $ocena['pokrycie_nazw'] === 1.0, json_encode($ocena));

// Stara Pogoń: 11 tagów, wszystkie UUID w nowym profilu z 16 — dokładanie tagów
// nie robi z analityka „nowego profilu" (Jaccard dawał tu 0,69).
$impPogon = Imports::create(1, $c, null, 'xp', 1, 'yp');
Db::run('UPDATE imports SET profil_json = :p WHERE id = :id', ['p' => json_encode([
    'uuid' => array_merge(array_map($uuid, range(1, 10)), array_map($uuid, range(61, 66))),
    'nazwy' => ['inne'], 'nazwa_uuid' => []]), 'id' => $impPogon]);
$impStara = Imports::create(1, $c, null, 'xs', 1, 'ys');
Db::run('UPDATE imports SET profil_json = :p WHERE id = :id', ['p' => json_encode([
    'uuid' => array_merge(array_map($uuid, range(1, 10)), [$uuid(61)]),
    'nazwy' => ['stare'], 'nazwa_uuid' => []]), 'id' => $impStara]);
$ocenaStara = ProfilAnalityka::ocen($impStara);
check('stara Pogoń (11 ⊂ 16 UUID) = znany profil',
    $ocenaStara !== null && $ocenaStara['nowy'] === false && $ocenaStara['pokrycie_uuid'] === 1.0,
    json_encode($ocenaStara));

$impC = Imports::create(1, $c, null, 'x2', 1, 'y2');
Db::run('UPDATE imports SET profil_json = :p WHERE id = :id', ['p' => json_encode([
    'uuid' => [$uuid(99)], 'nazwy' => ['coś innego'], 'nazwa_uuid' => []]), 'id' => $impC]);
$ocenaC = ProfilAnalityka::ocen($impC);
check('profil nowy — bez importu wzorcowego', $ocenaC !== null && $ocenaC['nowy'] === true && $ocenaC['import_id'] === null);
ProfilAnalityka::zapisz($impC, $ocenaC);
check('ocena zapisana w imports (migracja 019)',
    (int) Imports::find($impC)['profil_nowy'] === 1 && Imports::find($impC)['profil_import_id'] === null);
check('import bez odcisku — bez oceny', ProfilAnalityka::ocen(Imports::create(1, $c, null, 'x3', 1, 'y3')) === null);
check('UUID dla nazw Słownika po normalizacji', ProfilAnalityka::uuidDlaNazw(1, ['Tag 3.']) === [$uuid(3)]);

// ======================================================= C Słownik: znaczenie
echo "\n== C: Słownik — znaczenie i strona ==\n";

$zmienne = [
    ['id' => 'v_001', 'source' => ['type' => 'tag', 'raw' => 'Posiadanie Gamma'], 'canon' => null],
    ['id' => 'v_002', 'source' => ['type' => 'tag', 'raw' => 'tag 3'], 'canon' => null],
    ['id' => 'v_003', 'source' => ['type' => 'label', 'raw' => 'CELNY'], 'canon' => 'on_target'],
    ['id' => 'v_004', 'source' => ['type' => 'tag', 'raw' => 'Martwa'], 'canon' => 'shot'],
];
$po = UstawieniaKlubu::zastosujZnaczenia($zmienne,
    [0 => 'possession', 1 => 'pass', 2 => 'pass'],
    [0 => 'them', 1 => 'us', 2 => 'us'],
    static fn(array $n): array => ProfilAnalityka::uuidDlaNazw(1, $n));
check('posiadanie: pojęcie prezentacji i STRONA, nie nazwa klubu',
    $po[0]['canon'] === 'possession' && $po[0]['side'] === 'them');
check('UUID tagu dołożony do przypisania', ($po[1]['uuids'] ?? null) === [$uuid(3)] && $po[1]['canon'] === 'pass');
check('etykieta nie dostaje pojęcia tagu (tylko kwalifikator) ani strony', $po[2]['canon'] === 'on_target' && !isset($po[2]['side']));
check('zmienna spoza formularza nietknięta', $po[3]['canon'] === 'shot');
$po2 = UstawieniaKlubu::zastosujZnaczenia($po, [0 => '', 1 => 'nie-ma-takiego'], [0 => '']);
check('puste pojęcie = z pliku; nieznane odrzucone; pusta strona zdjęta',
    $po2[0]['canon'] === null && !isset($po2[0]['side']) && $po2[1]['canon'] === 'pass');

$konfig = Configurator::config($po, ['bilans']);
$v = $konfig['variables'];
check('templat przenosi side i uuids', $v[0]['side'] === 'them' && $v[1]['uuids'] === [$uuid(3)]);
check('bez pól W7 templat bez nowych kluczy', !array_key_exists('side', $v[3]) && !array_key_exists('uuids', $v[3]));
check('Configurator przyjmuje pojęcia prezentacji dla tagów',
    in_array('pass', Configurator::dozwoloneCanon('tag'), true)
    && !in_array('pass', Configurator::dozwoloneCanon('label'), true));

// ============================================= F/G/H przelot przez kolejkę
echo "\n== F/G/H: import przez cron (inspekcja + raport) ==\n";

Db::run('UPDATE matches SET club_home_id = 1, club_away_id = 3 WHERE id = :id', ['id' => $meczA]);
Db::run("INSERT INTO clubs (owner_id, club_key, name) VALUES (1, 'GAMMAKEY1', 'Gamma Dolna')");
$csvE = plik("tag_name,begin,end,players,labels,team,comment,pos_x_meters,pos_y_meters,pos_target_x_meters,pos_target_y_meters\n"
    . "Strzał,1302,1308,Zawodnik Jeden,,KLUB A,\"xG 0,4\",90,30,,\n"
    . "Gol,1300,1310,,,RYWAL C,,,,,\n"
    . "Strzał,2000,2006,,,RYWAL C,\"xG 1,5\",20,30,,\n"
    . "Posiadanie Gamma Dolna,10,70,,,,,,,,\n"
    . "Strzał,2500,2506,,,KLUB OBCY,\"xG 0,1\",50,30,,\n");
$jsonE = plik(json_encode(['software' => ['version' => '1.12.21'], 'dependencies' => [
    ['type' => 'tag', 'data' => ['name' => 'Strzał', 'uuid' => $uuid(1), 'time_before' => 3, 'time_after' => 3, 'params' => ['cm' => false]]],
    ['type' => 'tag', 'data' => ['name' => 'Gol', 'uuid' => $uuid(2), 'time_before' => 5, 'time_after' => 5, 'params' => ['cm' => false]]],
    ['type' => 'tag', 'data' => ['name' => 'Posiadanie Gamma Dolna', 'uuid' => $uuid(3), 'time_before' => 0, 'time_after' => 0, 'params' => ['cm' => true]]],
]], JSON_UNESCAPED_UNICODE), 'json');

$impE = Imports::create(1, $csvE, $jsonE, hash_file('sha256', $csvE), 1, Upload::sha256Zdarzen($csvE));
$meczE = (int) Imports::find($impE)['match_id'];
Db::run('UPDATE matches SET club_home_id = 1, club_away_id = 3, score_us = 2, score_them = 2 WHERE id = :id', ['id' => $meczE]);
Imports::queueInspect($impE, 1);
cron();
ca_test_db($baza, false);
$poInspekcji = Imports::find($impE);
check('inspekcja potwierdza skrót liczony przez panel',
    $poInspekcji['sha256_zdarzen'] === Upload::sha256Zdarzen($csvE));
$odcisk = ProfilAnalityka::odcisk($poInspekcji);
check('inspekcja zapisuje odcisk profilu (UUID i nazwy znormalizowane)',
    $odcisk !== null && $odcisk['uuid'] === [$uuid(1), $uuid(2), $uuid(3)]
    && in_array('posiadanie gamma dolna', $odcisk['nazwy'], true));

$job = Imports::queueBuild($impE, 1);
Db::run('UPDATE matches SET club_home_id = 1, club_away_id = 3 WHERE id = :id', ['id' => $meczE]);
$log = cron($job);
ca_test_db($baza, false);
check('raport zbudowany', (string) Db::one('SELECT status FROM jobs WHERE id = :id', ['id' => $job])['status'] === 'done', $log);

$config = json_decode((string) @file_get_contents($magazyn . '/jobs/' . $job . '/config.json'), true);
check('config: wynik wpisany ręcznie idzie do silnika', ($config['match']['score'] ?? null) === ['us' => 2, 'them' => 2]);
check('config: znane drużyny z panelu', in_array('Gamma Dolna', (array) ($config['znane_druzyny'] ?? []), true));
check('config: ocena profilu (pierwszy taki układ w klubie = nowy)', ($config['profil']['nowy'] ?? null) === true,
    json_encode($config['profil'] ?? null));

$poRaporcie = Imports::find($impE);
$typy = array_column(Imports::anomalie($poRaporcie), 'typ');
check('anomalia: gol z inną drużyną niż strzał', in_array('gol_inna_druzyna_niz_strzal', $typy, true), implode(',', $typy));
check('anomalia: trzecia drużyna', in_array('trzecia_druzyna', $typy, true));
check('anomalia: drużyna spoza meczu w nazwie tagu', in_array('druzyna_w_nazwie_tagu', $typy, true));
check('anomalia: xG spoza 0..1', in_array('xg_poza_zakresem', $typy, true));
check('niezmienniki silnika i panelu zgodne', (int) $poRaporcie['niezmienniki_ok'] === 1,
    json_encode(Imports::niezmienniki($poRaporcie), JSON_UNESCAPED_UNICODE));
$kody = array_column(Imports::niezmienniki($poRaporcie), 'kod');
check('niezmienniki panelu dopisane (baza, ekran Zawodnicy)',
    in_array('zdarzenia_w_bazie', $kody, true) && in_array('zawodnicy_na_ekranie', $kody, true), implode(',', $kody));
check('profil zapisany jako nowy', (int) $poRaporcie['profil_nowy'] === 1);

$gol = Db::one("SELECT team, team_side FROM events WHERE match_id = :m AND tag_name = 'Gol'", ['m' => $meczE]);
check('gol w tabeli zdarzeń z drużyną STRZAŁU (KLUB A), nie wiersza', $gol !== null && $gol['team'] === 'KLUB A'
    && $gol['team_side'] === 'us', json_encode($gol, JSON_UNESCAPED_UNICODE));
$html = (string) @file_get_contents((string) Db::one('SELECT html_path FROM reports WHERE match_id = :m', ['m' => $meczE])['html_path']);
check('raport ma sekcję „Wszystkie tagi z pliku" i baner nowego profilu',
    str_contains($html, 'id="sec-plik"') && str_contains($html, 'data-baner="profil"'));
check('brak alertu, gdy wszystko zgodne',
    !in_array('RAPORT_NIEZGODNY_Z_PLIKIEM', array_column(Alerts::all(), 'code'), true));

echo "\n== H: naruszenie niezmiennika ==\n";
Db::run("DELETE FROM events WHERE match_id = :m AND tag_name = 'Gol'", ['m' => $meczE]);
$meta = json_decode((string) file_get_contents($magazyn . '/jobs/' . $job . '/meta.json'), true);
$lista = KontrolaImportu::sprawdz($impE, $meczE, $meta);
$zle = array_values(array_filter($lista, static fn($n) => !$n['ok']));
check('zgubione zdarzenie w bazie łamie niezmiennik', count($zle) === 1 && $zle[0]['kod'] === 'zdarzenia_w_bazie',
    json_encode($zle, JSON_UNESCAPED_UNICODE));
check('imports.niezmienniki_ok = 0', (int) Imports::find($impE)['niezmienniki_ok'] === 0);
check('alert admina z numerem meczu', in_array('RAPORT_NIEZGODNY_Z_PLIKIEM', array_column(Alerts::all(), 'code'), true));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
