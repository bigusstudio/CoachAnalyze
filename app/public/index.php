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
use CoachAnalyze\Clubs;
use CoachAnalyze\Crest;
use CoachAnalyze\Engine;
use CoachAnalyze\Imports;
use CoachAnalyze\Jobs;
use CoachAnalyze\Matches;
use CoachAnalyze\Notes;
use CoachAnalyze\Remember;
use CoachAnalyze\Seasons;
use CoachAnalyze\Session;
use CoachAnalyze\Share;
use CoachAnalyze\Stats;
use CoachAnalyze\Storage;
use CoachAnalyze\Upload;
use CoachAnalyze\View;

/**
 * CA_ROOT — katalog, w którym leży `app/`. WSZYSTKIE ścieżki liczymy od niego.
 *
 * POWÓD ISTNIENIA TEJ STAŁEJ: układ katalogów w repozytorium NIE jest układem
 * produkcyjnym i ten plik jest jedynym, który zmienia przez to swoje położenie.
 * `deploy.sh` robi dwa przejścia rsync — `app/` trafia do podkatalogu domeny,
 * a zawartość `app/public/` ląduje piętro wyżej, w katalogu domeny:
 *
 *   repozytorium                    produkcja
 *   app/public/index.php    ->      {domena}/index.php
 *   app/src/bootstrap.php   ->      {domena}/app/src/bootstrap.php
 *
 * Dlatego `dirname(__DIR__)` daje w produkcji `~/public_html`, czyli katalog
 * SPOZA `open_basedir`, i całość kończy się „Operation not permitted".
 * Ten błąd wrócił dwa razy, więc nie liczymy już skoków w górę na sztywno:
 * szukamy katalogu, w którym faktycznie leży `app/src/bootstrap.php`.
 *
 * W produkcji pętla kończy się na pierwszym kroku i CA_ROOT === __DIR__.
 */
$caDir = __DIR__;
$caRoot = null;
for ($i = 0; $i < 5; $i++) {
    if (is_file($caDir . '/app/src/bootstrap.php')) {
        $caRoot = $caDir;
        break;
    }
    $parent = dirname($caDir);
    if ($parent === $caDir) {
        break;   // korzeń systemu plików
    }
    $caDir = $parent;
}

if ($caRoot === null) {
    // Bez kodu aplikacji nie ma czego uruchomić. Mówimy o tym wprost w logu,
    // a użytkownikowi pokazujemy zdanie bez ścieżek serwera.
    error_log('CoachAnalyze: nie znaleziono app/src/bootstrap.php startując z ' . __DIR__);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Aplikacja jest nieprawidłowo wdrożona.';
    exit(1);
}

define('CA_ROOT', $caRoot);

require CA_ROOT . '/app/src/bootstrap.php';

$path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// HEAD to GET bez treści odpowiedzi. Bez tego każde HEAD wpadało do gałęzi
// domyślnej i dostawało 404 — mylące przy diagnostyce (`curl -I`) i błędne
// dla pośredników, które sprawdzają zasób przed pobraniem.
if ($method === 'HEAD') {
    $method = 'GET';
}

// --- trasy bez sesji ------------------------------------------------------
if ($path === '/login') {
    $method === 'POST' ? handleLogin() : showLogin();
    exit;
}

// Raport publiczny. BEZ SESJI — nie dotykamy tu `Session`, więc przeglądarka
// czytelnika nie dostaje ciasteczka panelu. Jedyną ochroną jest token w adresie.
if (preg_match('#^/r/([^/]+)/([^/]+)$#', $path, $m) === 1 && $method === 'GET') {
    servePublicReport(rawurldecode($m[1]), rawurldecode($m[2]));
    exit;
}

