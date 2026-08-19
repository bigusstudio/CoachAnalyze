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
use CoachAnalyze\Mailer;
use CoachAnalyze\Mappings;
use CoachAnalyze\Matches;
use CoachAnalyze\Notes;
use CoachAnalyze\Notifications;
use CoachAnalyze\Remember;
use CoachAnalyze\Reports;
use CoachAnalyze\Seasons;
use CoachAnalyze\Session;
use CoachAnalyze\Share;
use CoachAnalyze\Stats;
use CoachAnalyze\Storage;
use CoachAnalyze\Upload;
use CoachAnalyze\Users;
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

/*
 * RESET HASŁA — trasy przedlogowe, POZA przełącznikiem tras i poza bramkami
 * ról (skaner test_bramki_rol obejmuje wyłącznie przełącznik). Pilnują ich
 * limity prób w Redisie i CSRF; odpowiedź jest IDENTYCZNA niezależnie od tego,
 * czy adres istnieje — rozstrzyga to dopiero proces roboczy (PasswordReset).
 */
if ($path === '/haslo/zapomniane') {
    $method === 'POST' ? handleForgotPassword() : showForgotPassword();
    exit;
}

if (preg_match('#^/haslo/reset/([a-f0-9]{64})$#', $path, $m) === 1) {
    $method === 'POST' ? handlePasswordReset($m[1]) : showPasswordReset($m[1]);
    exit;
}

