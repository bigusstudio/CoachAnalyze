<?php
declare(strict_types=1);

/**
 * PRZELOT PRZEZ PRAWDZIWY HTTP: cała ścieżka importu, od uploadu po render.
 *
 * Powód istnienia: kreator mapowań był na produkcji nieosiągalny, mimo że
 * zestaw testów jednostkowych był zielony — `needsMapping()` działało poprawnie
 * na danych, których zapis nigdy nie następował (`coverage_json` bez
 * `unmapped_tags`). Ta klasa błędu jest niewykrywalna bez przejścia PEŁNEJ
 * ścieżki: przeglądarka → PHP-FPM → kolejka → cron → silnik → baza → ekran.
 *
 * Test podnosi wbudowany serwer PHP (żądania idą prawdziwym HTTP, z sesją,
 * CSRF-em i multipartem), atrapę Redisa (limiter logowania jest fail closed)
 * i PRAWDZIWY silnik Pythona przez cron (`run_job.php`).
 *
 * Scenariusze:
 *   1. świeży import z nowymi tagami → kreator ZATRZYMUJE i pokazuje liczby,
 *   2. próba ominięcia kreatora POST-em na /generuj → odbita,
 *   3. zapis profilu → `mapping_profile_id` ustawione, render dostaje profil,
 *   4. artefakt STAREGO kodu (coverage_json bez unmapped_tags — stan produkcji
 *      sprzed naprawy) → kreator się nie pokaże, a „ponów" na zadaniu inspect
 *      naprawia wpis. To jest instrukcja obsługi wdrożenia, zapisana jako test.
 *
 * Uruchomienie:  php test_mapowania_http.php
 */

use CoachAnalyze\Db;
use CoachAnalyze\Mappings;
use CoachAnalyze\Stats;
use CoachAnalyze\View;

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
$baza    = $here . '/mapowania_http.sqlite';
$magazyn = $here . '/mapowania_http_storage';
$logFile = $here . '/mapowania_http.log';
$envFile = $here . '/.env.mapowania_http';
$sock    = $here . '/mapowania_http_redis.sock';
$port    = 8946;

@unlink($baza);
@unlink($logFile);
@unlink($sock);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);

// Ten sam interpreter, którego używa test_kolejka: paczka nie jest w venv
// zainstalowana, więc silnik dostaje PYTHONPATH na katalog engine/.
$python = $root . '/venv/bin/python';
putenv('PYTHONPATH=' . $root . '/engine');

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn,
    'LOG_PATH=' . $logFile,
    'PYTHON_BIN=' . $python,
    'ENGINE_TIMEOUT=60',
    'APP_URL=http://127.0.0.1:' . $port,
    'SESSION_NAME=ca_test',
    'REDIS_SOCKET=' . $sock,
    // Niskie parametry argon2id — test haszuje dodatkowe konta, a koszt
    // produkcyjny (96 MB, t=5) nic tu nie sprawdza.
    'ARGON_MEMORY_COST=8192',
    'ARGON_TIME_COST=1',
    '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';

ca_test_db($baza, false);
$teraz = Stats::now();