// Zalogowanie ciasteczkiem trwałym, zanim zażądamy sesji. Token jest zużywany
// i wymieniany na nowy przy KAŻDYM użyciu — patrz Remember::consume().
if (!Auth::isLoggedIn() && isset($_COOKIE[Remember::COOKIE])) {
    $wynik = (new Auth())->loginFromCookie((string) $_COOKIE[Remember::COOKIE]);
    if ($wynik['ok']) {
        setRememberCookie((string) $wynik['token']);
    } else {
        // Token nieznany, zużyty albo wygasły — ciasteczko do kosza, żeby nie
        // wracało z każdym żądaniem.
        clearRememberCookie();
    }
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
            'alerts'   => \CoachAnalyze\Alerts::all(),
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
        clearRememberCookie();
        Session::flash('notice', View::t('login.logged_out'));
        redirect('/login');
        break;

    // ------------------------------------------------- konto i urządzenia
    case $path === '/konto' && $method === 'GET':
        View::page('account', [
            'title'    => View::t('account.title'),
            'active'   => 'account',
            'user'     => $user,
            'devices'  => Remember::devices((int) $user['id']),
            'fullAuth' => Session::hasFullAccess(),
            'notice'   => Session::flash('notice'),
            'error'    => Session::flash('error'),
        ]);
        break;

    case preg_match('#^/konto/urzadzenie/(\d+)/wyloguj$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        Remember::forget((int) $m[1], (int) $user['id']);
        Session::flash('notice', View::t('device.forgotten'));
        redirect('/konto');
        break;

    case $path === '/konto/wyloguj-wszedzie' && $method === 'POST':
        requireCsrf();
        // Dostępne także z sesji `remembered` — to jest droga odzyskania kontroli
        // po zgubieniu urządzenia i blokowanie jej hasłem działałoby przeciw celowi.
        $ile = Remember::forgetAll((int) $user['id'], 'wyloguj wszędzie');
        clearRememberCookie();
        Session::flash('notice', View::t('device.forgot_all', $ile));
        redirect('/konto');
        break;

    case $path === '/konto/haslo' && $method === 'POST':
        requireCsrf();
        $wynik = (new Auth())->changePassword(
            (int) $user['id'],
            (string) ($_POST['obecne'] ?? ''),
            (string) ($_POST['nowe'] ?? '')
        );
        if ($wynik['ok']) {
            clearRememberCookie();
            Session::flash('notice', View::t('account.password_changed'));
        } else {
            Session::flash('error', View::t($wynik['error']));
        }
        redirect('/konto');
        break;

    // ---------------------------------------------------------------- kluby
    case $path === '/kluby' && $method === 'GET':
        View::page('clubs_list', [
            'title'  => View::t('club.list'),
            'active' => 'clubs',
            'clubs'  => Clubs::all(),
            'notice' => Session::flash('notice'),
            'error'  => Session::flash('error'),
        ]);
        break;

    case $path === '/kluby/nowy' && $method === 'GET':
        View::page('club_form', [
            'title'         => View::t('club.new'),
            'active'        => 'clubs',
            'club'          => null,
            // Nazwa proponowana z danych eksportu — operator potwierdza lub poprawia.
            'suggestedName' => isset($_GET['nazwa']) ? trim((string) $_GET['nazwa']) : null,
            'backTo'        => safeReturn($_GET['powrot'] ?? null),
            'error'         => Session::flash('error'),
        ]);
        break;

    case $path === '/kluby' && $method === 'POST':
        requireCsrf();
        saveClub(null, (int) $user['id']);
        break;

    case preg_match('#^/kluby/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showClub((int) $m[1]);
        break;

    case preg_match('#^/kluby/(\d+)$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        saveClub((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/kluby/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        deleteClub((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/herb/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        serveCrest((int) $m[1]);
        break;

    // ------------------------------------------------------- biblioteka meczów
    case $path === '/mecze' && $method === 'GET':
        $filtr = [
            'klub'   => isset($_GET['klub'])  && $_GET['klub'] !== ''  ? (int) $_GET['klub'] : null,
            'sezon'  => isset($_GET['sezon']) && $_GET['sezon'] !== '' ? (int) $_GET['sezon'] : null,
            'sort'   => Matches::normalizeSort($_GET['sort'] ?? null),
            'strona' => max(1, (int) ($_GET['strona'] ?? 1)),
        ];
        View::page('matches_list', [
            'title'   => View::t('matches.title'),
            'active'  => 'matches',
            'wynik'   => Matches::search([
                'club'   => $filtr['klub'],
                'season' => $filtr['sezon'],
                'sort'   => $filtr['sort'],
                'page'   => $filtr['strona'],
            ]),
            'clubs'   => Clubs::all(),
            'seasons' => Seasons::all(),
            'filtr'   => $filtr,
            'notice'  => Session::flash('notice'),
        ]);
        break;

    // ---------------------------------------------------------------- sezony
    case $path === '/sezony' && $method === 'GET':
        View::page('seasons_list', [
            'title'      => View::t('season.list'),
            'active'     => 'seasons',
            'seasons'    => Seasons::all(),
            'propozycja' => Seasons::boundsFor(date('Y-m-d')),
            'notice'     => Session::flash('notice'),
            'error'      => Session::flash('error'),
        ]);
        break;

    case $path === '/sezony' && $method === 'POST':
        requireCsrf();
        saveSeason((int) $user['id']);
        break;

    case preg_match('#^/sezony/(\d+)/biezacy$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        Seasons::markCurrent((int) $m[1], (int) $user['id']);
        Session::flash('notice', View::t('season.current_set'));
        redirect('/sezony');
        break;

    case preg_match('#^/sezony/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        $wynik = Seasons::delete((int) $m[1], (int) $user['id']);
        Session::flash(
            $wynik['ok'] ? 'notice' : 'error',
            View::t($wynik['ok'] ? 'season.deleted' : $wynik['error'])
        );
        redirect('/sezony');
        break;


    case preg_match('#^/mecze/(\\d+)/notatki$#', $path, $m) === 1 && $method === 'GET':
        showMatchNotes((int) $m[1]);
        break;

    // -------------------------------------------------------- notatnik (Etap 6)
    case $path === '/notatki' && $method === 'GET':
        $filtr = [
            'q'      => trim((string) ($_GET['q'] ?? '')),
            'poziom' => (string) ($_GET['poziom'] ?? ''),
            'tag'    => trim((string) ($_GET['tag'] ?? '')),
        ];
        View::page('notes_list', [
            'title'   => View::t('note.title'),
            'active'  => 'notes',
            'notes'   => Notes::search($filtr['q'], $filtr['poziom'] ?: null, $filtr['tag'] ?: null),
            'tags'    => Notes::tagCloud(),
            'filtr'   => $filtr,
            'matches' => Stats::recentMatches(50),
            'clubs'   => Clubs::all(),
            'notice'  => Session::flash('notice'),
            'error'   => Session::flash('error'),
        ]);
        break;

    case $path === '/notatki' && $method === 'POST':
        requireCsrf();
        saveNote((int) $user['id']);
        break;

    case preg_match('#^/notatki/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        Notes::delete((int) $m[1], (int) $user['id']);
        Session::flash('notice', View::t('note.deleted'));
        redirect('/notatki');
        break;

    case preg_match('#^/raport/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        serveReport((int) $m[1]);
        break;

    // ------------------------------------------------------- publikacja (Etap 7)
    case preg_match('#^/raport/(\d+)/udostepnij$#', $path, $m) === 1 && $method === 'GET':
        showShare((int) $m[1]);
        break;

    case preg_match('#^/raport/(\d+)/udostepnij$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        createShare((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/link/(\d+)/odwolaj$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        $id = (int) $m[1];
        $link = \CoachAnalyze\Db::one('SELECT report_id FROM share_links WHERE id = :id', ['id' => $id]);
        Session::flash(
            Share::revoke($id, (int) $user['id']) ? 'notice' : 'error',
            View::t('share.revoked')
        );
        redirect($link !== null ? '/raport/' . (int) $link['report_id'] . '/udostepnij' : '/linki');
        break;

    case $path === '/linki' && $method === 'GET':
        View::page('share_active', [
            'title'  => View::t('share.active'),
            'active' => 'links',
            'links'  => Share::active(),
            'appUrl' => \CoachAnalyze\Config::get('APP_URL', ''),
            'notice' => Session::flash('notice'),
        ]);
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

/**
 * Ciasteczko trwałego logowania.
 *
 * HttpOnly — JavaScript go nie odczyta. Secure — nie wyjdzie po HTTP.
 * SameSite=Lax — nie poleci przy żądaniu z obcej strony, ale wejście z linku
 * nadal działa. Te trzy atrybuty to całość ochrony ciasteczka, które żyje 30 dni.
 */
function setRememberCookie(string $token): void
{
    setcookie(Remember::COOKIE, $token, [
        'expires'  => time() + Remember::DAYS * 86400,
        'path'     => '/',
        'secure'   => str_starts_with((string) \CoachAnalyze\Config::get('APP_URL', ''), 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearRememberCookie(): void
{
    setcookie(Remember::COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => str_starts_with((string) \CoachAnalyze\Config::get('APP_URL', ''), 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
        // zadania odświeżająca się w kółko utrudniałaby czytanie komunikatu o błędzie.
        //
        // Odstępy dobrane pod cron, nie pod wrażenie płynności: zadanie w kolejce
        // ruszy najwcześniej przy najbliższym przejściu workera, czyli w ciągu
        // minuty — odpytywanie co kilka sekund to same puste żądania.
        // Gdy silnik już pracuje, sprawdzamy częściej, bo koniec może przyjść lada chwila.
        'refresh' => match ((string) $job['status']) {
            'queued'  => 20,
            'running' => 6,
            default   => null,
        },
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

    /*
     * ŻADNEGO URUCHAMIANIA SILNIKA W TEJ ŚCIEŻCE.
     *
     * PHP-FPM na lh.pl ma `disable_functions` obejmujące proc_open, exec,
     * shell_exec i resztę — z przeglądarki nie da się odpalić procesu.
     * Wcześniej stało tu synchroniczne `Engine::inspect()`, które wywalało się
     * wyjątkiem PRZED zapisem zadania, przez co tabela `jobs` zostawała pusta
     * i nie było nawet czego ponowić.
     *
     * Teraz zapisujemy import bez pokrycia i wstawiamy zadanie `inspect`
     * do kolejki. Podniesie je cron (co minutę, app/bin/run_job.php).
     */
    $importId = Imports::create(
        $userId,
        (string) $csv['path'],
        $json['path'] ?? null,
        (string) $csv['sha256']
    );

    $jobId = Imports::queueInspect($importId, $userId);
    redirect('/zadania/' . $jobId);
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

    /*
     * Wszystko poniżej pochodzi z ARTEFAKTÓW zapisanych przez proces roboczy.
     * Warstwa żądań nie uruchamia silnika (disable_functions), więc gdy pokrycia
     * jeszcze nie ma, pokazujemy stan zadania zamiast pustych liczb.
     */
    if ($report['coverage'] === []) {
        $job = Imports::latestJob($importId, 'inspect');
        redirect($job !== null ? '/zadania/' . (int) $job['id'] : '/import');
    }

    View::page('import_coverage', [
        'title'               => View::t('coverage.title'),
        'active'              => 'import',
        'import'              => $import,
        'coverage'            => $report['coverage'],
        'warnings'            => $report['warnings'],
        'sectionsUnavailable' => $report['sections_unavailable'],
        'sectionsAvailable'   => $report['sections_available'],
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

    // Zadanie czeka na crona — z przeglądarki nie wolno uruchomić procesu.
    Session::flash('notice', View::t('coverage.queued'));
    redirect('/zadania/' . $jobId);
}


function showMatchNotes(int $matchId): void
{
    $match = Matches::find($matchId);
    if ($match === null) {
        http_response_code(404);
        View::page('soon', ['title' => View::t('common.not_found'), 'heading' => View::t('common.not_found')]);
        return;
    }

    $ogolne = [];
    $poZdarzeniu = [];
    foreach (Notes::forMatch($matchId) as $n) {
        if ($n['scope'] === 'event' && !empty($n['event_ref'])) {
            $poZdarzeniu[(string) $n['event_ref']][] = $n;
        } else {
            $ogolne[] = $n;
        }
    }

    View::page('match_notes', [
        'title'       => View::t('note.match_title'),
        'active'      => 'notes',
        'match'       => $match,
        'ogolne'      => $ogolne,
        'poZdarzeniu' => $poZdarzeniu,
        'report'      => Imports::latestReport($matchId),
    ]);
}

// --------------------------------------------------------------- notatnik

function saveNote(int $userId): void
{
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        Session::flash('error', View::t('note.err.body'));
        redirect('/notatki');
    }

    $scope = (string) ($_POST['scope'] ?? 'match');
    $eventRef = trim((string) ($_POST['event_ref'] ?? ''));

    // Notatka „przy zdarzeniu" bez wskazania zdarzenia byłaby notatką do meczu
    // udającą coś więcej — i nigdy nie pokazałaby się w kontekście raportu.
    if ($scope === 'event' && ($eventRef === '' || empty($_POST['match_id']))) {
        Session::flash('error', View::t('note.err.event'));
        redirect('/notatki');
    }

    $kategoria = (string) ($_POST['kategoria'] ?? '');
    $tagi = (string) ($_POST['tags'] ?? '');
    if (in_array($kategoria, Notes::CATEGORIES, true)) {
        // Kategoria to po prostu tag z zamkniętej listy — jedno pole wyszukiwania
        // zamiast dwóch mechanizmów robiących to samo.
        $tagi = $tagi === '' ? $kategoria : $kategoria . ',' . $tagi;
    }

    Notes::create($userId, [
        'scope'     => $scope,
        'match_id'  => !empty($_POST['match_id']) ? (int) $_POST['match_id'] : null,
        'club_id'   => !empty($_POST['club_id']) ? (int) $_POST['club_id'] : null,
        'event_ref' => $eventRef !== '' ? $eventRef : null,
        'title'     => trim((string) ($_POST['title'] ?? '')),
        'body'      => $body,
        'tags'      => $tagi,
    ]);

    Session::flash('notice', View::t('note.created'));
    redirect('/notatki');
}

// ------------------------------------------------------ publikacja (panel)

function showShare(int $reportId): void
{
    $report = \CoachAnalyze\Db::one(
        'SELECT id, match_id, engine_version, generated_at FROM reports WHERE id = :id',
        ['id' => $reportId]
    );
    if ($report === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('report.missing'),
        ]);
        return;
    }

    View::page('share_list', [
        'title'  => View::t('share.title'),
        'active' => 'links',
        'report' => $report,
        'links'  => Share::forReport($reportId),
        'appUrl' => \CoachAnalyze\Config::get('APP_URL', ''),
        'notice' => Session::flash('notice'),
        'error'  => Session::flash('error'),
    ]);
}

function createShare(int $reportId, int $userId): void
{
    $clubId = Share::clubForReport($reportId);

    if ($clubId === null) {
        // Klucz klubu stoi w adresie. Bez przypisanego klubu nie ma czego wstawić,
        // a podstawienie przypadkowego dałoby link prowadzący do cudzego klucza.
        Session::flash('error', View::t('share.err.no_club'));
        redirect('/raport/' . $reportId . '/udostepnij');
    }

    $expires = trim((string) ($_POST['expires_at'] ?? ''));
    if ($expires !== '' && $expires < date('Y-m-d')) {
        Session::flash('error', View::t('share.err.past'));
        redirect('/raport/' . $reportId . '/udostepnij');
    }

    Share::create($reportId, $clubId, $expires !== '' ? $expires . ' 23:59:59' : null, $userId);
    Session::flash('notice', View::t('share.created'));
    redirect('/raport/' . $reportId . '/udostepnij');
}

// ------------------------------------------------------- raport publiczny

/**
 * Serwowanie raportu spod /r/{club_key}/{token}.
 *
 * ODPOWIEDŹ MUSI BYĆ IDENTYCZNA dla złego klucza klubu i złego tokenu —
 * to samo 404, ta sama treść, porównywalny czas. Każda różnica pozwala
 * sondować, które klucze klubów istnieją.
 *
 * Dlatego:
 *  - jedno zapytanie z obydwoma warunkami (Share::resolve), a nie dwa po kolei,
 *  - link odwołany i wygasły dają to samo 404 co nieistniejący,
 *  - wszystkie nieudane ścieżki schodzą się w jednym miejscu i są wyrównywane
 *    do wspólnego minimalnego czasu odpowiedzi.
 */
function servePublicReport(string $clubKey, string $token): void
{
    $start = microtime(true);

    $link = Share::resolve($clubKey, $token);

    if (!Share::isUsable($link)) {
        publicNotFound($start);
    }

    $path = (string) $link['html_path'];
    if (!Storage::isInside($path) || !is_readable($path)) {
        // Wpis w bazie jest, pliku nie ma. Dla czytelnika to nadal zwykłe 404;
        // powód idzie do logu, bo to awaria po naszej stronie.
        error_log('share: brak pliku raportu ' . $path . ' (link ' . $link['id'] . ')');
        publicNotFound($start);
    }

    Share::registerView((int) $link['id']);

    header('Content-Type: text/html; charset=utf-8');
    // Raport ma nie trafić do wyszukiwarek ani do archiwów.
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    // Adres NIESIE token — nagłówek Referer przekazałby go każdej stronie,
    // w którą czytelnik kliknie z raportu.
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    readfile($path);
    exit;
}

/**
 * Jedyna odpowiedź na każdy nieudany dostęp do raportu publicznego.
 *
 * Czas odpowiedzi wyrównujemy do wspólnego progu. Bez tego „zły klucz klubu"
 * (odpada w zapytaniu) i „zły token" (odpada tak samo) mogłyby różnić się
 * pomiarem na tyle, żeby dało się je odróżnić przy tysiącu prób.
 */
function publicNotFound(float $start): never
{
    $minimum = 0.15;   // sekundy
    $elapsed = microtime(true) - $start;
    if ($elapsed < $minimum) {
        usleep((int) (($minimum - $elapsed) * 1_000_000));
    }

    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    echo View::t('share.not_found');
    exit;
}

// ----------------------------------------------------------------- sezony

function saveSeason(int $userId): void
{
    $label = trim((string) ($_POST['label'] ?? ''));
    $from  = trim((string) ($_POST['date_from'] ?? ''));
    $to    = trim((string) ($_POST['date_to'] ?? ''));

    if ($label === '' || $from === '' || $to === '') {
        Session::flash('error', View::t('season.err.fields'));
        redirect('/sezony');
    }

    if ($from >= $to) {
        Session::flash('error', View::t('season.err.range'));
        redirect('/sezony');
    }

    Seasons::create($userId, [
        'label'      => $label,
        'date_from'  => $from,
        'date_to'    => $to,
        'is_current' => !empty($_POST['is_current']),
    ]);
    Session::flash('notice', View::t('season.created'));
    redirect('/sezony');
}

// ------------------------------------------------------------------ kluby

function showClub(int $id): void
{
    $club = Clubs::find($id);
    if ($club === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('club.not_found'),
        ]);
        return;
    }

    View::page('club_form', [
        'title'         => View::t('club.edit_title', (string) $club['name']),
        'active'        => 'clubs',
        'club'          => $club,
        'suggestedName' => null,
        'notice'        => Session::flash('notice'),
        'error'         => Session::flash('error'),
    ]);
}

/**
 * Zapis klubu. `club_key` powstaje wyłącznie przy tworzeniu i nigdy nie jest
 * tu ruszany — stoi w publicznych adresach raportów.
 */
function saveClub(?int $id, int $userId): void
{
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        Session::flash('error', View::t('club.err.name'));
        redirect($id === null ? '/kluby/nowy' : '/kluby/' . $id);
    }

    $primary   = View::color($_POST['color_primary'] ?? null, '#E8722C');
    $secondary = View::color($_POST['color_secondary'] ?? null, '#2C6FE8');

    /*
     * Korekta kontrastu NIE jest już liczona przy zapisie.
     *
     * Wymagała uruchomienia Pythona, a warstwa żądań nie może uruchamiać
     * procesów (disable_functions). Zapisujemy barwę wybraną przez operatora,
     * a korektę stosuje silnik przy renderze — konfiguracja niesie
     * `options.contrast_fix`, więc arytmetyka koloru zostaje po jednej stronie
     * i nadal nie jest przepisywana do PHP.
     */

    $data = [
        'name'            => $name,
        'short_name'      => trim((string) ($_POST['short_name'] ?? '')),
        'color_primary'   => $primary,
        'color_secondary' => $secondary,
        'is_own_team'     => !empty($_POST['is_own_team']),
        'aliases'         => (string) ($_POST['aliases'] ?? ''),
    ];

    $crest = Crest::accept($_FILES['crest'] ?? null);
    if (!$crest['ok']) {
        Session::flash('error', View::t($crest['error']));
        redirect($id === null ? '/kluby/nowy' : '/kluby/' . $id);
    }

    if ($id === null) {
        $data['crest_path'] = $crest['path'] ?? null;
        $id = Clubs::create($userId, $data);
    } else {
        Clubs::update($id, $userId, $data);
        if (!empty($crest['path'])) {
            $previous = Clubs::find($id)['crest_path'] ?? null;
            Clubs::setCrest($id, (string) $crest['path'], $userId);
            if (is_string($previous) && $previous !== '' && Storage::isInside($previous)) {
                @unlink($previous);
            }
        }
    }

    Session::flash('notice', View::t('club.saved'));
    redirect(safeReturn($_POST['powrot'] ?? null) !== '/' ? safeReturn($_POST['powrot'] ?? null) : '/kluby');
}

function deleteClub(int $id, int $userId): void
{
    $club = Clubs::find($id);
    if ($club === null) {
        redirect('/kluby');
    }

    // Bez potwierdzenia pokazujemy pytanie — osobny krok, bez JavaScriptu.
    if (empty($_POST['potwierdz'])) {
        View::page('club_delete', [
            'title'  => View::t('club.delete'),
            'active' => 'clubs',
            'club'   => $club,
        ]);
        return;
    }

    $result = Clubs::delete($id, $userId);
    Session::flash(
        $result['ok'] ? 'notice' : 'error',
        View::t($result['ok'] ? 'club.deleted' : $result['error'])
    );
    redirect($result['ok'] ? '/kluby' : '/kluby/' . $id);
}

/**
 * Herb serwowany przez PHP, nigdy bezpośrednio (CLAUDE.md §5).
 *
 * SVG to dokument XML, nie obrazek — dlatego oprócz sprawdzenia przy wgrywaniu
 * dokładamy `Content-Security-Policy: default-src 'none'`. Nawet gdyby coś
 * przeszło przez filtr, przeglądarka nie wykona skryptu ani nie pobierze
 * niczego z zewnątrz.
 */
function serveCrest(int $clubId): void
{
    $club = Clubs::find($clubId);
    $path = is_array($club) ? (string) ($club['crest_path'] ?? '') : '';

    if ($path === '' || !Storage::isInside($path) || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo View::t('common.not_found');
        return;
    }

    header('Content-Type: ' . Crest::mime($path));
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($path);
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
        // Token trwały wydajemy DOPIERO po udanym podaniu hasła.
        if (!empty($_POST['zapamietaj'])) {
            setRememberCookie(Remember::issue((int) $result['user']['id']));
        }
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