// Hasło indeksu współczynników — PUBLICZNE, bez sesji (M1). Czytelnik raportu
// publicznego (prezes, sztab) klika odsyłacz przy wskaźniku i ma trafić na
// definicję, nie na formularz logowania. Zły klucz klubu i złe hasło dają
// IDENTYCZNE 404 — jak przy raporcie.
if (preg_match('#^/r/([^/]+)/i/([a-z0-9-]{1,60})$#', $path, $m) === 1 && $method === 'GET') {
    servePublicIndexTerm(rawurldecode($m[1]), $m[2]);
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

/*
 * Punkt końcowy chmurek powiadomień — OBSŁUGIWANY PRZED `requireLogin()`.
 *
 * Powód: `requireLogin()` przekierowuje na `/login`, a przeglądarka wykonałaby
 * to przekierowanie po cichu i skrypt dostałby stronę logowania jako „odpowiedź
 * JSON". Zamiast tego brak sesji daje **404**, a nie 401 ani 302 — 401 na
 * istniejącej trasie potwierdza, że trasa istnieje, a tego nie ma powodu
 * zdradzać komuś, kto nie jest zalogowany.
 */
if ($path === '/powiadomienia/nowe') {
    if ($method !== 'GET' || Session::userId() === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Nie znaleziono.';
        exit;
    }
    notificationsFeed((int) Session::userId());
    exit;
}

// --- od tego miejsca wszystko wymaga zalogowania --------------------------
$user = Auth::requireLogin();

/*
 * WYMUSZONA ZMIANA HASŁA.
 *
 * Konto założone przez administratora ma hasło, które zna DWOJE ludzi — ten,
 * kto je wygenerował, i właściciel. Do czasu zmiany panel wpuszcza wyłącznie
 * na ekran konta i na wylogowanie.
 *
 * Sprawdzane TUTAJ, a nie w widokach: warunek w szablonie omija się wpisaniem
 * adresu z palca.
 */
if (!empty($user['must_change_password'])
    && !in_array($path, ['/konto', '/konto/haslo', '/logout'], true)) {
    Session::flash('error', View::t('users.must_change'));
    redirect('/konto');
}

/**
 * Bramka roli. Wołana W TRASIE, przed wykonaniem czynności.
 *
 * Ukrycie przycisku w widoku nie jest kontrolą dostępu — adres da się wpisać
 * ręcznie, a POST wysłać z konsoli. Widok chowa to, czego nie wolno, żeby nie
 * kusić; o tym, czy wolno, rozstrzyga to miejsce.
 */
function requireCan(array $user, string $czynnosc): void
{
    if (\CoachAnalyze\Users::can($user, $czynnosc)) {
        return;
    }

    // 404, nie 403: rola bez uprawnienia nie ma powodu wiedzieć, że taka część
    // panelu w ogóle istnieje.
    http_response_code(404);
    View::page('soon', [
        'title'   => View::t('common.not_found'),
        'heading' => View::t('common.not_found'),
        'body'    => View::t('users.err.forbidden'),
    ]);
    exit;
}

switch (true) {
    /*
     * PUNKT WEJŚCIA = LISTA KLUBÓW (Sesja 2, docs/PRZEBUDOWA_KLUB_SESJE.md).
     * Klub jest teraz jednostką, wokół której kręci się panel — pulpit ogólny
     * (liczniki i zadania niezależne od klubu) zostaje, ale pod `/pulpit`,
     * dostępny z nawigacji, nie jako to, co widać zaraz po zalogowaniu.
     */
    case $path === '/' && $method === 'GET':
        redirect('/kluby');
        break;

    case $path === '/pulpit' && $method === 'GET':
        View::page('dashboard', [
            'title'    => View::t('dash.title'),
            'active'   => 'pulpit',
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
        // Viewer OGLĄDA. Upload wprowadza dane taktyczne do systemu i jest
        // czynnością, nie podglądem.
        requireCan($user, 'upload');
        requireCsrf();
        handleImport((int) $user['id']);
        break;

    case preg_match('#^/import/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showCoverage((int) $m[1]);
        break;

    case preg_match('#^/import/(\d+)/generuj$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'generate');
        requireCsrf();
        queueBuild((int) $m[1], (int) $user['id']);
        break;

    // ------------------------------------------------- kreator mapowań
    case preg_match('#^/import/(\d+)/mapowanie$#', $path, $m) === 1 && $method === 'GET':
        showMapping((int) $m[1]);
        break;

    case preg_match('#^/import/(\d+)/mapowanie$#', $path, $m) === 1 && $method === 'POST':
        // Profil mapowań decyduje, które zdarzenia wchodzą do metryk — zmiana
        // tej samej wagi co generowanie raportu, więc i bramka ta sama klasa.
        requireCan($user, 'mappings');
        requireCsrf();
        saveMapping((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/kluby/(\d+)/mapowania$#', $path, $m) === 1 && $method === 'GET':
        showClubMappings((int) $m[1]);
        break;

    case preg_match('#^/kluby/(\d+)/mapowania$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'mappings');
        requireCsrf();
        updateClubMappings((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/zadania/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showJob((int) $m[1]);
        break;

    case preg_match('#^/zadania/(\d+)/ponow$#', $path, $m) === 1 && $method === 'POST':
        // Ponowienie uruchamia silnik albo wysyłkę poczty — czynność, nie podgląd.
        requireCan($user, 'generate');
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

    // ------------------------------------------------- zarządzanie kontami
    //
    // KAŻDA z tych tras sprawdza rolę PRZED wykonaniem czynności. Ukrycie
    // pozycji w nawigacji to wygoda, nie zabezpieczenie.
    case $path === '/uzytkownicy' && $method === 'GET':
        requireCan($user, 'accounts');
        showUsers();
        break;

    case $path === '/uzytkownicy' && $method === 'POST':
        requireCan($user, 'accounts');
        requireCsrf();
        createUser((int) $user['id']);
        break;

    case preg_match('#^/uzytkownicy/(\d+)/rola$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'accounts');
        requireCsrf();
        changeUserRole((int) $m[1], (string) ($_POST['role'] ?? ''), (int) $user['id']);
        break;

    case preg_match('#^/uzytkownicy/(\d+)/status$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'accounts');
        requireCsrf();
        changeUserStatus((int) $m[1], (string) ($_POST['status'] ?? ''), (int) $user['id']);
        break;

    case preg_match('#^/uzytkownicy/(\d+)/haslo$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'accounts');
        requireCsrf();
        resetUserPassword((int) $m[1], (int) $user['id']);
        break;

    // ------------------------------------------------- konto i urządzenia
    case $path === '/konto' && $method === 'GET':
        View::page('account', [
            'title'    => View::t('account.title'),
            'active'   => 'account',
            'user'     => $user,
            'devices'  => Remember::devices((int) $user['id']),
            'mailPrefs' => Notifications::preferences($user),
            'mailReady' => Mailer::isConfigured(),
            'fullAuth' => Session::hasFullAccess(),
            'notice'   => Session::flash('notice'),
            'error'    => Session::flash('error'),
        ]);
        break;

    case $path === '/konto/powiadomienia' && $method === 'POST':
        requireCsrf();
        // Pola wyboru nieodznaczone NIE PRZYCHODZĄ w żądaniu — dlatego budujemy
        // pełny zestaw z listy nadesłanej, a brak wpisu znaczy „wyłączone".
        // Odczytywanie tego wprost z $_POST dałoby przełącznik, którego nie da
        // się wyłączyć.
        $wybrane = array_flip(array_map('strval', (array) ($_POST['typy'] ?? [])));
        Notifications::savePreferences((int) $user['id'], [
            Notifications::TYP_PENDING => isset($wybrane[Notifications::TYP_PENDING]),
            Notifications::TYP_READY   => isset($wybrane[Notifications::TYP_READY]),
            Notifications::TYP_FAILED  => isset($wybrane[Notifications::TYP_FAILED]),
        ]);
        Session::flash('notice', View::t('notif.prefs.saved'));
        redirect('/konto');
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
    /*
     * LISTA = WYŁĄCZNIE TENANCI (Sesja 2). Rywale (`is_own_team = 0`) nie są
     * „klubem do wyboru" w nawigacji — żyją w warstwie danych jako strona
     * meczu. Nadal da się do nich dotrzeć pod adresem, np. z pokrycia importu
     * („znany klub" → `/kluby/{id}`) — ten edytor generyczny zostaje bez zmian.
     */
    case $path === '/kluby' && $method === 'GET':
        View::page('clubs_list', [
            'title'  => View::t('club.list'),
            'active' => 'clubs',
            'clubs'  => Clubs::tenants(),
            'notice' => Session::flash('notice'),
            'error'  => Session::flash('error'),
        ]);
        break;

    case $path === '/kluby/nowy' && $method === 'GET':
        // `?tenant=1` — wejście z nawigacji „Kluby → Nowy klub". Tworzenie
        // klubu z listy głównej ZAWSZE zakłada tenanta (WYTYCZNA Sesji 2):
        // pole „to mój klub" znika z formularza, a POST je wymusza niżej
        // (saveClub) niezależnie od tego, co przyszłoby w żądaniu.
        //
        // Bez tego parametru formularz zostaje TAKI SAM jak dziś — to ścieżka
        // zakładania RYWALA z ekranu pokrycia importu (nazwa z eksportu,
        // `is_own_team` odznaczone domyślnie), świadomie nietknięta.
        $forceTenant = ($_GET['tenant'] ?? '') === '1';
        View::page('club_form', [
            'title'         => View::t($forceTenant ? 'club.new_tenant' : 'club.new'),
            'active'        => 'clubs',
            'club'          => null,
            'forceTenant'   => $forceTenant,
            // Nazwa proponowana z danych eksportu — operator potwierdza lub poprawia.
            'suggestedName' => isset($_GET['nazwa']) ? trim((string) $_GET['nazwa']) : null,
            'backTo'        => safeReturn($_GET['powrot'] ?? null),
            'error'         => Session::flash('error'),
        ]);
        break;

    case $path === '/kluby' && $method === 'POST':
        requireCan($user, 'clubs');
        requireCsrf();
        saveClub(null, (int) $user['id']);
        break;

    case preg_match('#^/kluby/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showClub((int) $m[1]);
        break;

    case preg_match('#^/kluby/(\d+)$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'clubs');
        requireCsrf();
        saveClub((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/kluby/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'clubs');
        requireCsrf();
        deleteClub((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/herb/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        serveCrest((int) $m[1]);
        break;

    // ------------------------------------------------------- hub klubu (Sesja 2)
    //
    // Wejście do panelu, gdy kontekst klubu jest wybrany. Wszystkie trasy
    // poniżej wymagają TENANTA — rywal (`is_own_team = 0`) pod tym adresem
    // dostaje 404, tak samo jak nieistniejące id: hub jest pojęciem tenanta,
    // nie każdego wiersza w `clubs` (patrz `resolveTenant()`).
    case preg_match('#^/klub/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        showClubHub((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/mecze$#', $path, $m) === 1 && $method === 'GET':
        showClubMatches((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/raporty$#', $path, $m) === 1 && $method === 'GET':
        showClubReports((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/notatki$#', $path, $m) === 1 && $method === 'GET':
        showClubNotes((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/import$#', $path, $m) === 1 && $method === 'GET':
        showClubImport((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/import$#', $path, $m) === 1 && $method === 'POST':
        // Sama czynność (wgranie eksportu) jest tożsama z globalnym `/import` —
        // ta sama bramka roli.
        requireCan($user, 'upload');
        requireCsrf();
        handleClubImport((int) $m[1], (int) $user['id']);
        break;

    // ------------------------------------------- konfigurator raportu (Sesje 3+4)
    case preg_match('#^/klub/(\d+)/konfigurator$#', $path, $m) === 1 && $method === 'GET':
        showConfigurator((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/konfigurator$#', $path, $m) === 1 && $method === 'POST':
        // Import założycielski wprowadza dane taktyczne do systemu — ta sama
        // bramka co każdy inny upload.
        requireCan($user, 'upload');
        requireCsrf();
        handleConfiguratorImport((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/klub/(\d+)/konfigurator/slownik$#', $path, $m) === 1 && $method === 'GET':
        showConfiguratorDictionary((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/konfigurator/slownik$#', $path, $m) === 1 && $method === 'POST':
        // Zapis decyzji o zmiennych rozstrzyga, co wejdzie do raportu i jak
        // zostanie policzone — waga ta sama co profil mapowań.
        requireCan($user, 'mappings');
        requireCsrf();
        saveConfiguratorDraft((int) $m[1]);
        break;

    case preg_match('#^/klub/(\d+)/konfigurator/zapisz$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'mappings');
        requireCsrf();
        saveConfiguratorTemplate((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/klub/(\d+)/konfigurator/porzuc$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'mappings');
        requireCsrf();
        discardConfiguratorDraft((int) $m[1]);
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
        requireCan($user, 'seasons');
        requireCsrf();
        saveSeason((int) $user['id']);
        break;

    case preg_match('#^/sezony/(\d+)/biezacy$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'seasons');
        requireCsrf();
        Seasons::markCurrent((int) $m[1], (int) $user['id']);
        Session::flash('notice', View::t('season.current_set'));
        redirect('/sezony');
        break;

    case preg_match('#^/sezony/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'seasons');
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
        // `notes` ma także viewer — notatnik jest jego warsztatem pracy.
        // Bramka istnieje po to, żeby ta decyzja była JAWNA, a nie domyślna.
        requireCan($user, 'notes');
        requireCsrf();
        saveNote((int) $user['id']);
        break;

    case preg_match('#^/notatki/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'notes');
        requireCsrf();
        Notes::delete((int) $m[1], (int) $user['id']);
        Session::flash('notice', View::t('note.deleted'));
        redirect(safeReturn($_POST['powrot'] ?? '/notatki'));
        break;

    // ------------------------------------------------- indeks współczynników (M1)
    case $path === '/indeks' && $method === 'GET':
        showIndexList();
        break;

    case preg_match('#^/indeks/([a-z0-9-]{1,60})$#', $path, $m) === 1 && $method === 'GET':
        showIndexTerm($m[1]);
        break;

    case preg_match('#^/indeks/([a-z0-9-]{1,60})/edytuj$#', $path, $m) === 1 && $method === 'GET':
        // Formularz też za bramką: ekran edycji dla roli bez prawa zapisu
        // to zaproszenie do pracy, która pójdzie do kosza.
        requireCan($user, 'index');
        showIndexEdit($m[1]);
        break;

    case preg_match('#^/indeks/([a-z0-9-]{1,60})$#', $path, $m) === 1 && $method === 'POST':
        // Hasło opisuje metodykę liczenia — edycja to decyzja metodyczna,
        // nie podgląd. Viewer czyta, admin i operator piszą.
        requireCan($user, 'index');
        requireCsrf();
        saveIndexTerm($m[1], (int) $user['id']);
        break;

    // ------------------------------------------------- kalkulator xG (M3)
    case $path === '/xg' && $method === 'GET':
        showXgCalc((int) $user['id']);
        break;

    case $path === '/xg' && $method === 'POST':
        // Narzędzie robocze analityka — dodawanie strzałów to praca na danych,
        // nie podgląd. Viewer ogląda listę, nie dopisuje.
        requireCan($user, 'generate');
        requireCsrf();
        addXgShot((int) $user['id']);
        break;

    case preg_match('#^/xg/(\d+)$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'generate');
        requireCsrf();
        updateXgShot((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/xg/(\d+)/usun$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'generate');
        requireCsrf();
        \CoachAnalyze\XgCalc::delete((int) $m[1], (int) $user['id']);
        Session::flash('notice', View::t('xg.deleted'));
        redirect('/xg');
        break;

    // ------------------------------------------------- raporty (lista zbiorcza)
    case $path === '/raporty' && $method === 'GET':
        showReports();
        break;

    case preg_match('#^/raport/(\d+)/ponow$#', $path, $m) === 1 && $method === 'POST':
        requireCan($user, 'generate');
        requireCsrf();
        regenerateReport((int) $m[1], (int) $user['id']);
        break;

    // ------------------------------------------------- powiadomienia
    case $path === '/powiadomienia' && $method === 'GET':
        showNotifications((int) $user['id']);
        break;

    // Zamknięcie jednej chmurki. Działa BEZ SKRYPTU — to zwykły formularz
    // z tokenem CSRF; skrypt wysyła dokładnie to samo żądanie przez `fetch`.
    case preg_match('#^/powiadomienia/(\d+)/odczytane$#', $path, $m) === 1 && $method === 'POST':
        requireCsrf();
        $oznaczono = Notifications::markRead((int) $m[1], (int) $user['id']);

        // Odpowiedź dla skryptu: bez przekierowania, z aktualnym licznikiem.
        // Rozpoznajemy go po nagłówku, a nie po osobnej trasie — jedna trasa,
        // jedna reguła CSRF, jedno miejsce do pomylenia mniej.
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(
                ['ok' => $oznaczono, 'unread' => Notifications::unreadCount((int) $user['id'])],
                JSON_UNESCAPED_UNICODE
            );
            break;
        }

        redirect(safeReturn($_POST['powrot'] ?? '/'));
        break;

    case preg_match('#^/mecze/(\d+)/historia$#', $path, $m) === 1 && $method === 'GET':
        showMatchHistory((int) $m[1]);
        break;

    case preg_match('#^/raport/(\d+)$#', $path, $m) === 1 && $method === 'GET':
        serveReport((int) $m[1]);
        break;

    // ------------------------------------------------------- publikacja (Etap 7)
    case preg_match('#^/raport/(\d+)/udostepnij$#', $path, $m) === 1 && $method === 'GET':
        showShare((int) $m[1]);
        break;

    case preg_match('#^/raport/(\d+)/udostepnij$#', $path, $m) === 1 && $method === 'POST':
        // Udostępnienie WYPUSZCZA RAPORT POZA PANEL — to decyzja, nie podgląd.
        requireCan($user, 'share');
        requireCsrf();
        createShare((int) $m[1], (int) $user['id']);
        break;

    case preg_match('#^/link/(\d+)/odwolaj$#', $path, $m) === 1 && $method === 'POST':
        // Odwołanie linku to druga strona tej samej decyzji co jego wydanie.
        requireCan($user, 'share');
        requireCsrf();
        $id = (int) $m[1];
        $link = \CoachAnalyze\Db::one('SELECT report_id FROM share_links WHERE id = :id', ['id' => $id]);
        Session::flash(
            Share::revoke($id, (int) $user['id']) ? 'notice' : 'error',
            View::t('share.revoked')
        );
        // Odwołać link można z kilku ekranów — wracamy tam, skąd przyszliśmy.
        // `safeReturn()` przepuszcza wyłącznie ścieżki własne, więc pole `powrot`
        // nie da się użyć do przekierowania na obcą stronę.
        redirect(isset($_POST['powrot'])
            ? safeReturn((string) $_POST['powrot'])
            : ($link !== null ? '/raport/' . (int) $link['report_id'] . '/udostepnij' : '/linki'));
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
    $powod = Session::csrfProblem($_POST['csrf'] ?? null);
    if ($powod === Session::CSRF_OK) {
        return;
    }
    Audit::log(Audit::CSRF_FAIL, Session::userId(), 'session');
    http_response_code(400);
    View::page('soon', [
        'title'   => View::t('common.error'),
        'heading' => View::t('common.error'),
        'body'    => csrfMessage($powod),
    ]);
    exit;
}

/**
 * Komunikat DOPASOWANY DO PRZYCZYNY odrzucenia tokenu CSRF.
 *
 * Powód istnienia: „Formularz stracił ważność. Spróbuj jeszcze raz." obiecuje,
 * że powtórzenie pomoże. Gdy przyczyną jest brak sesji — a nie stary formularz —
 * powtórzenie da dokładnie ten sam wynik, a użytkownik klika w nieskończoność,
 * bo komunikat go do tego zachęca. Zgłoszone z produkcji: Firefox na Windowsie
 * przy ostrzeżeniu o certyfikacie nie zapisywał ciasteczka `Secure`, więc sesja
 * nie powstawała, token nie miał się gdzie zapisać i pętla się zapętlała.
 *
 * Kolejność rozstrzygania: najpierw pytamy, czy wina nie leży PO NASZEJ STRONIE.
 * Serwer, który nie umie zapisać sesji, wygląda z przeglądarki identycznie jak
 * zablokowane ciasteczka, a obwinienie użytkownika za własną awarię wysyła
 * go w kilkugodzinne szukanie ustawień, których nie ma po co zmieniać.
 */
function csrfMessage(string $powod): string
{
    if ($powod === Session::CSRF_MISMATCH) {
        return View::t('login.err.csrf');
    }

    $awaria = Session::storageProblem();
    if ($awaria !== null) {
        error_log('sesja: ' . $awaria);
        return View::t('login.err.session_storage');
    }

    if ($powod === Session::CSRF_NO_TOKEN) {
        // Ciasteczko wróciło, sesja pusta, zapis działa — sesja po prostu wygasła.
        return View::t('login.err.session_lost');
    }

    if (Session::cookieUnreachable()) {
        error_log('sesja: ciasteczko ma flagę Secure (APP_URL to https), a żądanie przyszło bez '
            . 'szyfrowania — przeglądarka odrzuca ciasteczko i sesja nie powstanie');
        return View::t('login.err.cookie_insecure');
    }

    return View::t('login.err.cookie_blocked');
}

/**
 * Ostrzeżenie pokazywane PRZED pierwszą próbą logowania, gdy już wiadomo,
 * że ciasteczko sesji nie ma jak wrócić.
 *
 * Warunek `!cookieReturned()` nie jest ozdobnikiem: jeśli przeglądarka odesłała
 * ciasteczko sesji, to je zapisała i ostrzeganie jej użytkownika byłoby
 * fałszywym alarmem — niezależnie od tego, co mówią nagłówki schematu.
 */
function loginCookieWarning(): ?string
{
    if (Session::cookieReturned() || !Session::cookieUnreachable()) {
        return null;
    }
    return View::t('login.err.cookie_insecure');
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
/**
 * `$clubId` — SESJA 2: `/klub/{id}/import` przekazuje tenanta wprost z trasy,
 * obowiązkowo (`showClubImport()`/route sprawdza wcześniej, że to tenant).
 * `null` to globalny `/import`, bez kontekstu w adresie — zostaje dla
 * ciągłości i sięga po klub domyślny w `Imports::create()`.
 *
 * `$formPath` — dokąd wraca operator przy błędzie walidacji pliku. Musi
 * wskazywać TEN formularz, z którego przyszedł, inaczej błąd „za duży plik"
 * na scoped ekranie wyrzuciłby operatora do globalnego `/import`.
 */
function handleImport(int $userId, ?int $clubId = null, string $formPath = '/import'): void
{
    $csv = Upload::accept($_FILES['csv'] ?? null, 'csv', true);
    if (!$csv['ok']) {
        Session::flash('error', View::t($csv['error']));
        redirect($formPath);
    }

    $json = Upload::accept($_FILES['json'] ?? null, 'json', false);
    if (!$json['ok']) {
        // CSV już leży w storage — sprzątamy, żeby nieudany import nie zostawiał śmieci.
        @unlink((string) $csv['path']);
        Session::flash('error', View::t($json['error']));
        redirect($formPath);
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
        (string) $csv['sha256'],
        $clubId
    );

    $jobId = Imports::queueInspect($importId, $userId);

    /*
     * Powiadomienie „przetwarzanie w toku" — ZE ZWŁOKĄ.
     *
     * Mail ma pójść tylko wtedy, gdy przetwarzanie faktycznie się przeciąga.
     * Przy pustej kolejce raport bywa gotowy w kilkanaście sekund i taki mail
     * dotarłby PO mailu „raport gotowy", czyli jako dezinformacja.
     *
     * Zwłoka sama nie wystarcza: przed wysłaniem worker sprawdza jeszcze, czy
     * powód nadal obowiązuje (Notifications::stillRelevant). Zwłoka odsuwa
     * pytanie w czasie, a odpowiedź na nie pada dopiero w chwili wysyłki.
     */
    $import = Imports::find($importId);
    if ($import !== null) {
        Notifications::create($userId, [
            'type'      => Notifications::TYP_PENDING,
            'title'     => View::t('notif.pending.title'),
            'body'      => View::t('notif.pending.body'),
            'entity'    => 'match',
            'entity_id' => (int) $import['match_id'],
            'url'       => '/zadania/' . $jobId,
            'delay'     => Notifications::OPOZNIENIE_PENDING,
        ]);
    }

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

    /*
     * KREATOR MAPOWAŃ przed raportem pokrycia.
     *
     * Cztery eksporty od trzech klubów mają trzy różne słowniki, a klub potrafi
     * dołożyć tag w trakcie sezonu. Bez zatrzymania w tym miejscu nowy klub
     * dostaje raport z pustymi sekcjami, a zmiana metodyki po cichu wycina
     * zdarzenia z metryk — bez błędu i bez ostrzeżenia.
     *
     * Gdy wszystkie tagi są znane, NIE ZATRZYMUJEMY SIĘ. Ekran, który pyta
     * o nic, uczy klikać „dalej" bez czytania.
     */
    if (Imports::needsMapping($import)) {
        redirect('/import/' . $importId . '/mapowanie');
    }

    View::page('import_coverage', [
        'title'               => View::t('coverage.title'),
        'active'              => 'import',
        'import'              => $import,
        'coverage'            => $report['coverage'],
        'warnings'            => $report['warnings'],
        'sectionsUnavailable' => $report['sections_unavailable'],
        'sectionsAvailable'   => $report['sections_available'],
        'excluded'            => $report['excluded'],
        'report'              => Imports::latestReport((int) $import['match_id']),
        'notice'              => Session::flash('notice'),
    ]);
}

/**
 * Kreator mapowań: tagi z eksportu, o których profil klubu jeszcze nie wie.
 */
function showMapping(int $importId): void
{
    $import = Imports::find($importId);
    if ($import === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
        ]);
        return;
    }

    $meta = json_decode((string) ($import['coverage_json'] ?? ''), true);
    if (!is_array($meta) || $meta === []) {
        // Bez pokrycia nie ma czego mapować — wracamy do stanu zadania.
        $job = Imports::latestJob($importId, 'inspect');
        redirect($job !== null ? '/zadania/' . (int) $job['id'] : '/import');
    }

    $clubId   = Imports::mappingClubId($import);
    $nieznane = Mappings::unknown($meta, $clubId);

    // Nic nowego — nie zatrzymujemy operatora na pustym ekranie.
    if ($nieznane['tags'] === [] && $nieznane['labels'] === []) {
        redirect('/import/' . $importId);
    }

    // Podpowiedzi liczone na podstawie tego, co klub JUŻ ma zmapowane, oraz
    // słownika domyślnego silnika. To sugestia — zaznaczona wstępnie, ale
    // zatwierdza ją człowiek.
    $znane = Mappings::decidedTags($clubId) + Mappings::domyslneTagi();
    foreach ($nieznane['tags'] as &$tag) {
        $tag['suggestion'] = Mappings::suggest((string) $tag['name'], $znane);
    }
    unset($tag);

    View::page('mapping_form', [
        'title'      => View::t('mapping.title'),
        'active'     => 'import',
        'import'     => $import,
        'club'       => $clubId !== null ? Clubs::find($clubId) : null,
        'tags'       => $nieznane['tags'],
        'labels'     => $nieznane['labels'],
        'concepts'   => Mappings::POJECIA,
        'qualifiers' => Mappings::KWALIFIKATORY,
        'error'      => Session::flash('error'),
    ]);
}

/**
 * Zapis decyzji operatora jako NOWEJ WERSJI profilu klubu.
 */
function saveMapping(int $importId, int $userId): void
{
    $import = Imports::find($importId);
    if ($import === null) {
        http_response_code(404);
        redirect('/import');
    }

    $clubId = Imports::mappingClubId($import);
    if ($clubId === null) {
        // Bez klubu nie ma gdzie zapisać profilu. Mówimy wprost, zamiast
        // przyjmować decyzje i gubić je po cichu.
        Session::flash('error', View::t('mapping.err.no_club'));
        redirect('/import/' . $importId . '/mapowanie');
    }

    $tagi     = (array) ($_POST['tag'] ?? []);
    $etykiety = (array) ($_POST['label'] ?? []);

    // Zgłoszenia brakujących pojęć wyjmujemy PRZED zapisem profilu: tag idzie
    // wtedy tymczasowo jako nieanalizowany, a sprawa zostaje do przejrzenia.
    $doZgloszenia = [];
    foreach ($tagi as $tag => $wybor) {
        if ((string) $wybor === Mappings::ZGLOS) {
            $doZgloszenia[] = (string) $tag;
            $tagi[$tag] = Mappings::NIE_ANALIZUJ;
        }
    }

    try {
        $profileId = Mappings::saveVersion(
            $clubId,
            array_map('strval', $tagi),
            array_map('strval', $etykiety),
            $userId,
            isset($_POST['note']) ? (string) $_POST['note'] : null
        );
    } catch (\Throwable $e) {
        // Nieznane pojęcie w żądaniu = ktoś obszedł listę wyboru. Nie zapisujemy
        // niczego i mówimy o tym wprost.
        error_log('mapowania: ' . $e->getMessage());
        Session::flash('error', View::t('mapping.err.unknown_concept'));
        redirect('/import/' . $importId . '/mapowanie');
    }

    foreach ($doZgloszenia as $tag) {
        Mappings::requestConcept(
            $clubId,
            $importId,
            $tag,
            (string) ($_POST['rationale'][$tag] ?? ''),
            $userId
        );
    }

    Imports::setMappingProfile($importId, $profileId);

    Session::flash('notice', View::t(
        $doZgloszenia === [] ? 'mapping.saved' : 'mapping.saved_with_request',
        count($doZgloszenia)
    ));
    redirect('/import/' . $importId);
}

/** Ustawienia mapowań klubu: historia wersji i decyzje do zmiany. */
function showClubMappings(int $clubId): void
{
    $club = Clubs::find($clubId);
    if ($club === null) {
        http_response_code(404);
        View::page('soon', ['title' => View::t('common.not_found'), 'heading' => View::t('common.not_found')]);
        return;
    }

    View::page('club_mappings', [
        'title'      => View::t('mapping.club.title'),
        'active'     => 'clubs',
        'club'       => $club,
        'assigned'   => Mappings::assignedTags($clubId),
        'ignored'    => Mappings::ignoredTags($clubId),
        'history'    => Mappings::history($clubId),
        'requests'   => Mappings::requestsFor($clubId),
        'concepts'   => Mappings::POJECIA,
        'notice'     => Session::flash('notice'),
        'error'      => Session::flash('error'),
    ]);
}

/** Zmiana decyzji o tagu — również cofnięcie „nie analizuj". */
function updateClubMappings(int $clubId, int $userId): void
{
    if (Clubs::find($clubId) === null) {
        http_response_code(404);
        redirect('/kluby');
    }

    try {
        Mappings::saveVersion(
            $clubId,
            array_map('strval', (array) ($_POST['tag'] ?? [])),
            [],
            $userId,
            isset($_POST['note']) ? (string) $_POST['note'] : null
        );
        Session::flash('notice', View::t('mapping.club.saved'));
    } catch (\Throwable $e) {
        error_log('mapowania: ' . $e->getMessage());
        Session::flash('error', View::t('mapping.err.unknown_concept'));
    }

    redirect('/kluby/' . $clubId . '/mapowania');
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

    /*
     * KREATOR MAPOWAŃ PILNOWANY PRZY AKCJI, nie tylko przy ekranie.
     *
     * Przekierowanie w `showCoverage()` chroni jedną drogę — tę przez raport
     * pokrycia. Do generowania da się jednak dojść inaczej: przyciskiem
     * „wygeneruj ponownie" z listy raportów albo wprost POST-em pod ten adres.
     * Wtedy eksport z nowymi tagami przechodziłby do renderu z pominięciem
     * decyzji operatora, a raport powstałby niepełny — bez śladu, że czegoś
     * w nim brakuje.
     *
     * Kontrola przy akcji zmieniającej stan jest jedyną, której nie da się
     * ominąć wyborem innej ścieżki w interfejsie.
     */
    if (Imports::needsMapping($import)) {
        Session::flash('notice', View::t('mapping.required'));
        redirect('/import/' . $importId . '/mapowanie');
    }

    $jobId = Imports::queueBuild($importId, $userId);

    // Zadanie czeka na crona — z przeglądarki nie wolno uruchomić procesu.
    Session::flash('notice', View::t('coverage.queued'));
    redirect('/zadania/' . $jobId);
}


/** Lista wszystkich raportów, niezależnie od meczu. */
function showReports(): void
{
    $wynik = Reports::search([
        'club'   => isset($_GET['klub']) && $_GET['klub'] !== '' ? (int) $_GET['klub'] : null,
        'season' => isset($_GET['sezon']) && $_GET['sezon'] !== '' ? (int) $_GET['sezon'] : null,
        'sort'   => isset($_GET['sort']) ? (string) $_GET['sort'] : null,
        'page'   => isset($_GET['strona']) ? (int) $_GET['strona'] : 1,
    ]);

    View::page('reports_list', [
        'title'   => View::t('reports.title'),
        'active'  => 'reports',
        'rows'    => $wynik['rows'],
        'total'   => $wynik['total'],
        'page'    => $wynik['page'],
        'pages'   => $wynik['pages'],
        'clubs'   => Reports::filterClubs(),
        'seasons' => Reports::filterSeasons(),
        'filters' => [
            'klub'  => $_GET['klub']  ?? '',
            'sezon' => $_GET['sezon'] ?? '',
            'sort'  => Reports::normalizeSort($_GET['sort'] ?? null),
        ],
        'notice'  => Session::flash('notice'),
        'error'   => Session::flash('error'),
    ]);
}

/**
 * Ponowne wygenerowanie raportu dla tego samego meczu.
 *
 * Powstaje NOWY raport, stary zostaje. Podmiana w miejscu skasowałaby odpowiedź
 * na pytanie „dlaczego raport z marca pokazywał inną liczbę" (CLAUDE.md §7),
 * a przy okazji unieważniła działający link publiczny wskazujący stary plik.
 */
function regenerateReport(int $reportId, int $userId): void
{
    $report = Reports::find($reportId);
    if ($report === null) {
        http_response_code(404);
        redirect('/raporty');
    }

    $import = Imports::latestForMatch((int) $report['match_id']);
    if ($import === null) {
        // Eksport źródłowy zniknął — mówimy wprost, zamiast kolejkować zadanie,
        // które i tak padnie za minutę z komunikatem o brakującym imporcie.
        Session::flash('error', View::t('reports.regen.no_import'));
        redirect('/raporty');
    }

    $jobId = Imports::queueBuild((int) $import['id'], $userId);
    Session::flash('notice', View::t('reports.regen.queued'));
    redirect('/zadania/' . $jobId);
}

/**
 * Punkt końcowy chmurek: JSON z nieodczytanymi powiadomieniami zalogowanego.
 *
 * ZWRACA WYŁĄCZNIE DANE, nigdy HTML-a do wstrzyknięcia. Skrypt buduje z tego
 * elementy przez `textContent`, więc nawet gdyby w tytule powiadomienia
 * znalazł się znacznik, trafi tam jako tekst — a nie jako kod do wykonania.
 *
 * Filtr konta jest w zapytaniu (`Notifications::unreadForToasts`), nie w PHP.
 */
function notificationsFeed(int $userId): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $items = [];
    foreach (Notifications::unreadForToasts($userId) as $n) {
        $items[] = [
            'id'    => (int) $n['id'],
            'kind'  => Notifications::kind((string) $n['type']),
            'title' => (string) $n['title'],
            // Odnośnik prowadzi wprost do raportu — po to, żeby chmurka
            // „raport gotowy" była jednym kliknięciem od raportu, a nie
            // wstępem do szukania go w bibliotece.
            'url'   => $n['url'] !== null ? (string) $n['url'] : null,
            'at'    => substr((string) $n['created_at'], 0, 16),
        ];
    }

    echo json_encode([
        'unread'  => Notifications::unreadCount($userId),
        'items'   => $items,
        // Podpowiedź dla skryptu, jak często pytać. Decyzję o odstępach
        // podejmuje klient — serwer mówi tylko, czy coś się dzieje.
        'working' => Notifications::hasActiveWork($userId),
    ], JSON_UNESCAPED_UNICODE);
}

/** Lista kont wraz z rolami i stanem. */
function showUsers(): void
{
    View::page('users_list', [
        'title'   => View::t('users.title'),
        'active'  => 'users',
        'users'   => Users::all(),
        'roles'   => Users::ROLE,
        'me'      => (int) (Session::userId() ?? 0),
        'admins'  => Users::activeAdminCount(),
        /*
         * HASŁO POKAZYWANE RAZ.
         *
         * Leci przez `flash` w sesji, a nie przez bazę ani adres. W bazie jest
         * wyłącznie hash; w adresie zostałoby w historii przeglądarki i w logach
         * serwera. Flash znika po jednym wyświetleniu — dokładnie tak, jak
         * obiecuje komunikat na ekranie.
         */
        'freshPassword' => Session::flash('fresh_password'),
        'freshFor'      => Session::flash('fresh_password_for'),
        'notice'  => Session::flash('notice'),
        'error'   => Session::flash('error'),
    ]);
}

/** Nowe konto z hasłem wygenerowanym przez system. */
function createUser(int $authorId): void
{
    try {
        $wynik = Users::create([
            'email'        => $_POST['email'] ?? '',
            'display_name' => $_POST['display_name'] ?? '',
            'role'         => $_POST['role'] ?? 'operator',
        ], $authorId);
    } catch (\Throwable $e) {
        Session::flash('error', $e->getMessage());
        redirect('/uzytkownicy');
    }

    Session::flash('fresh_password', $wynik['password']);
    Session::flash('fresh_password_for', (string) ($_POST['email'] ?? ''));
    Session::flash('notice', View::t('users.account_created'));
    redirect('/uzytkownicy');
}

function changeUserRole(int $userId, string $rola, int $authorId): void
{
    try {
        Users::setRole($userId, $rola, $authorId);
        Session::flash('notice', View::t('users.role_changed'));
    } catch (\Throwable $e) {
        Session::flash('error', $e->getMessage());
    }
    redirect('/uzytkownicy');
}

function changeUserStatus(int $userId, string $status, int $authorId): void
{
    try {
        Users::setStatus($userId, $status, $authorId);
        Session::flash('notice', View::t(
            $status === 'disabled' ? 'users.disabled' : 'users.enabled'
        ));
    } catch (\Throwable $e) {
        Session::flash('error', $e->getMessage());
    }
    redirect('/uzytkownicy');
}

function resetUserPassword(int $userId, int $authorId): void
{
    try {
        $haslo = Users::resetPassword($userId, $authorId);
        $konto = Users::find($userId);

        Session::flash('fresh_password', $haslo);
        Session::flash('fresh_password_for', (string) ($konto['email'] ?? ''));
        Session::flash('notice', View::t('users.password_reset'));
    } catch (\Throwable $e) {
        Session::flash('error', $e->getMessage());
    }
    redirect('/uzytkownicy');
}

/** Lista powiadomień. Wejście tutaj zeruje licznik nieodczytanych. */
function showNotifications(int $userId): void
{
    $lista = Notifications::forUser($userId);

    // Odczyt oznaczamy PO pobraniu listy, żeby na tym ekranie było jeszcze
    // widać, które były nowe. Licznik w nagłówku jest już wtedy wyzerowany.
    Notifications::markAllRead($userId);

    View::page('notifications_list', [
        'title'  => View::t('notif.title'),
        'active' => 'notifications',
        'rows'   => $lista,
    ]);
}

/** Historia zdarzeń jednego meczu: wgranie, pokrycie, raport, udostępnienie. */
function showMatchHistory(int $matchId): void
{
    $match = Matches::find($matchId);
    if ($match === null) {
        http_response_code(404);
        View::page('soon', ['title' => View::t('common.not_found'), 'heading' => View::t('common.not_found')]);
        return;
    }

    View::page('match_history', [
        'title'  => View::t('history.title'),
        'active' => 'matches',
        'match'  => $match,
        'events' => Matches::history($matchId),
    ]);
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
    // Formularz notatki bywa osadzony na scoped `/klub/{id}/notatki` (Sesja 2) —
    // `powrot` odsyła operatora dokładnie tam, skąd przyszedł, zamiast zawsze
    // do globalnego notatnika. `safeReturn()` przepuszcza wyłącznie ścieżki
    // własne aplikacji.
    $powrot = safeReturn($_POST['powrot'] ?? '/notatki');

    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        Session::flash('error', View::t('note.err.body'));
        redirect($powrot);
    }

    $scope = (string) ($_POST['scope'] ?? 'match');
    $eventRef = trim((string) ($_POST['event_ref'] ?? ''));

    // Notatka „przy zdarzeniu" bez wskazania zdarzenia byłaby notatką do meczu
    // udającą coś więcej — i nigdy nie pokazałaby się w kontekście raportu.
    if ($scope === 'event' && ($eventRef === '' || empty($_POST['match_id']))) {
        Session::flash('error', View::t('note.err.event'));
        redirect($powrot);
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
    redirect($powrot);
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

// ------------------------------------------------- indeks współczynników (M1)

/**
 * Klub, którego metodykę pokazuje indeks.
 *
 * `?klub=` wybiera wprost; bez parametru bierzemy klub „nasz" — `Clubs::all()`
 * sortuje `is_own_team DESC`, więc to pierwszy wiersz. Bez żadnego klubu
 * indeks działa dalej: pokazuje hasła domyślne (club_id null).
 */
function indeksKlub(): ?array
{
    $kluby = Clubs::all();
    if (isset($_GET['klub']) && $_GET['klub'] !== '') {
        foreach ($kluby as $klub) {
            if ((int) $klub['id'] === (int) $_GET['klub']) {
                return $klub;
            }
        }
    }
    return $kluby[0] ?? null;
}

function showIndexList(): void
{
    $klub = indeksKlub();
    $q = trim((string) ($_GET['q'] ?? ''));

    View::page('index_list', [
        'title'  => View::t('index.title'),
        'active' => 'index',
        'terms'  => \CoachAnalyze\IndexTerms::search($klub !== null ? (int) $klub['id'] : null, $q),
        'q'      => $q,
        'club'   => $klub,
        'clubs'  => Clubs::all(),
        'notice' => Session::flash('notice'),
        'error'  => Session::flash('error'),
    ]);
}

function showIndexTerm(string $slug): void
{
    $klub = indeksKlub();
    $haslo = \CoachAnalyze\IndexTerms::find($klub !== null ? (int) $klub['id'] : null, $slug);

    if ($haslo === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('index.err.missing'),
        ]);
        return;
    }

    View::page('index_term', [
        'title'  => (string) $haslo['name'],
        'active' => 'index',
        'term'   => $haslo,
        'club'   => $klub,
        'notice' => Session::flash('notice'),
    ]);
}

function showIndexEdit(string $slug): void
{
    $klub = indeksKlub();
    if ($klub === null) {
        // Bez klubu nie ma gdzie zapisać wersji — hasła domyślne są w kodzie.
        Session::flash('error', View::t('index.err.no_club'));
        redirect('/indeks');
    }

    $haslo = \CoachAnalyze\IndexTerms::find((int) $klub['id'], $slug);
    if ($haslo === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('common.not_found'),
            'heading' => View::t('common.not_found'),
            'body'    => View::t('index.err.missing'),
        ]);
        return;
    }

    View::page('index_form', [
        'title'  => View::t('index.edit.title', (string) $haslo['name']),
        'active' => 'index',
        'term'   => $haslo,
        'club'   => $klub,
        'error'  => Session::flash('error'),
    ]);
}

function saveIndexTerm(string $slug, int $userId): void
{
    $klub = indeksKlub();
    if ($klub === null) {
        Session::flash('error', View::t('index.err.no_club'));
        redirect('/indeks');
    }

    try {
        \CoachAnalyze\IndexTerms::saveVersion((int) $klub['id'], $slug, [
            'name'           => (string) ($_POST['name'] ?? ''),
            'definition'     => (string) ($_POST['definition'] ?? ''),
            'formula'        => (string) ($_POST['formula'] ?? ''),
            'example'        => (string) ($_POST['example'] ?? ''),
            'interpretation' => (string) ($_POST['interpretation'] ?? ''),
            'source'         => (string) ($_POST['source'] ?? ''),
            'estimated_note' => (string) ($_POST['estimated_note'] ?? ''),
        ], $userId);
    } catch (\Throwable $e) {
        error_log('indeks: ' . $e->getMessage());
        Session::flash('error', View::t('index.err.save'));
        redirect('/indeks/' . $slug . '/edytuj' . '?klub=' . (int) $klub['id']);
    }

    Session::flash('notice', View::t('index.saved'));
    redirect('/indeks/' . $slug . '?klub=' . (int) $klub['id']);
}

/**
 * Hasło indeksu dla czytelnika raportu publicznego — BEZ SESJI.
 *
 * Klucz klubu jest jedyną „przepustką": jest losowy, stały i obecny w każdym
 * publicznym adresie raportu, więc czytelnik raportu już go ma. Zły klucz
 * i złe hasło dają identyczne 404 z wyrównanym czasem — jak przy raporcie.
 */
function servePublicIndexTerm(string $clubKey, string $slug): void
{
    $start = microtime(true);

    $klub = \CoachAnalyze\Db::one(
        'SELECT id, name FROM clubs WHERE club_key = :key',
        ['key' => $clubKey]
    );
    if ($klub === null) {
        publicNotFound($start);
    }

    $haslo = \CoachAnalyze\IndexTerms::find((int) $klub['id'], $slug);
    if ($haslo === null) {
        publicNotFound($start);
    }

    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');

    View::page('index_public', [
        'title'  => (string) $haslo['name'],
        'chrome' => false,
        'term'   => $haslo,
    ]);
}

// ------------------------------------------------------- kalkulator xG (M3)

function showXgCalc(int $userId): void
{
    $edytowany = null;
    if (isset($_GET['edytuj'])) {
        $edytowany = \CoachAnalyze\XgCalc::find((int) $_GET['edytuj'], $userId);
    }

    View::page('xg_calc', [
        'title'     => View::t('xg.title'),
        'active'    => 'xg',
        'shots'     => \CoachAnalyze\XgCalc::shots($userId),
        'sum'       => \CoachAnalyze\XgCalc::sum($userId),
        'gridReady' => \CoachAnalyze\XgCalc::grid() !== null,
        'edited'    => $edytowany,
        'last'      => Session::flash('xg_last'),
        'notice'    => Session::flash('notice'),
        'error'     => Session::flash('error'),
    ]);
}

/**
 * Kliknięcie w boisko: `input type=image` wysyła `punkt_x`/`punkt_y`
 * w pikselach obrazka. PHP przelicza piksele na metry i ODCZYTUJE wartość
 * z siatki policzonej przez silnik — niczego nie liczy (CLAUDE.md §4).
 */
function addXgShot(int $userId): void
{
    // `input type=image name="punkt"` daje pola `punkt_x` i `punkt_y`.
    if (!isset($_POST['punkt_x'], $_POST['punkt_y'])) {
        Session::flash('error', View::t('xg.err.no_point'));
        redirect('/xg');
    }

    [$x, $y] = \CoachAnalyze\XgCalc::pxToMeters((int) $_POST['punkt_x'], (int) $_POST['punkt_y']);

    $id = \CoachAnalyze\XgCalc::add(
        $userId,
        $x,
        $y,
        (string) ($_POST['body_part'] ?? 'foot'),
        (string) ($_POST['situation'] ?? 'open')
    );

    if ($id === null) {
        Session::flash('error', View::t('xg.err.grid'));
        redirect('/xg');
    }

    $strzal = \CoachAnalyze\XgCalc::find($id, $userId);
    Session::flash('xg_last', $strzal);
    redirect('/xg');
}

function updateXgShot(int $id, int $userId): void
{
    $ok = \CoachAnalyze\XgCalc::update(
        $id,
        $userId,
        (float) str_replace(',', '.', (string) ($_POST['x'] ?? '-1')),
        (float) str_replace(',', '.', (string) ($_POST['y'] ?? '-1')),
        (string) ($_POST['body_part'] ?? 'foot'),
        (string) ($_POST['situation'] ?? 'open')
    );

    Session::flash($ok ? 'notice' : 'error', View::t($ok ? 'xg.updated' : 'xg.err.update'));
    redirect('/xg');
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
        // Hub przekazuje `?powrot=/klub/{id}`, żeby zapis wracał do niego,
        // a nie na listę klubów (`club_form.php` → `$backTo`).
        'backTo'        => safeReturn($_GET['powrot'] ?? null),
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
        // `force_tenant` — formularz osiągnięty z nawigacji „Kluby → Nowy klub"
        // (`/kluby/nowy?tenant=1`). Pole „to mój klub" tam nie istnieje, więc
        // czytanie samego `is_own_team` dałoby ZAWSZE false. Wymuszamy prawdę
        // po stronie serwera — ukryte pole formularza jest sygnałem KONTEKSTU,
        // a nie danymi, którym ufamy bez sprawdzenia, więc liczy się jego
        // ISTNIENIE (wysłał je nasz formularz), nie treść.
        'is_own_team'     => !empty($_POST['force_tenant']) || !empty($_POST['is_own_team']),
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

// -------------------------------------------------------- hub klubu (Sesja 2)

/**
 * Klub, ale WYŁĄCZNIE tenant. Hub i wszystko pod `/klub/{id}/…` to pojęcie
 * tenanta — rywal (`is_own_team = 0`) nie ma tu czego robić: nie jest
 * „klubem do wyboru" w nawigacji (WYTYCZNA Sesji 2) i nie ma własnych
 * meczów jako właściciel analizy (`club_id` zawsze wskazuje na tenanta).
 *
 * 404, nie przekierowanie — z tych samych powodów co `requireCan()`: adres,
 * pod którym nie ma nic do pokazania, nie ma wyglądać, jakby coś ukrywał.
 *
 * @return array<string,mixed>|null
 */
function resolveTenant(int $id): ?array
{
    $club = Clubs::find($id);
    return $club !== null && !empty($club['is_own_team']) ? $club : null;
}

function tenantNotFound(): void
{
    http_response_code(404);
    View::page('soon', [
        'title'   => View::t('common.not_found'),
        'heading' => View::t('common.not_found'),
        'body'    => View::t('club.not_found'),
    ]);
}

function showClubHub(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $mecze   = Matches::search(['tenant' => $id, 'sort' => 'data_desc']);
    $raporty = Reports::search(['tenant' => $id, 'sort' => 'date_desc']);

    View::page('club_dashboard', [
        'title'         => View::t('club.hub.title', (string) $club['name']),
        'active'        => 'clubs',
        'club'          => $club,
        'crumb'         => null,
        // NAZWA CELOWO NIE „template": `View::render()` ma własny parametr
        // `$template` (nazwa PLIKU szablonu) i `extract(…, EXTR_SKIP)` NIE
        // nadpisuje istniejących zmiennych — klucz `template` w danych
        // zostałby po cichu wchłonięty przez ten parametr, a widok dostałby
        // napis „club_dashboard” zamiast wiersza z bazy albo `null`.
        'reportTemplate' => \CoachAnalyze\ReportTemplates::current($id),
        'matches'       => $mecze['rows'],
        'matchesTotal'  => $mecze['total'],
        'reports'       => $raporty['rows'],
        'reportsTotal'  => $raporty['total'],
        'notice'        => Session::flash('notice'),
        'error'         => Session::flash('error'),
    ]);
}

function showClubMatches(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $filtr = [
        'sezon'  => isset($_GET['sezon']) && $_GET['sezon'] !== '' ? (int) $_GET['sezon'] : null,
        'sort'   => Matches::normalizeSort($_GET['sort'] ?? null),
        'strona' => max(1, (int) ($_GET['strona'] ?? 1)),
    ];
    View::page('matches_list', [
        'title'   => View::t('matches.title'),
        'active'  => 'clubs',
        'club'    => $club,
        'crumb'   => View::t('nav.matches'),
        'wynik'   => Matches::search([
            'tenant' => $id,
            'season' => $filtr['sezon'],
            'sort'   => $filtr['sort'],
            'page'   => $filtr['strona'],
        ]),
        'clubs'   => [],
        'seasons' => Seasons::all(),
        'filtr'   => $filtr,
        'notice'  => Session::flash('notice'),
    ]);
}

function showClubReports(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $wynik = Reports::search([
        'tenant' => $id,
        'season' => isset($_GET['sezon']) && $_GET['sezon'] !== '' ? (int) $_GET['sezon'] : null,
        'sort'   => isset($_GET['sort']) ? (string) $_GET['sort'] : null,
        'page'   => isset($_GET['strona']) ? (int) $_GET['strona'] : 1,
    ]);

    View::page('reports_list', [
        'title'   => View::t('reports.title'),
        'active'  => 'clubs',
        'club'    => $club,
        'crumb'   => View::t('nav.reports'),
        'rows'    => $wynik['rows'],
        'total'   => $wynik['total'],
        'page'    => $wynik['page'],
        'pages'   => $wynik['pages'],
        'clubs'   => [],
        'seasons' => Reports::filterSeasons(),
        'filters' => [
            'klub'  => '',
            'sezon' => $_GET['sezon'] ?? '',
            'sort'  => Reports::normalizeSort($_GET['sort'] ?? null),
        ],
        'notice'  => Session::flash('notice'),
        'error'   => Session::flash('error'),
    ]);
}

function showClubNotes(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $filtr = [
        'q'      => trim((string) ($_GET['q'] ?? '')),
        'poziom' => (string) ($_GET['poziom'] ?? ''),
        'tag'    => trim((string) ($_GET['tag'] ?? '')),
    ];
    View::page('notes_list', [
        'title'   => View::t('note.title'),
        'active'  => 'clubs',
        'club'    => $club,
        'crumb'   => View::t('nav.notes'),
        'notes'   => Notes::search($filtr['q'], $filtr['poziom'] ?: null, $filtr['tag'] ?: null, $id),
        'tags'    => Notes::tagCloud(),
        'filtr'   => $filtr,
        // Wybór meczu w formularzu nowej notatki ograniczony do tenanta —
        // inaczej notatka „przy meczu" mogłaby wskazać mecz innego klubu.
        'matches' => Matches::search(['tenant' => $id, 'sort' => 'data_desc'])['rows'],
        'clubs'   => [],
        'notice'  => Session::flash('notice'),
        'error'   => Session::flash('error'),
    ]);
}

function showClubImport(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    View::page('import_form', [
        'title'        => View::t('import.title'),
        'active'       => 'clubs',
        'club'         => $club,
        'crumb'        => View::t('import.nav'),
        'error'        => Session::flash('error'),
        'storageReady' => Storage::writable(),
    ]);
}

function handleClubImport(int $id, int $userId): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    handleImport($userId, $id, '/klub/' . $id . '/import');
}

// ------------------------------------------- konfigurator raportu (Sesje 3+4)

/**
 * Wejście do konfiguratora. Trzy stany, rozstrzygane stanem roboczym:
 *
 *   brak draftu                    -> formularz importu założycielskiego
 *   draft bez pokrycia             -> ekran oczekiwania (inspekcję robi cron)
 *   draft z pokryciem              -> edytor zmiennych (`/slownik`)
 *
 * Rozstrzyga DRAFT, nie parametr w adresie: dzięki temu odświeżenie strony
 * i powrót z zakładki trafiają tam, gdzie operator skończył, a nie na początek.
 */
function showConfigurator(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $draft = \CoachAnalyze\Configurator::draft($id);

    if ($draft !== null && !empty($draft['import_id'])) {
        $import = Imports::find((int) $draft['import_id']);

        // Import zniknął (skasowany mecz, sprzątanie) — draft wskazuje w pustkę.
        // Mówimy o tym wprost zamiast pokazywać wieczne „przetwarzanie".
        if ($import === null) {
            \CoachAnalyze\Configurator::clearDraft($id);
            Session::flash('error', View::t('conf.err.import_gone'));
            redirect('/klub/' . $id . '/konfigurator');
        }

        if (!empty($import['coverage_json'])) {
            redirect('/klub/' . $id . '/konfigurator/slownik');
        }

        $job = Imports::latestJob((int) $draft['import_id'], 'inspect');
        View::page('configurator_czekaj', [
            'title'  => View::t('conf.title'),
            'active' => 'clubs',
            'club'   => $club,
            'crumb'  => View::t('conf.crumb'),
            'job'    => $job,
            // Odstęp jak przy ekranie zadania: kolejkę podnosi cron co minutę,
            // więc odpytywanie co kilka sekund to same puste żądania.
            'refresh' => $job !== null && (string) $job['status'] === 'running' ? 6 : 20,
        ]);
        return;
    }

    View::page('configurator_import', [
        'title'        => View::t('conf.title'),
        'active'       => 'clubs',
        'club'         => $club,
        'crumb'        => View::t('conf.crumb'),
        // NIE „template": `View::render()` ma własny parametr o tej nazwie
        // (nazwa PLIKU szablonu), a `extract(…, EXTR_SKIP)` go nie nadpisuje —
        // klucz zostałby po cichu wchłonięty i widok dostałby napis zamiast
        // wiersza z bazy. Ta sama pułapka co w `club_dashboard.php`.
        'reportTemplate' => \CoachAnalyze\ReportTemplates::current($id),
        'storageReady' => Storage::writable(),
        'notice'       => Session::flash('notice'),
        'error'        => Session::flash('error'),
    ]);
}

/**
 * Import założycielski: ten sam pipeline co zwykły import.
 *
 * Mecz powstaje normalnie — import założycielski JEST pierwszym meczem klubu
 * (spec Sesji 3 pkt 7), a nie bytem osobnym. Dzięki temu surowe pliki leżą
 * tam, gdzie leżą wszystkie inne (`imports.csv_path`), i regeneracja z Sesji 7
 * będzie działać bez wyjątku dla tego jednego przypadku.
 */
function handleConfiguratorImport(int $id, int $userId): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $powrot = '/klub/' . $id . '/konfigurator';

    $csv = Upload::accept($_FILES['csv'] ?? null, 'csv', true);
    if (!$csv['ok']) {
        Session::flash('error', View::t($csv['error']));
        redirect($powrot);
    }

    $json = Upload::accept($_FILES['json'] ?? null, 'json', false);
    if (!$json['ok']) {
        @unlink((string) $csv['path']);
        Session::flash('error', View::t($json['error']));
        redirect($powrot);
    }

    $importId = Imports::create(
        $userId,
        (string) $csv['path'],
        $json['path'] ?? null,
        (string) $csv['sha256'],
        $id
    );
    Imports::queueInspect($importId, $userId);

    // Draft powstaje TERAZ, z samym odnośnikiem do importu. Zmienne dołożą się
    // dopiero, gdy cron policzy pokrycie — wcześniej nie ma z czego ich zbudować.
    \CoachAnalyze\Configurator::saveDraft($id, [
        'import_id' => $importId,
        'variables' => null,
        'sections'  => \CoachAnalyze\Configurator::SEKCJE,
    ]);

    redirect($powrot);
}

/** Edytor zmiennych — Sesja 3 (listing + podpowiedzi) i Sesja 4 (edycja) razem. */
function showConfiguratorDictionary(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $draft = \CoachAnalyze\Configurator::draft($id);
    if ($draft === null || empty($draft['import_id'])) {
        redirect('/klub/' . $id . '/konfigurator');
    }

    $import = Imports::find((int) $draft['import_id']);
    if ($import === null || empty($import['coverage_json'])) {
        redirect('/klub/' . $id . '/konfigurator');
    }

    $raport = Imports::report($import);
    $meta = $raport['coverage'];

    // Zmienne budujemy RAZ, przy pierwszym wejściu. Potem obowiązuje draft —
    // inaczej każde odświeżenie kasowałoby decyzje operatora i zastępowało je
    // podpowiedziami od nowa.
    if (!is_array($draft['variables'] ?? null)) {
        $draft['variables'] = \CoachAnalyze\Configurator::zmienneZeSlownika(
            $meta,
            new \CoachAnalyze\HeuristicSuggester(),
            $id,
            configuratorPalette($import),
            array_filter([$club['color_primary'] ?? null, $club['color_secondary'] ?? null])
        );
        \CoachAnalyze\Configurator::saveDraft($id, $draft);
    }

    View::page('configurator_slownik', [
        'title'      => View::t('conf.dict.title'),
        'active'     => 'clubs',
        'club'       => $club,
        'crumb'      => View::t('conf.crumb'),
        'import'     => $import,
        'coverage'   => $meta,
        'warnings'   => $raport['warnings'],
        'variables'  => $draft['variables'],
        'sections'   => array_values((array) ($draft['sections'] ?? \CoachAnalyze\Configurator::SEKCJE)),
        'concepts'   => \CoachAnalyze\Mappings::POJECIA,
        'qualifiers' => \CoachAnalyze\Mappings::KWALIFIKATORY,
        'notice'     => Session::flash('notice'),
        'error'      => Session::flash('error'),
    ]);
}

/**
 * Paleta z pliku projektu LiveTag — barwy domyślne zmiennych.
 *
 * DZIŚ ZWRACA PUSTO I TO JEST STAN ZNANY, NIE PRZEOCZENIE.
 *
 * Paletę liczy silnik (`prep_palette` + `to_hex` z korektą jasności) i NIE
 * przechodzi ona dziś przez `meta.json` — jest wyłącznie wejściem do ostrzeżeń
 * przy inspekcji. Warstwa żądań nie może jej policzyć sama: uruchomienie
 * silnika z PHP-FPM blokuje `disable_functions`, a przepisanie `to_hex` do PHP
 * byłoby przeniesieniem arytmetyki koloru do warstwy, która nie ma prawa nic
 * liczyć (pilnuje tego `test_4b.php`).
 *
 * Skutek jest widoczny i wytłumaczalny: bez palety zmienne dostają barwy klubu
 * (`Configurator::barwa`), czyli dokładnie ten fallback, który przewiduje
 * specyfikacja dla importu bez pliku projektu. Doprowadzenie palety wymaga
 * przeniesienia jej do `meta.json` — naturalnie razem z Sesją 5, która i tak
 * dokłada silnikowi wejście z templatu.
 *
 * Czytamy z `coverage_json`, żeby ta ścieżka zadziałała sama, gdy paleta się
 * tam pojawi — bez zmiany w tym miejscu.
 *
 * @return array<string,mixed>
 */
function configuratorPalette(array $import): array
{
    $meta = json_decode((string) ($import['coverage_json'] ?? ''), true);
    $paleta = is_array($meta) ? ($meta['palette'] ?? null) : null;
    return is_array($paleta) ? $paleta : [];
}

/** Zapis decyzji operatora do stanu roboczego. Bez zapisu templatu. */
function saveConfiguratorDraft(int $id): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $draft = \CoachAnalyze\Configurator::draft($id);
    if ($draft === null || !is_array($draft['variables'] ?? null)) {
        redirect('/klub/' . $id . '/konfigurator');
    }

    $draft['variables'] = configuratorVariablesFromPost($draft['variables']);
    $draft['sections'] = array_values(array_intersect(
        \CoachAnalyze\Configurator::SEKCJE,
        array_map('strval', (array) ($_POST['sections'] ?? []))
    ));

    \CoachAnalyze\Configurator::saveDraft($id, $draft);
    Session::flash('notice', View::t('conf.draft.saved'));
    redirect('/klub/' . $id . '/konfigurator/slownik');
}

/**
 * Decyzje z formularza nałożone na zmienne draftu.
 *
 * ŹRÓDŁO ZMIENNEJ NIE PRZYCHODZI Z ŻĄDANIA. `source` i `count` biorą się
 * wyłącznie z draftu — z żądania przyjmujemy tylko to, co operator faktycznie
 * edytuje. Inaczej podmiana pola ukrytego pozwoliłaby przypisać decyzję
 * do innego tagu, niż widać na ekranie.
 *
 * @param list<array<string,mixed>> $biezace
 * @return list<array<string,mixed>>
 */
function configuratorVariablesFromPost(array $biezace): array
{
    $canon    = (array) ($_POST['canon'] ?? []);
    $etykiety = (array) ($_POST['label'] ?? []);
    $barwy    = (array) ($_POST['color'] ?? []);
    $sekcje   = (array) ($_POST['vsections'] ?? []);
    $widoczne = (array) ($_POST['visible'] ?? []);
    $usuniete = array_flip(array_map('strval', (array) ($_POST['remove'] ?? [])));

    $out = [];
    foreach ($biezace as $z) {
        $vid = (string) ($z['id'] ?? '');
        if ($vid === '' || isset($usuniete[$vid])) {
            continue;   // „Usuń z templatu" — zmienna nie trafi do raportów.
        }

        $wybrane = (string) ($canon[$vid] ?? '');
        $dozwolone = \CoachAnalyze\Configurator::dozwoloneCanon(
            (string) ($z['source']['type'] ?? '')
        );
        $z['canon'] = in_array($wybrane, $dozwolone, true) ? $wybrane : null;

        $etykieta = trim((string) ($etykiety[$vid] ?? ''));
        $z['display_label'] = $etykieta !== '' ? $etykieta : (string) ($z['display_label'] ?? '');

        $z['color'] = View::color($barwy[$vid] ?? null, (string) ($z['color'] ?? '#8899AA'));

        $z['sections'] = array_values(array_intersect(
            \CoachAnalyze\Configurator::SEKCJE,
            array_map('strval', (array) ($sekcje[$vid] ?? []))
        ));

        $z['visible'] = !empty($widoczne[$vid]);

        $out[] = $z;
    }

    return $out;
}

/** Zapis templatu v1 (albo kolejnej wersji) — append-only przez ReportTemplates. */
function saveConfiguratorTemplate(int $id, int $userId): void
{
    $club = resolveTenant($id);
    if ($club === null) {
        tenantNotFound();
        return;
    }

    $draft = \CoachAnalyze\Configurator::draft($id);
    if ($draft === null || !is_array($draft['variables'] ?? null)) {
        redirect('/klub/' . $id . '/konfigurator');
    }

    // Decyzje z formularza obowiązują także przy zapisie: operator klika
    // „Zapisz templat" bez osobnego zapisywania draftu i ma prawo oczekiwać,
    // że zapisze się to, co widzi.
    $zmienne = configuratorVariablesFromPost($draft['variables']);
    $sekcje = array_values(array_intersect(
        \CoachAnalyze\Configurator::SEKCJE,
        array_map('strval', (array) ($_POST['sections'] ?? $draft['sections'] ?? []))
    ));

    $draft['variables'] = $zmienne;
    $draft['sections'] = $sekcje;
    \CoachAnalyze\Configurator::saveDraft($id, $draft);

    $config = \CoachAnalyze\Configurator::config($zmienne, $sekcje);
    $bledy = \CoachAnalyze\Configurator::bledyConfigu($config);

    if ($bledy !== []) {
        // Komunikaty z KLUCZY, nie sklejane z treści — zestaw jest zamknięty
        // i tłumaczy się w `pl.php` jak reszta interfejsu.
        Session::flash('error', implode(' ', array_map(
            static fn(string $klucz): string => View::t($klucz),
            $bledy
        )));
        redirect('/klub/' . $id . '/konfigurator/slownik');
    }

    $wersja = \CoachAnalyze\ReportTemplates::saveNewVersion($id, $config, $userId);

    \CoachAnalyze\Configurator::clearDraft($id);

    Session::flash('notice', View::t(
        'conf.saved',
        $wersja,
        count($zmienne),
        \CoachAnalyze\Configurator::podsumowanie($config)['canon'],
        count($sekcje)
    ));
    redirect('/klub/' . $id);
}

/** Porzucenie stanu roboczego. Import i mecz ZOSTAJĄ — skasowane byłyby utratą danych. */
function discardConfiguratorDraft(int $id): void
{
    if (resolveTenant($id) === null) {
        tenantNotFound();
        return;
    }

    \CoachAnalyze\Configurator::clearDraft($id);
    Session::flash('notice', View::t('conf.draft.discarded'));
    redirect('/klub/' . $id . '/konfigurator');
}

function showLogin(): void
{
    if (Auth::isLoggedIn()) {
        redirect('/');
    }
    View::page('login', [
        'title'   => View::t('login.title'),
        'chrome'  => false,
        'csrf'    => Session::csrfToken(),
        'notice'  => Session::flash('notice'),
        'error'   => Session::flash('error'),
        // Uprzedzamy o niemożliwym logowaniu ZANIM ktoś wpisze hasło, jeśli
        // przyczynę widać już przy pokazywaniu formularza.
        'warning' => loginCookieWarning(),
        'email'   => '',
    ]);
}

// ------------------------------------------------------------- reset hasła

function showForgotPassword(): void
{
    if (Auth::isLoggedIn()) {
        redirect('/konto');
    }
    View::page('password_forgot', [
        'title'   => View::t('reset.title'),
        'chrome'  => false,
        'csrf'    => Session::csrfToken(),
        'notice'  => Session::flash('notice'),
        'error'   => Session::flash('error'),
        'warning' => loginCookieWarning(),
    ]);
}

/**
 * Formularz prośby o reset z komunikatem W TEJ SAMEJ ODPOWIEDZI.
 *
 * Osobno od `showForgotPassword()`, bo tamten niesie komunikat przez `flash`,
 * czyli PRZEZ SESJĘ. Przy braku sesji — a to jedyny przypadek, w którym tu
 * trafiamy — komunikat przepadłby po drodze i użytkownik zobaczyłby czysty
 * formularz bez słowa wyjaśnienia. Cicha pętla zamiast błędu.
 */
function renderForgotPassword(string $error): void
{
    http_response_code(400);
    View::page('password_forgot', [
        'title'  => View::t('reset.title'),
        'chrome' => false,
        'csrf'   => Session::csrfToken(),
        'error'  => $error,
    ]);
}

/**
 * Prośba o reset. ODPOWIEDŹ IDENTYCZNA dla adresu istniejącego
 * i nieistniejącego — ten sam komunikat, to samo przekierowanie, ta sama
 * praca po drodze (zawsze limit + zawsze zadanie w kolejce). Czy konto
 * istnieje, rozstrzyga dopiero proces roboczy, poza pomiarem czasu.
 */
function handleForgotPassword(): void
{
    $powod = Session::csrfProblem($_POST['csrf'] ?? null);
    if ($powod !== Session::CSRF_OK) {
        Audit::log(Audit::CSRF_FAIL, null, 'user');
        if ($powod === Session::CSRF_MISMATCH) {
            // Sesja żyje, więc komunikat przetrwa przekierowanie.
            Session::flash('error', csrfMessage($powod));
            redirect('/haslo/zapomniane');
        }
        renderForgotPassword(csrfMessage($powod));
        return;
    }

    $email = trim((string) ($_POST['email'] ?? ''));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        // Błąd FORMATU wolno pokazać — nie mówi nic o istnieniu konta.
        Session::flash('error', View::t('reset.err.email'));
        redirect('/haslo/zapomniane');
    }

    $limiter = new \CoachAnalyze\RateLimit();
    try {
        // Limit liczony od KAŻDEJ prośby, także dla adresu spoza systemu —
        // inaczej licznik zdradzałby istnienie konta.
        if ($limiter->checkReset($email) > 0) {
            Session::flash('error', View::t('reset.err.rate'));
            redirect('/haslo/zapomniane');
        }
        $limiter->registerReset($email);
    } catch (\RuntimeException $e) {
        // Fail closed jak logowanie: bez licznika nie przyjmujemy prośby.
        error_log('reset hasła: limit niedostępny — ' . $e->getMessage());
        Session::flash('error', View::t('reset.err.unavailable'));
        redirect('/haslo/zapomniane');
    }

    \CoachAnalyze\PasswordReset::queueRequest($email);

    Session::flash('notice', View::t('reset.sent'));
    redirect('/login');
}

function showPasswordReset(string $token): void
{
    $reset = \CoachAnalyze\PasswordReset::findValid($token);
    if ($reset === null) {
        // Zły, zużyty i wygasły token wyglądają IDENTYCZNIE.
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('reset.title'),
            'heading' => View::t('reset.err.invalid.heading'),
            'body'    => View::t('reset.err.invalid'),
        ]);
        return;
    }

    renderPasswordReset($token, (string) $reset['email'], Session::flash('error'), 200);
}

/** @param string|null $error gotowy komunikat albo null */
function renderPasswordReset(string $token, string $email, $error, int $status): void
{
    http_response_code($status);
    View::page('password_reset', [
        'title'     => View::t('reset.new.title'),
        'chrome'    => false,
        'csrf'      => Session::csrfToken(),
        'token'     => $token,
        'email'     => $email,
        'minLength' => Auth::minPasswordLength(),
        'error'     => is_string($error) ? $error : null,
    ]);
}

function handlePasswordReset(string $token): void
{
    // Token weryfikowany PONOWNIE przy zapisie — między pokazaniem formularza
    // a wysłaniem mógł zostać użyty albo wygasnąć.
    $reset = \CoachAnalyze\PasswordReset::findValid($token);
    if ($reset === null) {
        http_response_code(404);
        View::page('soon', [
            'title'   => View::t('reset.title'),
            'heading' => View::t('reset.err.invalid.heading'),
            'body'    => View::t('reset.err.invalid'),
        ]);
        return;
    }

    $powod = Session::csrfProblem($_POST['csrf'] ?? null);
    if ($powod !== Session::CSRF_OK) {
        Audit::log(Audit::CSRF_FAIL, null, 'user');
        if ($powod === Session::CSRF_MISMATCH) {
            Session::flash('error', csrfMessage($powod));
            redirect('/haslo/reset/' . $token);
        }
        // Bez sesji `flash` nie przeniesie komunikatu przez przekierowanie —
        // rysujemy formularz od razu. Token jest już zweryfikowany wyżej.
        renderPasswordReset($token, (string) $reset['email'], csrfMessage($powod), 400);
        return;
    }

    $nowe = (string) ($_POST['nowe'] ?? '');
    if (mb_strlen($nowe, 'UTF-8') < Auth::minPasswordLength()) {
        Session::flash('error', View::t('reset.err.short', Auth::minPasswordLength()));
        redirect('/haslo/reset/' . $token);
    }

    \CoachAnalyze\PasswordReset::complete($reset, $nowe);

    Session::flash('notice', View::t('reset.done'));
    redirect('/login');
}

function handleLogin(): void
{
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $powod = Session::csrfProblem($_POST['csrf'] ?? null);
    if ($powod !== Session::CSRF_OK) {
        Audit::log(Audit::CSRF_FAIL, null, 'user');
        renderLogin($email, csrfMessage($powod));
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
