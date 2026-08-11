<?php
declare(strict_types=1);

/**
 * Przelot całej kolejki: żądanie kolejkuje → cron wykonuje → strona pokazuje wynik.
 *
 * To jedyny test, który dowodzi, że przebudowa pod `disable_functions` działa
 * jako całość. Zestawy statyczne pilnują, żeby warstwa żądań nie wołała procesów;
 * ten sprawdza, że po tym zakazie cokolwiek jeszcze się liczy.
 *
 * Uruchomienie:  php test_kolejka.php
 */

use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\Imports;
use CoachAnalyze\Jobs;
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

// ---------------------------------------------------------------- środowisko
$baza    = $here . '/kolejka.sqlite';
$magazyn = $here . '/kolejka_storage';
$logFile = $here . '/kolejka.log';
$envFile = $here . '/.env.kolejka';

@unlink($baza);
@unlink($logFile);
exec('rm -rf ' . escapeshellarg($magazyn));
mkdir($magazyn . '/uploads', 0770, true);
mkdir($magazyn . '/reports', 0770, true);

// Python z venv projektu — ten sam, którego używa produkcja.
// Lokalnie paczka nie jest w venv zainstalowana (interpreter 3.9, silnik wymaga
// 3.11), więc podajemy PYTHONPATH. Na serwerze robi to `pip install -e engine`.
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
    'APP_URL=http://localhost',
    'SESSION_NAME=ca_test',
    'REDIS_SOCKET=',
    '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';

ca_test_db($baza);

echo "== przygotowanie ==\n";
check('konfiguracja wczytana z pliku testowego', Config::loadedFrom() === $envFile, (string) Config::loadedFrom());
check('interpreter Pythona istnieje', is_file($python), $python);

// Eksport LiveTag: bierzemy przykład używany przez pozostałe zestawy.
$csvZrodlo = $here . '/synt.csv';
check('jest przykładowy eksport', is_file($csvZrodlo), $csvZrodlo);
if (!is_file($csvZrodlo)) {
    echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
    exit(1);
}

$csv = $magazyn . '/uploads/' . bin2hex(random_bytes(6)) . '.csv';
copy($csvZrodlo, $csv);

// ---------------------------------------------------------------- kolejkowanie
echo "\n== warstwa żądań: kolejkuje i nic nie uruchamia ==\n";

$przedCzasem = microtime(true);
$importId = Imports::create(1, $csv, null, hash_file('sha256', $csv));
$jobId    = Imports::queueInspect($importId, 1);
$trwalo   = microtime(true) - $przedCzasem;

$job = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);

check('zadanie powstało', $job !== null);
check('status to queued', ($job['status'] ?? '') === 'queued', (string) ($job['status'] ?? ''));
check('typ to inspect', ($job['type'] ?? '') === 'inspect', (string) ($job['type'] ?? ''));
check('nic nie ruszyło — started_at puste', ($job['started_at'] ?? null) === null);
// Gdyby kolejkowanie uruchamiało Pythona, samo zaimportowanie modułu zajęłoby
// więcej niż ćwierć sekundy. To wykrywa powrót „szybkiej ścieżki" tylnymi drzwiami.
check('kolejkowanie było natychmiastowe', $trwalo < 0.25, sprintf('%.3f s', $trwalo));

$import = Imports::find($importId);
check('inspekcja jeszcze się nie odbyła', empty($import['coverage_json']));

// ---------------------------------------------------------------- wykonanie
echo "\n== cron: podejmuje i wykonuje ==\n";

$wyjscie = [];
$kod = 0;
exec(
    'PYTHONPATH=' . escapeshellarg($root . '/engine') . ' CA_ENV_PATH=' . escapeshellarg($envFile) . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1',
    $wyjscie,
    $kod
);

check('worker zakończył się kodem 0', $kod === 0, 'kod ' . $kod . ': ' . implode(' | ', array_slice($wyjscie, -3)));

// Worker działa w osobnym procesie i ma własne połączenie — czytamy bazę na nowo.
ca_test_db($baza);
$job = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $jobId]);

check('zadanie nie jest już w kolejce', ($job['status'] ?? '') !== 'queued', (string) ($job['status'] ?? ''));
check('zadanie podjęte — started_at ustawione', ($job['started_at'] ?? null) !== null);
check('zadanie domknięte — finished_at ustawione', ($job['finished_at'] ?? null) !== null);
check('licznik prób podbity', (int) ($job['attempts'] ?? 0) === 1, (string) ($job['attempts'] ?? 0));

$powodzenie = ($job['status'] ?? '') === 'done';
check('inspekcja zakończona powodzeniem', $powodzenie,
    'status=' . ($job['status'] ?? '?') . ' exit=' . ($job['exit_code'] ?? '?')
    . ' błąd=' . substr((string) ($job['error_text'] ?? ''), 0, 160));

if ($powodzenie) {
    $import = Imports::find($importId);
    check('wynik inspekcji zapisany w bazie', !empty($import['coverage_json']));
    $pokrycie = json_decode((string) $import['coverage_json'], true);
    check('wynik jest poprawnym JSON-em', is_array($pokrycie));
}

