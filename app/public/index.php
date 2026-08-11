<?php
declare(strict_types=1);

/**
 * Jedyny plik wystawiony przez nginx. Reszta aplikacji leży poza katalogiem publicznym.
 *
 * Trasy publiczne:  /r/{club_key}/{token}  — raport read-only, bez sesji (Etap 7)
 * Trasy panelu:     wszystkie pozostałe    — wymagają zalogowania
 *
 * UWAGA: przy niepoprawnym club_key i przy niepoprawnym tokenie odpowiedź musi być
 * IDENTYCZNA (404, ten sam czas odpowiedzi). Inaczej klucz klubu da się wysondować.
 */

use CoachAnalyze\Audit;
use CoachAnalyze\Engine;
use CoachAnalyze\Imports;
use CoachAnalyze\Storage;
use CoachAnalyze\Upload;
use CoachAnalyze\Auth;
use CoachAnalyze\Jobs;
use CoachAnalyze\Session;
use CoachAnalyze\Stats;
use CoachAnalyze\View;

require dirname(__DIR__) . '/src/bootstrap.php';

$path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- trasy bez sesji ------------------------------------------------------
if ($path === '/login') {
    $method === 'POST' ? handleLogin() : showLogin();
    exit;
}

// --- od tego miejsca wszystko wymaga zalogowania --------------------------
$user = Auth::requireLogin();

