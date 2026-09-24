<?php
declare(strict_types=1);
/** Weryfikacja Etapu 4a: zapytania pulpitu, widoki, motyw, ucieczka HTML. */

use CoachAnalyze\Db;
use CoachAnalyze\Jobs;
use CoachAnalyze\Stats;
use CoachAnalyze\View;

$root = dirname(__DIR__, 3);
ob_start();
require $root . '/app/src/bootstrap.php';
require __DIR__ . '/seed.php';

$ok = 0; $fail = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $ok, $fail;
    if ($cond) { $ok++; echo "  OK   $name\n"; }
    else { $fail++; echo "  BŁĄD $name" . ($detail ? " — $detail" : '') . "\n"; }
}

$db = sys_get_temp_dir() . '/ca_4a_' . getmypid() . '.sqlite';
@unlink($db);
ca_test_db($db);

// ---------------------------------------------------------------- liczniki
echo "== liczniki pulpitu ==\n";
$c = Stats::counters();
check('mecze w bieżącym sezonie', $c['matches'] === 6, "jest {$c['matches']}");
check('etykieta sezonu z bazy', $c['matches_scope'] === '2026/2027', $c['matches_scope']);
check('wygenerowane raporty', $c['reports'] === 2, (string) $c['reports']);
check('aktywne linki — bez wygasłych i odwołanych', $c['links'] === 1, (string) $c['links']);
check('zadania w kolejce', $c['queued'] === 1, (string) $c['queued']);

// ---------------------------------------------------------------- mecze
echo "\n== ostatnie mecze ==\n";
$m = Stats::recentMatches(5);
check('dokładnie pięć pozycji', count($m) === 5, (string) count($m));
check('najnowszy mecz pierwszy', str_starts_with((string) $m[0]['played_at'], '2026-08-09'));
// Mecz bez daty ląduje na końcu porządku, więc przy pięciu datowanych nie wchodzi
// do „ostatnich 5" — datowane mają pierwszeństwo. To jest zamierzone.
check('mecz bez daty nie wypiera datowanych z listy',
    !in_array(null, array_column($m, 'played_at'), true));
$wszystkie = Stats::recentMatches(50);
check('mecz bez daty jest na końcu pełnej listy',
    $wszystkie[count($wszystkie) - 1]['played_at'] === null);
check('mecz z niepełnym przypisaniem klubów nadal na liście',
    in_array(null, array_column($m, 'away_name'), true));

// ---------------------------------------------------------------- zadania
echo "\n== zadania wymagające uwagi ==\n";
$j = Stats::jobsNeedingAttention();
$statusy = array_column($j, 'status');
check('są tylko running i failed', array_diff($statusy, ['running', 'failed']) === []);
check('failed przed running', $statusy[0] === 'failed', implode(',', $statusy));
check('stary failed (5 dni) pominięty', count($j) === 2, 'jest ' . count($j));
check('zadanie w kolejce nie trafia do panelu uwagi', !in_array('queued', $statusy, true));

// ---------------------------------------------------------------- ponowienie
echo "\n== ponowienie zadania ==\n";
$failedId = (int) $j[0]['id'];
check('ponowienie zadania failed', Jobs::retry($failedId));
$po = Jobs::find($failedId);
check('status wrócił do queued', $po['status'] === 'queued', (string) $po['status']);
check('licznik prób wyzerowany', (int) $po['attempts'] === 0, (string) $po['attempts']);
check('ślady po błędzie wyczyszczone',
    $po['error_text'] === null && $po['exit_code'] === null && $po['finished_at'] === null);
check('ponowne ponowienie tego samego zadania nic nie robi', Jobs::retry($failedId) === false);

$runningId = (int) Db::one("SELECT id FROM jobs WHERE status='running'")['id'];
check('nie da się ponowić zadania w toku', Jobs::retry($runningId) === false);
check('ponowienie odnotowane w audit_log',
    (int) Db::one("SELECT COUNT(*) c FROM audit_log WHERE action='job.retry'")['c'] === 1);

$doneJob = Db::one("SELECT * FROM jobs WHERE status='done'");
check('raport odnaleziony dla zadania done', Jobs::reportFor($doneJob) !== null);