// ---------------------------------------------------------------- błędy
echo "\n== traceback nie trafia do przeglądarki ==\n";

$zlyCsv = $magazyn . '/uploads/' . bin2hex(random_bytes(6)) . '.csv';
file_put_contents($zlyCsv, "to nie jest eksport LiveTag\nani trochę\n");
$zlyImport = Imports::create(1, $zlyCsv, null, hash_file('sha256', $zlyCsv));
$zleJob    = Imports::queueInspect($zlyImport, 1);

exec('PYTHONPATH=' . escapeshellarg($root . '/engine') . ' CA_ENV_PATH=' . escapeshellarg($envFile) . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1');
ca_test_db($baza);
$zle = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $zleJob]);

check('zły plik kończy się statusem failed', ($zle['status'] ?? '') === 'failed', (string) ($zle['status'] ?? ''));
$tekst = (string) ($zle['error_text'] ?? '');
check('powód zapisany po polsku', $tekst !== '', $tekst);
check('w bazie NIE MA tracebacku', !str_contains($tekst, 'Traceback (most recent call last)'), substr($tekst, 0, 120));
check('w bazie NIE MA ścieżek do plików silnika', !preg_match('#/[a-z_]+/coachanalyze/[a-z_]+\.py#', $tekst), substr($tekst, 0, 120));

// ---------------------------------------------------------------- ponowienie
echo "\n== ponowienie wraca do kolejki ==\n";
Jobs::retry($zleJob, 1);
ca_test_db($baza);
$po = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $zleJob]);
check('po ponowieniu status to queued', ($po['status'] ?? '') === 'queued', (string) ($po['status'] ?? ''));
check('poprzedni błąd wyczyszczony', ($po['error_text'] ?? null) === null);

// ---------------------------------------------------------------- render i powiadomienie
echo "\n== pelny render: identyfikator raportu i temat powiadomienia ==\n";

ca_test_db($baza);