switch (true) {
    case $path === '/' && $method === 'GET':
        View::page('dashboard', [
            'title'    => View::t('dash.title'),
            'active'   => 'dashboard',
            'counters' => Stats::counters(),
            'matches'  => Stats::recentMatches(5),
            'jobs'     => Stats::jobsNeedingAttention(),
            'notice'   => Session::flash('notice'),
        ]);
        break;

    case $path === '/import' && $method === 'GET':
        View::page('import_form', [
            'title'        => View::t('import.title'),
            'active'       => 'import',
            'error'        => Session::flash('error'),
            'storageReady' => Storage::writable(),
        ]);
        break;

    case $path === '/import' && $method === 'POST':
        requireCsrf();
        handleImport((int) $user['id']);
        break;

    case preg_match('#^/import/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showCoverage((int) $m[1]);
        break;

    case preg_match('#^/import/(\d+)/generuj$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        queueBuild((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/zadania/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showJob((int) $m[1]);
        break;

    case preg_match('#^/zadania/(\d+)/ponow$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        $id = (int) $m[1];
        // Jedno wywołanie — `retry()` zmienia stan, więc nie wolno go użyć
        // powtórnie tylko po to, żeby wybrać komunikat.
        $done = Jobs::retry($id);
        Session::flash(
            $done ? 'notice' : 'error',
            View::t($done ? 'job.retry.done' : 'job.retry.refused')
        );
        redirect('/zadania/' . $id);
        break;

    case $path === '/motyw' && $method === 'POST':
        requireCsrf();
        setTheme((string) ($_POST['theme'] ?? 'light'));
        redirect(safeReturn($_POST['powrot'] ?? '/'));
        break;

    case $path === '/logout' && $method === 'POST':
        // Wylogowanie wyłącznie POST-em z tokenem: pod adresem GET wystarczyłby
        // obrazek na obcej stronie, żeby wylogować operatora.
        requireCsrf();
        (new Auth())->logout();
        Session::flash('notice', View::t('login.logged_out'));
        redirect('/login');
        break;

    // Etapy 4b/4c/5 — zapowiedź zamiast 404, żeby nawigacja nie prowadziła w pustkę.
    // Kluby i Notatki nie mają jeszcze pozycji w menu, ale adres wpisany z ręki
    // albo z zakładki ma powiedzieć „wkrótce", a nie „nie ma takiej strony".
    case in_array($path, ['/mecze', '/kluby', '/notatki'], true) && $method === 'GET':
        $nazwy = ['/mecze' => 'nav.matches', '/kluby' => 'nav.clubs', '/notatki' => 'nav.notes'];
        View::page('soon', [
            'title'   => View::t($nazwy[$path]),
            'active'  => $path === '/mecze' ? 'matches' : '',
            'heading' => View::t($nazwy[$path]),
        ]);
        break;

    case preg_match('#^/raport/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        serveReport((int) $m[1]);
        break;

    default:
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('common.not_found'),
        ]);
}

// --------------------------------------------------------------------------

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

/**
 * Adres powrotu przyjmujemy tylko jako ścieżkę względną tej aplikacji.
 * Bez tego `powrot=//obcy.host` zamieniłby przełącznik motywu w otwarte
 * przekierowanie na dowolną stronę.
 */
function safeReturn(mixed $candidate): string
{
    $value = is_string($candidate) ? $candidate : '/';
    if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//')) {
        return '/';
    }
    return $value;
}

function setTheme(string $theme): void
{
    $theme = in_array($theme, ['light', 'dark'], true) ? $theme : 'light';
    setcookie('ca_theme', $theme, [
        'expires'  => time() + 31536000,   // rok
        'path'     => '/',
        'secure'   => str_starts_with((string) \CoachAnalyze\Config::get('APP_URL', ''), 'https://'),
        // Motyw to preferencja wyglądu, nie poświadczenie — JS może go czytać.
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function requireCsrf(): void
{
    if (Session::checkCsrf($_POST['csrf'] ?? null)) {
        return;
    }
    Audit::log(Audit::CSRF_FAIL, Session::userId(), 'session');
    http_response_code(400);
    View::page('soon', [
        'title'   => View::t('common.error'),
        'heading' => View::t('common.error'),
        'body'    => View::t('login.err.csrf'),
    ]);
    exit;
}

function showJob(int $id): void
{
    $job = Jobs::find($id);
    if ($job === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('job.not_found'),
        ]);
        return;
    }

    View::page('job', [
        'title'  => View::t('job.title', $id),
        'job'    => $job,
        'report' => Jobs::reportFor($job),
        'notice' => Session::flash('notice'),
        'error'  => Session::flash('error'),
        // Odświeżamy tylko wtedy, gdy jest na co czekać. Strona zakończonego
        // zadania odświeżająca się w kółko utrudniałaby czytanie tracebacku.
        'refresh' => in_array($job['status'], ['queued', 'running'], true) ? 4 : null,
    ]);
}

/**
 * Podgląd wygenerowanego raportu dla zalogowanego operatora.
 *
 * Plik NIGDY nie jest serwowany bezpośrednio przez serwer WWW (CLAUDE.md §5) —
 * leży poza katalogiem publicznym i przechodzi przez PHP. Ścieżkę z bazy
 * sprawdzamy względem STORAGE_PATH: bez tego wpis `../../` w bazie pozwoliłby
 * odczytać dowolny plik dostępny dla procesu PHP.
 *
 * Publiczny adres /r/{club_key}/{token} to osobna sprawa — Etap 7.
 */
function serveReport(int $id): void
{
    $report = \CoachAnalyze\Db::one('SELECT html_path FROM reports WHERE id = :id', ['id' => $id]);
    $storage = \CoachAnalyze\Config::get('STORAGE_PATH');

    $file = null;
    if ($report !== null && $storage !== null) {
        $real = realpath((string) $report['html_path']);
        $root = realpath($storage);
        if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            $file = $real;
        }
    }

    if ($file === null || !is_readable($file)) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('report.missing'),
        ]);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, no-store');
    readfile($file);
}

/**
 * Przyjęcie eksportu i NATYCHMIASTOWY raport pokrycia.
 *
 * `inspect` wołamy synchronicznie: to samo parsowanie, ułamek sekundy, a operator
 * czeka na ekranie. Render jest osobnym krokiem i osobną decyzją — dlatego tutaj
 * nie powstaje żadne zadanie w kolejce.
 */