// ---------------------------------------------------------------- widoki
echo "\n== widoki ==\n";
// Do widoku podajemy pełną listę, żeby trafiły w nią także wiersze niepełne.
$html = View::render('dashboard', [
    'counters' => $c, 'matches' => Stats::recentMatches(50), 'jobs' => $j, 'notice' => null,
]);
/*
 * KOTWICE PRZEPISANE 2026-09-24 (sesja 3,5). Pulpit dostał nowy układ
 * z zatwierdzonego podglądu: kafle liczbowe zamiast listy liczników, karta
 * ostatniego meczu, pasek sezonu. Stare asercje pinowały TREŚĆ, której na
 * ekranie już nie ma („Wygenerowane raporty", kolumny „Nasza drużyna/Rywal").
 *
 * Sprawdzamy dalej to samo, co miały sprawdzać: że liczby są, że statusy są
 * po polsku i że braki danych są OPISANE, a nie puste.
 */
check('kafel raportów widoczny', str_contains($html, 'Raporty gotowe'));
check('status po polsku, nie po angielsku',
    str_contains($html, 'gotowe') && !str_contains($html, '>done<'));
check('kolumny są po polsku, nie Gospodarz/Gość',
    !str_contains($html, 'Gospodarz') && !str_contains($html, 'Gość'));
/*
 * KOTWICA ZMIENIONA W SESJI 3. Podpis „od sesji 3" zniknął, bo metryki
 * przyszły — kafle liczą się z tabeli `events`. Została sama zasada:
 * kafel BEZ WARTOŚCI ma kreskę i klasę `kpi--pusty`, nie zmyśloną liczbę.
 *
 * Ten render nie dostaje metryk w ogóle, więc wszystkie trzy mają być puste —
 * i to jest właściwy przypadek do sprawdzenia: brak danych, nie zero.
 */
check('kafel bez danych pokazuje kreskę, nie zmyśloną liczbę',
    substr_count($html, 'kpi--pusty') === 3 && str_contains($html, '—'),
    'brak danych ma być widoczny (CLAUDE.md §8)');

$pusty = View::render('dashboard', [
    'counters' => ['matches' => 0, 'matches_scope' => 'wszystkie sezony',
                   'reports' => 0, 'links' => 0, 'queued' => 0],
    'matches' => [], 'jobs' => [], 'notice' => null,
]);
check('pusty stan meczów jest opisowy',
    str_contains($pusty, 'Nie ma jeszcze żadnego rozegranego meczu'));
check('pusty stan „wymaga uwagi" jest opisowy', str_contains($pusty, 'Nic nie czeka'));
check('brak pustej tabeli przy zerze meczów', !str_contains($pusty, '<tbody>'));

// Świeże zadanie z tracebackiem: poprzednie zostało wyżej ponowione, co czyści error_text.
Db::run('INSERT INTO jobs (type,payload_json,status,attempts,exit_code,error_text,created_at)
         VALUES (?,?,?,?,?,?,?)',
    ['build_report', '{"match_id":3}', 'failed', 3, 4,
     "Traceback (most recent call last):\n  File \"cli.py\", line 12\nNotImplementedError: render <script>alert(1)</script>",
     Stats::now()]);
$jobHtml = View::render('job', [
    'job' => Db::one("SELECT * FROM jobs WHERE error_text LIKE '%Traceback%'"),
    'report' => null, 'notice' => null, 'error' => null,
]);
check('traceback w bloku <pre>', str_contains($jobHtml, '<pre class="pre">'));
check('HTML z tracebacku uciekniony',
    str_contains($jobHtml, '&lt;script&gt;') && !str_contains($jobHtml, '<script>alert'));
check('przycisk Ponów obecny dla failed', str_contains($jobHtml, 'Ponów'));

$running = View::render('job', [
    'job' => Db::one("SELECT * FROM jobs WHERE status='running'"),
    'report' => null, 'notice' => null, 'error' => null,
]);
check('brak przycisku Ponów przy zadaniu w toku', !str_contains($running, 'Ponów'));

// ---------------------------------------------------------------- motyw
echo "\n== motyw ==\n";
unset($_COOKIE['ca_theme']);
check('brak ciasteczka = brak wymuszenia motywu', View::theme() === null);
$_COOKIE['ca_theme'] = 'dark';
check('ciasteczko dark czytane', View::theme() === 'dark');
$_COOKIE['ca_theme'] = 'zielony';
check('nieznana wartość ignorowana', View::theme() === null);