// Klub „nasz" o nazwie zgodnej z kolumną `team` eksportów testowych.
Db::run("INSERT INTO clubs (id, owner_id, club_key, name, color_primary, is_own_team, created_at)
         VALUES (1, 1, 'HUT7K2QX', 'KLUB A', '#E8722C', 1, :t)", ['t' => $teraz]);

// ---------------------------------------------------------------- procesy
$procesy = [];

// Atrapa Redisa — bez niej logowanie jest zablokowane (fail closed).
$procesy[] = proc_open(
    'php ' . escapeshellarg($here . '/fake_redis.php') . ' ' . escapeshellarg($sock),
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $rury1
);
for ($i = 0; $i < 50 && !file_exists($sock); $i++) {
    usleep(100000);
}

// Wbudowany serwer PHP z index.php jako routerem — każde żądanie przechodzi
// przez ten sam plik co na produkcji.
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

/**
 * Jedno żądanie HTTP. Przekierowań NIE śledzimy — to, dokąd aplikacja odsyła,
 * jest właśnie przedmiotem testu.
 *
 * @param array{form?:array<string,mixed>, multipart?:array{0:string,1:string}} $opts
 * @return array{status:int, location:?string, body:string}
 */
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
    } elseif (isset($opts['multipart'])) {
        [$tresc, $typ] = $opts['multipart'];
        $naglowki[] = 'Content-Type: ' . $typ;
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

/** Treść multipart — upload pliku tak, jak robi to formularz przeglądarki. */
function multipart(array $pola, array $pliki): array
{
    $granica = '----ca' . bin2hex(random_bytes(8));
    $out = '';
    foreach ($pola as $nazwa => $wartosc) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$nazwa}\"\r\n\r\n{$wartosc}\r\n";
    }
    foreach ($pliki as $nazwa => [$plik, $zawartosc]) {
        $out .= "--{$granica}\r\nContent-Disposition: form-data; name=\"{$nazwa}\"; filename=\"{$plik}\"\r\n"
            . "Content-Type: text/csv\r\n\r\n{$zawartosc}\r\n";
    }
    $out .= "--{$granica}--\r\n";
    return [$out, 'multipart/form-data; boundary=' . $granica];
}

/** Przejście crona — dokładnie tak, jak wykonuje je serwer, osobnym procesem. */
function cron(): int
{
    global $root, $envFile;
    exec(
        'CA_ENV_PATH=' . escapeshellarg($envFile)
        . ' PYTHONPATH=' . escapeshellarg($root . '/engine')
        . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1',
        $wyjscie,
        $kod
    );
    return $kod;
}