function handleImport(int $userId): void
{
    $csv = Upload::accept($_FILES['csv'] ?? null, 'csv', true);
    if (!$csv['ok']) {
        Session::flash('error', View::t($csv['error']));
        redirect('/import');
    }

    $json = Upload::accept($_FILES['json'] ?? null, 'json', false);
    if (!$json['ok']) {
        // CSV już leży w storage — sprzątamy, żeby nieudany import nie zostawiał śmieci.
        @unlink((string) $csv['path']);
        Session::flash('error', View::t($json['error']));
        redirect('/import');
    }

    $result = Engine::inspect((string) $csv['path'], $json['path'] ?? null);

    if (!$result['ok']) {
        @unlink((string) $csv['path']);
        if (!empty($json['path'])) {
            @unlink((string) $json['path']);
        }
        // Traceback silnika idzie do logu, nigdy do przeglądarki (CLAUDE.md §5).
        if ($result['stderr'] !== '') {
            error_log('inspect stderr: ' . $result['stderr']);
        }
        $powod = (string) ($result['meta']['msg'] ?? View::t('common.error'));
        Session::flash('error', View::t('import.err.engine', $powod));
        redirect('/import');
    }

    $importId = Imports::create(
        $userId,
        (string) $csv['path'],
        $json['path'] ?? null,
        (string) $csv['sha256'],
        (array) $result['meta']
    );

    redirect('/import/' . $importId);
}

function showCoverage(int $importId): void
{
    $import = Imports::find($importId);
    if ($import === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('common.not_found'),
        ]);
        return;
    }

    $report = Imports::report($import);

    // Sekcje niedostępne nie mieszczą się w schemacie `imports`, więc odtwarzamy
    // je z ostatniego przebiegu silnika. Gdy ich nie ma — pytamy silnik jeszcze raz.
    $meta = Engine::inspect((string) $import['csv_path'], $import['json_path'] ?: null);

    View::page('import_coverage', [
        'title'               => View::t('coverage.title'),
        'active'              => 'import',
        'import'              => $import,
        'coverage'            => $report['coverage'] ?: (array) ($meta['meta']['coverage'] ?? []),
        'warnings'            => $report['warnings'] ?: (array) ($meta['meta']['warnings'] ?? []),
        'sectionsUnavailable' => (array) ($meta['meta']['sections_unavailable'] ?? []),
        'sectionsAvailable'   => (array) ($meta['meta']['sections_available'] ?? []),
        'report'              => Imports::latestReport((int) $import['match_id']),
        'notice'              => Session::flash('notice'),
    ]);
}

/**
 * Zatwierdzenie: zadanie do kolejki i natychmiastowy start procesu w tle.
 * Ten sam import można wygenerować ponownie — bez wgrywania pliku od nowa.
 */
function queueBuild(int $importId, int $userId): void
{
    $import = Imports::find($importId);
    if ($import === null) {
        http_response_code(404);
        redirect('/');
    }

    $jobId = Imports::queueBuild($importId, $userId);

    if (!Engine::launchWorker($jobId)) {
        // Zadanie zostaje w kolejce — podniesie je cron-siatka. Operator ma
        // o tym wiedzieć, zamiast patrzeć na status, który się nie zmienia.
        Session::flash('error', View::t('job.launch_failed'));
    } else {
        Session::flash('notice', View::t('coverage.queued'));
    }

    redirect('/zadania/' . $jobId);
}

function showLogin(): void
{
    if (Auth::isLoggedIn()) {
        redirect('/');
    }
    View::page('login', [
        'title'  => View::t('login.title'),
        'chrome' => false,
        'csrf'   => Session::csrfToken(),
        'notice' => Session::flash('notice'),
        'error'  => Session::flash('error'),
        'email'  => '',
    ]);
}

function handleLogin(): void
{
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!Session::checkCsrf($_POST['csrf'] ?? null)) {
        Audit::log(Audit::CSRF_FAIL, null, 'user');
        renderLogin($email, View::t('login.err.csrf'));
        return;
    }

    if ($email === '' || $password === '') {
        renderLogin($email, View::t('login.err.empty'));
        return;
    }

    $result = (new Auth())->attempt($email, $password);
    if ($result['ok']) {
        redirect('/');
    }

    renderLogin($email, match ($result['error']) {
        'rate_limited' => View::t(
            'login.err.rate_limited',
            View::humanSeconds((int) ($result['retry_after'] ?? 900))
        ),
        'login_unavailable' => View::t('login.err.login_unavailable'),
        default             => View::t('login.err.invalid_credentials'),
    });
}

function renderLogin(string $email, string $error): void
{
    http_response_code(401);
    View::page('login', [
        'title'  => View::t('login.title'),
        'chrome' => false,
        'csrf'   => Session::csrfToken(),
        'error'  => $error,
        'email'  => $email,
    ]);
}