// Mecz z JEDNYM znanym klubem — dokladnie sytuacja z produkcji, w ktorej
// temat brzmial „KS WIAZOWNICA — rywal".
//
// Kluby inne niz nasz USUWAMY z bazy: `assignClubs()` uruchamia sie dwa razy
// (przy kolejkowaniu i przy zapisie pokrycia w renderze) i dopasowuje po nazwach
// z eksportu. Dopoki w bazie stoi klub pasujacy do drugiej druzyny, przeciwnik
// zostanie przypisany i test sprawdzalby inny przypadek, niz zamierza.
Db::run("DELETE FROM matches WHERE club_away_id IS NOT NULL AND id <> (SELECT match_id FROM imports WHERE id = :i)", ['i' => $importId]);
Db::run('DELETE FROM clubs WHERE is_own_team = 0');
Db::run("INSERT INTO clubs (id, owner_id, club_key, name, color_primary, is_own_team, created_at)
         VALUES (50, 1, 'WIA7K2QX', 'KLUB A', '#E8722C', 1, :t)", ['t' => Stats::now()]);
Db::run("UPDATE matches SET club_home_id = 50, club_away_id = NULL, played_at = '2026-08-11'
          WHERE id = (SELECT match_id FROM imports WHERE id = :i)", ['i' => $importId]);
Db::run("UPDATE users SET email = 'trener@example.test' WHERE id = 1");

$buildJob = Imports::queueBuild($importId, 1);

// Przeciwnika czyscimy PO `queueBuild()`, bo ono ponownie dopasowuje kluby po
// nazwach z eksportu i potrafi przypisac rywala. Test sprawdza temat maila przy
// NIEZNANYM przeciwniku, wiec ten stan trzeba ustawic tuz przed renderem.
Db::run("UPDATE matches SET club_away_id = NULL
          WHERE id = (SELECT match_id FROM imports WHERE id = :i)", ['i' => $importId]);

exec('PYTHONPATH=' . escapeshellarg($root . '/engine') . ' CA_ENV_PATH=' . escapeshellarg($envFile)
    . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' ' . $buildJob . ' 2>&1', $wyjB, $kodB);

ca_test_db($baza);
$zadanieB = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $buildJob]);
$powiodloSie = ($zadanieB['status'] ?? '') === 'done';

check('render zakonczony powodzeniem', $powiodloSie,
    'status=' . ($zadanieB['status'] ?? '?') . ' blad=' . substr((string) ($zadanieB['error_text'] ?? ''), 0, 120));

if ($powiodloSie) {
    $raport = Db::one('SELECT * FROM reports ORDER BY id DESC LIMIT 1');
    check('raport zapisany', $raport !== null);
    check('raport ma prawdziwy identyfikator', (int) ($raport['id'] ?? 0) > 0);

    $pow = Db::one("SELECT * FROM notifications WHERE type = 'report.ready' ORDER BY id DESC LIMIT 1");
    check('powstalo powiadomienie o gotowym raporcie', $pow !== null);

    if ($pow !== null) {
        // USTERKA 1: mail prowadzil do /raport/0.
        check('odnosnik NIE prowadzi do /raport/0',
            (string) $pow['url'] !== '/raport/0', (string) $pow['url']);
        check('odnosnik zawiera prawdziwy identyfikator raportu',
            (string) $pow['url'] === '/raport/' . (int) $raport['id'],
            (string) $pow['url'] . ' zamiast /raport/' . (int) $raport['id']);

        // USTERKA 3: temat zawieral slowo zastepcze „rywal".
        //
        // Nazwe czytamy Z BAZY, a nie z tego, co wstawil test: `queueBuild()`
        // ponownie dopasowuje kluby po nazwach z eksportu i potrafi podmienic
        // przypisanie. Test ma sprawdzac temat, a nie dopasowywanie klubow.
        $tytul = (string) $pow['title'];
        $naszKlub = Db::one(
            'SELECT c.name FROM matches m JOIN clubs c ON c.id = m.club_home_id
              WHERE m.id = (SELECT match_id FROM imports WHERE id = :i)',
            ['i' => $importId]
        );
        check('temat zawiera nazwe naszego klubu',
            $naszKlub !== null && str_contains($tytul, (string) $naszKlub['name']),
            $tytul);
        check('temat NIE zawiera slowa zastepczego „rywal"',
            !preg_match('/\brywal\b/iu', $tytul), $tytul);
        check('temat NIE zawiera „nasza druzyna"',
            !preg_match('/nasza dru/iu', $tytul), $tytul);
        check('zamiast nazwy przeciwnika jest data meczu',
            str_contains($tytul, 'mecz z 11.08'), $tytul);
    }
}

// ---------------------------------------------------------------- poczta
echo "\n== wysylka poczty przez workera ==\n";

// SMTP wskazuje na port, na ktorym nic nie nasluchuje — chcemy awarii.
file_put_contents($envFile, implode("\n", [
    'APP_ENV=test', 'DB_DRIVER=sqlite', 'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $magazyn, 'LOG_PATH=' . $logFile,
    'PYTHON_BIN=' . $python, 'ENGINE_TIMEOUT=60',
    'APP_URL=http://localhost', 'SESSION_NAME=ca_test', 'REDIS_SOCKET=',
    'SMTP_HOST=127.0.0.1', 'SMTP_PORT=2599', 'SMTP_ENCRYPTION=none',
    'SMTP_TIMEOUT=2', 'MAIL_FROM=raporty@example.test', '',
]));

ca_test_db($baza);
Db::run("UPDATE users SET email = 'trener@example.test' WHERE id = 1");
Db::run("INSERT INTO notifications (user_id, type, title, created_at, mail_status, mail_to)
         VALUES (1, 'report.ready', 'Raport gotowy', :t, 'pending', 'trener@example.test')",
    ['t' => Stats::now()]);
$notifId = (int) Db::pdo()->lastInsertId();
Db::run("INSERT INTO jobs (type, payload_json, status, created_at)
         VALUES ('send_mail', :p, 'queued', :t)",
    ['p' => json_encode(['notification_id' => $notifId]), 't' => Stats::now()]);
$mailJobId = (int) Db::pdo()->lastInsertId();

exec('PYTHONPATH=' . escapeshellarg($root . '/engine') . ' CA_ENV_PATH=' . escapeshellarg($envFile)
    . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' ' . $mailJobId . ' 2>&1', $wyj2, $kod2);

check('worker nie wywrocil sie na awarii poczty', $kod2 === 0, 'kod ' . $kod2);

ca_test_db($baza);
$mailJob = Db::one('SELECT * FROM jobs WHERE id = :id', ['id' => $mailJobId]);
check('zadanie wysylki wrocilo do kolejki', ($mailJob['status'] ?? '') === 'queued',
    (string) ($mailJob['status'] ?? ''));
check('z wyznaczonym terminem kolejnej proby', ($mailJob['available_at'] ?? null) !== null);
check('powod zapisany po polsku',
    str_contains((string) $mailJob['error_text'], 'Próba 1 z 5'), (string) $mailJob['error_text']);
check('powiadomienie nadal pending',
    Db::one('SELECT mail_status FROM notifications WHERE id = :id', ['id' => $notifId])['mail_status'] === 'pending');

// Kluczowe: worker NIE podejmie zadania przed terminem.
exec('CA_ENV_PATH=' . escapeshellarg($envFile) . ' php ' . escapeshellarg($root . '/app/bin/run_job.php') . ' 2>&1');
ca_test_db($baza);
$poPrzejsciu = Db::one('SELECT attempts FROM jobs WHERE id = :id', ['id' => $mailJobId]);
check('kolejne przejscie crona NIE rusza zadania przed terminem',
    (int) $poPrzejsciu['attempts'] === 1, 'prob: ' . $poPrzejsciu['attempts']);

// ---------------------------------------------------------------- sprzątanie
@unlink($baza);
@unlink($envFile);
@unlink($logFile);
exec('rm -rf ' . escapeshellarg($magazyn));

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