$_COOKIE['ca_theme'] = 'dark';
$layout = View::render('layout', ['content' => 'x', 'title' => 't']);
check('data-theme w <html> przy pierwszym renderze', str_contains($layout, '<html lang="pl" data-theme="dark">'));
check('przełącznik prowadzi do motywu jasnego', str_contains($layout, 'value="light"'));
unset($_COOKIE['ca_theme']);
$layout2 = View::render('layout', ['content' => 'x']);
check('bez ciasteczka brak atrybutu data-theme', str_contains($layout2, '<html lang="pl">'));

// Po etapie 6 każda pozycja menu ma własną trasę — nie ma już pozycji nieaktywnych.
check('brak pozycji nieaktywnych — wszystkie mają trasy',
    substr_count($layout, 'is-disabled') === 0);
check('Notatki jako zwykły odnośnik', str_contains($layout, 'href="/notatki"'));
/*
 * SESJA 3,5: szyna z grupami zamiast płaskiej listy. Sprawdzamy WYRENDEROWANY
 * HTML, więc kotwicą jest etykieta z `pl.php`, nie klucz.
 *
 * „Kluby" zeszło do grupy ADMINISTRACJA i jest widoczne wyłącznie dla
 * administratora — ten render jest bez sesji, więc tej pozycji tu nie ma
 * i to jest poprawne. Widoczność dla administratora sprawdza `test_bramki_rol`.
 */
check('szyna ma grupy menu',
    str_contains($layout, 'Narzędzia') && str_contains($layout, 'grp__lab'),
    'grupy zamiast płaskiej listy pozycji');
check('pozycje bez widoku prowadzą do zapowiedzi, nie do 404',
    str_contains($layout, 'href="/zawodnicy"') && str_contains($layout, 'href="/kalendarz"'));
check('szyna niesie stan kolejki w stopce', str_contains($layout, 'W kolejce'));

// ---------------------------------------------------------------- CSS
echo "\n== motyw w CSS ==\n";
$css = (string) file_get_contents($root . '/app/public/assets/app.css');
$bezMotywu = preg_replace('/(:root[^{]*\{[^}]*\}|@media[^{]*\{\s*:root[^{]*\{[^}]*\}\s*\})/s', '', $css);
preg_match_all('/#[0-9A-Fa-f]{3,8}\b/', (string) $bezMotywu, $hex);
check('brak wartości szesnastkowych poza definicją motywu', $hex[0] === [],
    implode(', ', $hex[0]));

preg_match_all('/var\(--([a-z0-9-]+)\)/', $css, $uzyte);   // tylko bez fallbacku
preg_match_all('/^\s*--([a-z0-9-]+):/m', $css, $zdefiniowane);
$brakujace = array_diff(array_unique($uzyte[1]), array_unique($zdefiniowane[1]));
check('każda użyta zmienna jest zdefiniowana', $brakujace === [], implode(', ', $brakujace));

$jasny = [];
preg_match('/:root \{(.*?)\}/s', $css, $mm);
preg_match_all('/--([a-z0-9-]+):/', $mm[1], $jasny);
preg_match('/:root\[data-theme="dark"\] \{(.*?)\}/s', $css, $dd);
preg_match_all('/--([a-z0-9-]+):/', $dd[1], $ciemny);
$tylkoJasny = array_diff($jasny[1], $ciemny[1]);
/*
 * Zmienne, które CELOWO nie mają wariantu ciemnego (sesja 3,5):
 *
 * - `promien`, `r`  — geometria, nie kolor;
 * - `bg`…`shadow`   — ALIASY na nazwy z szablonu v21 (`--bg: var(--tlo)`).
 *   Wskazują na zmienne, które wariant ciemny nadpisuje, więc powielanie ich
 *   w każdym bloku dawałoby dwa źródła prawdy dla jednej barwy;
 * - `szyna-akt*`    — SZYNA JEST ZAWSZE CIEMNA, także w motywie jasnym, więc
 *   barwa pozycji aktywnej jest z założenia ta sama w obu motywach.
 */
$bezWariantu = ['promien', 'r', 'bg', 'panel', 'panel2', 'line',
                'ink', 'ink2', 'ink3', 'acc', 'acc-ink', 'shadow',
                'szyna-akt', 'szyna-akt-tekst'];
check('motyw ciemny nadpisuje komplet kolorów',
    array_diff($tylkoJasny, $bezWariantu) === [],
    'brakuje: ' . implode(', ', array_diff($tylkoJasny, $bezWariantu)));

@unlink($db);
$out = ob_get_clean(); echo $out;
echo "\n=== OK: $ok, BŁĘDÓW: $fail ===\n";
exit($fail === 0 ? 0 : 1);