function csrfZ(string $html): string
{
    return preg_match('/name="csrf" value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
}

// ---------------------------------------------------------------- logowanie
echo "== logowanie przez HTTP ==\n";

$strona = http('GET', '/login');
check('strona logowania odpowiada', $strona['status'] === 200, 'status ' . $strona['status']);
$csrf = csrfZ($strona['body']);
check('formularz niesie token CSRF', $csrf !== '');

$logowanie = http('POST', '/login', ['form' => [
    'email'    => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf'     => $csrf,
]]);
check('zalogowanie przekierowuje do panelu', $logowanie['status'] === 302,
    'status ' . $logowanie['status']);

$formularz = http('GET', '/import');
check('formularz importu dostępny po zalogowaniu', $formularz['status'] === 200,
    'status ' . $formularz['status']);
$csrf = csrfZ($formularz['body']);
check('token CSRF po zalogowaniu', $csrf !== '');

// ---------------------------------------------------------------- scenariusz 1
echo "\n== świeży import: kreator MUSI zatrzymać ==\n";

$upload = http('POST', '/import', ['multipart' => multipart(
    ['csrf' => $csrf],
    ['csv' => ['nowe_tagi.csv', (string) file_get_contents($here . '/nowe_tagi.csv')]]
)]);
check('upload przekierowuje na stan zadania', $upload['status'] === 302
    && preg_match('#^/zadania/(\d+)$#', (string) $upload['location'], $m) === 1,
    $upload['status'] . ' → ' . (string) $upload['location']);
$jobInspect = (int) ($m[1] ?? 0);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports ORDER BY id DESC LIMIT 1');
$importId = (int) ($import['id'] ?? 0);
check('import zapisany bez pokrycia (nic nie ruszyło)', $import !== null && empty($import['coverage_json']));

$przedInspekcja = http('GET', '/import/' . $importId);
check('przed inspekcją pokrycie odsyła do stanu zadania',
    $przedInspekcja['status'] === 302
    && $przedInspekcja['location'] === '/zadania/' . $jobInspect,
    $przedInspekcja['status'] . ' → ' . (string) $przedInspekcja['location']);

check('cron wykonał inspekcję', cron() === 0);

ca_test_db($baza);
$import = Db::one('SELECT * FROM imports WHERE id = :id', ['id' => $importId]);
$meta = json_decode((string) $import['coverage_json'], true);
check('coverage_json niesie unmapped_tags — sedno naprawy',
    is_array($meta) && !empty($meta['unmapped_tags']),
    'klucze: ' . implode(', ', array_keys((array) $meta)));

$pokrycie = http('GET', '/import/' . $importId);
check('KREATOR ZATRZYMUJE: pokrycie przekierowuje na mapowanie',
    $pokrycie['status'] === 302
    && $pokrycie['location'] === '/import/' . $importId . '/mapowanie',
    $pokrycie['status'] . ' → ' . (string) $pokrycie['location']);

$kreator = http('GET', '/import/' . $importId . '/mapowanie');
check('ekran kreatora odpowiada', $kreator['status'] === 200, 'status ' . $kreator['status']);
check('kreator wymienia nowe tagi', str_contains($kreator['body'], 'AKCJA DEFENSYWNA')
    && str_contains($kreator['body'], 'SBZ PODAJĄCY'));
check('kreator zna klub profilu', str_contains($kreator['body'], 'KLUB A'));

// Kształt wzbogacony (silnik ≥ 0.9.0): liczby wystąpień zamiast kresek
// i etykiety towarzyszące. „NIEUDANA" jest etykietą ZNANĄ, więc na tym ekranie
// może pochodzić wyłącznie z próbki etykiet nierozpoznanego tagu.
check('liczby wystąpień są na ekranie, nie kreski',
    !str_contains($kreator['body'], View::t('mapping.count.unknown')));
check('etykiety towarzyszące widoczne przy tagu', str_contains($kreator['body'], 'NIEUDANA'));

// ---------------------------------------------------------------- scenariusz 2
echo "\n== ominięcie kreatora POST-em jest odbite ==\n";

$obejscie = http('POST', '/import/' . $importId . '/generuj', ['form' => ['csrf' => $csrf]]);
check('generowanie odsyła z powrotem do kreatora',
    $obejscie['status'] === 302
    && $obejscie['location'] === '/import/' . $importId . '/mapowanie',
    $obejscie['status'] . ' → ' . (string) $obejscie['location']);

ca_test_db($baza);
check('żadne zadanie renderu nie powstało',
    (int) Db::one("SELECT COUNT(*) AS ile FROM jobs WHERE type = 'build_report'")['ile'] === 0);

// ---------------------------------------------------------------- scenariusz 3
echo "\n== zapis profilu i render z profilem ==\n";

$decyzjeTagi = [];
$decyzjeEtykiety = [];
$nieznane = Mappings::unknown($meta, 1);
foreach ($nieznane['tags'] as $t) {
    $decyzjeTagi[$t['name']] = $t['name'] === '1x1 DEF' ? 'duel' : Mappings::NIE_ANALIZUJ;
}
foreach ($nieznane['labels'] as $l) {
    $decyzjeEtykiety[$l['name']] = Mappings::NIE_ANALIZUJ;
}

$zapis = http('POST', '/import/' . $importId . '/mapowanie', ['form' => [
    'csrf'  => $csrf,
    'tag'   => $decyzjeTagi,
    'label' => $decyzjeEtykiety,
    'note'  => 'przelot HTTP',
]]);
check('zapis wraca na pokrycie', $zapis['status'] === 302
    && $zapis['location'] === '/import/' . $importId,
    $zapis['status'] . ' → ' . (string) $zapis['location']);

ca_test_db($baza);
check('profil klubu zapisany',
    (int) Db::one('SELECT COUNT(*) AS ile FROM mapping_profiles WHERE club_id = 1')['ile'] === 1);
check('import pamięta wersję profilu — mapping_profile_id NIE jest null',
    Db::one('SELECT mapping_profile_id FROM imports WHERE id = :id', ['id' => $importId])['mapping_profile_id'] !== null,
    'to jest dokładnie produkcyjny symptom nieosiągalnego kreatora');

$poZapisie = http('GET', '/import/' . $importId);
check('po decyzjach pokrycie już NIE zatrzymuje', $poZapisie['status'] === 200,
    $poZapisie['status'] . ' → ' . (string) $poZapisie['location']);

$generuj = http('POST', '/import/' . $importId . '/generuj', ['form' => ['csrf' => $csrf]]);
check('generowanie przechodzi', $generuj['status'] === 302
    && preg_match('#^/zadania/(\d+)$#', (string) $generuj['location'], $m) === 1,
    $generuj['status'] . ' → ' . (string) $generuj['location']);
$jobBuild = (int) ($m[1] ?? 0);

check('cron wykonał render', cron() === 0);

ca_test_db($baza);
check('zadanie renderu domknięte',
    (string) Db::one('SELECT status FROM jobs WHERE id = :id', ['id' => $jobBuild])['status'] === 'done',
    (string) Db::one('SELECT error_text FROM jobs WHERE id = :id', ['id' => $jobBuild])['error_text']);
check('raport zapisany', Db::one('SELECT id FROM reports ORDER BY id DESC LIMIT 1') !== null);

$config = json_decode((string) @file_get_contents($magazyn . '/jobs/' . $jobBuild . '/config.json'), true);
$regulaDuel = null;
foreach ((array) ($config['mapping_profile']['rules'] ?? []) as $r) {
    if (($r['match']['tag'] ?? '') === '1x1 DEF') {
        $regulaDuel = $r;
    }
}
check('render dostał profil klubu w config.json',
    $regulaDuel !== null && $regulaDuel['concept'] === 'duel',
    'mapping_profile: ' . json_encode($config['mapping_profile'] ?? null, JSON_UNESCAPED_UNICODE));

$metaRenderu = json_decode((string) @file_get_contents($magazyn . '/jobs/' . $jobBuild . '/meta.json'), true);
check('po decyzjach silnik nie zgłasza nierozpoznanych tagów',
    ($metaRenderu['unmapped_tags'] ?? null) === [] && ($metaRenderu['unmapped_labels'] ?? null) === []);

// ---------------------------------------------------------------- scenariusz 4
echo "\n== artefakt starego kodu: stan produkcji i droga naprawy ==\n";

// Import z tagiem, o którym profil klubu nie wie.
$csvProdukcyjny = "tag_name,begin,end,team,labels,comment,pos_x_meters,pos_y_meters\n"
    . "TAG PRODUKCYJNY,10,20,KLUB A,\"NOWA ETYKIETA\",,50,30\n"
    . "TAG PRODUKCYJNY,30,40,KLUB A,,,51,31\n";

$upload2 = http('POST', '/import', ['multipart' => multipart(
    ['csrf' => $csrf],
    ['csv' => ['prod.csv', $csvProdukcyjny]]
)]);
preg_match('#^/zadania/(\d+)$#', (string) $upload2['location'], $m);
$jobInspect2 = (int) ($m[1] ?? 0);
check('drugi upload przyjęty', $upload2['status'] === 302 && $jobInspect2 > 0);

check('cron wykonał inspekcję', cron() === 0);
ca_test_db($baza);
$importId2 = (int) Db::one('SELECT id FROM imports ORDER BY id DESC LIMIT 1')['id'];

$swiezy = http('GET', '/import/' . $importId2);
check('świeża inspekcja zatrzymuje na kreatorze', $swiezy['status'] === 302
    && $swiezy['location'] === '/import/' . $importId2 . '/mapowanie');

/*
 * SYMULACJA STANU PRODUKCJI SPRZED NAPRAWY: stary kod zapisywał w
 * `coverage_json` wyłącznie obiekt `coverage` — bez `unmapped_tags`.
 * Takie wiersze zostają w bazie PO wdrożeniu naprawy i nie naprawią się same:
 * `needsMapping()` widzi pustkę, choć raport pokrycia (z `warnings_json`)
 * dalej wymienia tagi z nazwy. Dokładnie ten paradoks zgłoszono z produkcji.
 */
$metaStare = json_decode((string) Db::one(
    'SELECT coverage_json FROM imports WHERE id = :id', ['id' => $importId2]
)['coverage_json'], true);
unset($metaStare['unmapped_tags'], $metaStare['unmapped_labels']);
Db::run('UPDATE imports SET coverage_json = :c WHERE id = :id', [
    'c'  => json_encode($metaStare, JSON_UNESCAPED_UNICODE),
    'id' => $importId2,
]);

$staryWpis = http('GET', '/import/' . $importId2);
check('stary wpis NIE zatrzymuje na kreatorze (paradoks z produkcji)',
    $staryWpis['status'] === 200, $staryWpis['status'] . ' → ' . (string) $staryWpis['location']);
check('…choć raport pokrycia wymienia tag z nazwy',
    str_contains($staryWpis['body'], 'TAG PRODUKCYJNY'));

$dziura = http('POST', '/import/' . $importId2 . '/generuj', ['form' => ['csrf' => $csrf]]);
check('generowanie przechodzi MIMO nowych tagów — dziura starych wpisów',
    $dziura['status'] === 302
    && $dziura['location'] !== '/import/' . $importId2 . '/mapowanie',
    $dziura['status'] . ' → ' . (string) $dziura['location']);

// Zadanie renderu z dziury usuwamy — scenariusz sprawdza drogę naprawy,
// a nie render bez profilu.
ca_test_db($baza);
Db::run("DELETE FROM jobs WHERE type = 'build_report' AND status = 'queued'");
Db::run("UPDATE matches SET status = 'draft'
          WHERE id = (SELECT match_id FROM imports WHERE id = :id)", ['id' => $importId2]);

// DROGA NAPRAWY: „ponów" na zadaniu inspect (działa też dla `done`) uruchamia
// inspekcję nowym kodem i uzupełnia `coverage_json`.
$ponow = http('POST', '/zadania/' . $jobInspect2 . '/ponow', ['form' => ['csrf' => $csrf]]);
check('ponowienie zadania inspect przyjęte', $ponow['status'] === 302,
    'status ' . $ponow['status']);
check('cron ponowił inspekcję', cron() === 0);

$poNaprawie = http('GET', '/import/' . $importId2);
check('po ponowieniu kreator ZNÓW zatrzymuje',
    $poNaprawie['status'] === 302
    && $poNaprawie['location'] === '/import/' . $importId2 . '/mapowanie',
    $poNaprawie['status'] . ' → ' . (string) $poNaprawie['location']);

// ---------------------------------------------------------------- scenariusz 5
echo "\n== viewer nie zapisze profilu mapowań (bramka roli) ==\n";

/*
 * Profil mapowań zmienia liczby w raportach tak samo jak generowanie.
 * Viewer OGLĄDA — bramka `requireCan('mappings')` ma odpowiadać 404,
 * nie 403: rola bez uprawnienia nie ma powodu wiedzieć, że trasa istnieje.
 */
ca_test_db($baza);
Db::run("INSERT INTO users (email, pass_hash, display_name, role)
         VALUES ('viewer@example.com', :h, 'Widz', 'viewer')",
    ['h' => \CoachAnalyze\Auth::hashPassword('haslo-widza-do-testow')]);

$ciasteczka = [];   // nowa przeglądarka
$strona = http('GET', '/login');
$logowanie = http('POST', '/login', ['form' => [
    'email'    => 'viewer@example.com',
    'password' => 'haslo-widza-do-testow',
    'csrf'     => csrfZ($strona['body']),
]]);
check('viewer się loguje', $logowanie['status'] === 302, 'status ' . $logowanie['status']);
$csrfViewer = csrfZ(http('GET', '/import')['body']);

foreach ([
    'zapis mapowań importu' => '/import/' . $importId2 . '/mapowanie',
    'zapis mapowań klubu'   => '/kluby/1/mapowania',
    'edycja klubu'          => '/kluby/1',
    'ponowienie zadania'    => '/zadania/' . $jobInspect2 . '/ponow',
    'generowanie raportu'   => '/import/' . $importId2 . '/generuj',
] as $nazwa => $trasa) {
    $proba = http('POST', $trasa, ['form' => ['csrf' => $csrfViewer, 'tag' => ['X' => '__ignoruj__']]]);
    check("viewer odbity (404): {$nazwa}", $proba['status'] === 404,
        $trasa . ' → ' . $proba['status']);
}

ca_test_db($baza);
check('po próbach viewera nie przybyło wersji profilu',
    (int) Db::one('SELECT COUNT(*) AS ile FROM mapping_profiles')['ile'] === 1);

// ---------------------------------------------------------------- scenariusz 6
echo "\n== indeks współczynników (M1): panel, viewer, wersja publiczna ==\n";

// Viewer CZYTA indeks…
$indeksViewer = http('GET', '/indeks');
check('viewer widzi listę haseł', $indeksViewer['status'] === 200
    && str_contains($indeksViewer['body'], 'xG'));

// …ale nie edytuje.
$edycjaViewer = http('POST', '/indeks/xg', ['form' => [
    'csrf' => $csrfViewer, 'name' => 'X', 'definition' => 'Y',
]]);
check('viewer odbity (404) od edycji hasła', $edycjaViewer['status'] === 404,
    'status ' . $edycjaViewer['status']);

// Operator edytuje — powstaje wersja klubowa.
$ciasteczka = [];
$strona = http('GET', '/login');
http('POST', '/login', ['form' => [
    'email'    => 'operator@example.com',
    'password' => 'bardzo-dlugie-haslo-testowe',
    'csrf'     => csrfZ($strona['body']),
]]);
$haslo = http('GET', '/indeks/xg');
check('operator widzi hasło xG', $haslo['status'] === 200
    && str_contains($haslo['body'], 'porównawczo'), 'status ' . $haslo['status']);

$edycja = http('POST', '/indeks/xg?klub=1', ['form' => [
    'csrf'           => csrfZ($haslo['body']),
    'name'           => 'xG po naszemu',
    'definition'     => 'Definicja metodyki klubu.',
    'estimated_note' => 'Nasze zastrzeżenie o modelu.',
]]);
check('edycja zapisuje wersję klubową', $edycja['status'] === 302,
    $edycja['status'] . ' → ' . (string) $edycja['location']);

ca_test_db($baza);
check('wersja klubowa w bazie',
    (int) Db::one("SELECT COUNT(*) AS ile FROM index_terms WHERE club_id = 1 AND slug = 'xg'")['ile'] === 1);

// Hasło PUBLICZNE — nowa przeglądarka, zero sesji.
$ciasteczka = [];
$publiczne = http('GET', '/r/HUT7K2QX/i/xg');
check('hasło publiczne dostępne bez logowania', $publiczne['status'] === 200
    && str_contains($publiczne['body'], 'xG po naszemu'),
    'status ' . $publiczne['status']);
check('strona publiczna bez nawigacji panelu',
    !str_contains($publiczne['body'], 'side__item'));

$zlyKlucz = http('GET', '/r/ZLYKLUCZ/i/xg');
$zlySlug  = http('GET', '/r/HUT7K2QX/i/nie-ma');
check('zły klucz klubu daje 404', $zlyKlucz['status'] === 404, 'status ' . $zlyKlucz['status']);
check('złe hasło daje IDENTYCZNE 404', $zlySlug['status'] === 404
    && $zlySlug['body'] === $zlyKlucz['body']);

// Raport z renderu niesie blok odsyłaczy do indeksu.
$plikRaportu = Db::one('SELECT html_path FROM reports ORDER BY id DESC LIMIT 1');
$trescRaportu = $plikRaportu !== null ? (string) @file_get_contents((string) $plikRaportu['html_path']) : '';
check('raport ma blok odsyłaczy do indeksu', str_contains($trescRaportu, 'ca-indeks'));
check('odsyłacz prowadzi pod publiczny adres klubu',
    str_contains($trescRaportu, '/r/HUT7K2QX/i/'));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
