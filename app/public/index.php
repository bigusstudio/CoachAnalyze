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
    case $path === '/mecze' && $method === 'GET':
        View::page('soon', [
            'title'   => View::t('nav.matches'),
            'active'  => 'matches',
            'heading' => View::t('nav.matches'),
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
