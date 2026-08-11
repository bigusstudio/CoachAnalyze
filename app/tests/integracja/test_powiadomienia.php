<?php
declare(strict_types=1);

/**
 * Raporty, powiadomienia w aplikacji i warstwa mailowa.
 *
 * Uruchomienie:  php test_powiadomienia.php
 */

use CoachAnalyze\Config;
use CoachAnalyze\Db;
use CoachAnalyze\Jobs;
use CoachAnalyze\Mailer;
use CoachAnalyze\Matches;
use CoachAnalyze\Notifications;
use CoachAnalyze\Reports;
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
$baza    = $here . '/powiadomienia.sqlite';
$envFile = $here . '/.env.powiadomienia';
@unlink($baza);

file_put_contents($envFile, implode("\n", [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $baza,
    'STORAGE_PATH=' . $here,
    'APP_URL=https://app.example.test',
    'SESSION_NAME=ca_test',
    // SMTP celowo PUSTY — sprawdzamy, że warstwa mailowa wyłącza się po cichu.
    'SMTP_HOST=',
    'MAIL_FROM=',
    '',
]));
putenv('CA_ENV_PATH=' . $envFile);

require $root . '/app/src/bootstrap.php';
require $here . '/seed.php';

// Bez danych przykladowych — wstawiamy wlasne, o znanych identyfikatorach.
ca_test_db($baza, false);

// ---------------------------------------------------------------- dane
$teraz = Stats::now();
// Uzytkownik id=1 powstaje w seed.php zawsze — podmieniamy tylko adres.
Db::run("INSERT INTO clubs (id, owner_id, club_key, name, color_primary, is_own_team, created_at)
         VALUES (1, 1, 'HUT7K2QX', 'Klub A', '#E8722C', 1, :t)", ['t' => $teraz]);
Db::run("INSERT INTO clubs (id, owner_id, club_key, name, color_primary, is_own_team, created_at)
         VALUES (2, 1, 'POG3M9RT', 'Klub B', '#2C6FE8', 0, :t)", ['t' => $teraz]);
Db::run("INSERT INTO seasons (id, owner_id, label, is_current)
         VALUES (1, 1, '2025/2026', 1)");

Db::run("INSERT INTO matches (id, owner_id, season_id, club_home_id, club_away_id, played_at, status, created_at)
         VALUES (1, 1, 1, 1, 2, '2026-03-14', 'done', :t)", ['t' => $teraz]);
Db::run("INSERT INTO matches (id, owner_id, season_id, club_home_id, club_away_id, played_at, status, created_at)
         VALUES (2, 1, 1, 1, 2, '2026-04-02', 'queued', :t)", ['t' => $teraz]);

// Dwa raporty dla meczu 1 — regeneracja. Starszy zostaje.
Db::run("INSERT INTO reports (id, match_id, html_path, engine_version, generated_at)
         VALUES (1, 1, '/x/a.html', '0.8.0', '2026-03-15 10:00:00')");
Db::run("INSERT INTO reports (id, match_id, html_path, engine_version, generated_at)
         VALUES (2, 1, '/x/b.html', '0.8.1', '2026-03-16 11:00:00')");
Db::run("INSERT INTO reports (id, match_id, html_path, engine_version, generated_at)
         VALUES (3, 2, '/x/c.html', '0.8.1', '2026-04-03 09:00:00')");

Db::run("INSERT INTO share_links (id, report_id, club_id, token, created_at, views)
         VALUES (1, 2, 1, 'tok_aktywny_000000000000000000', :t, 7)", ['t' => $teraz]);
Db::run("INSERT INTO share_links (id, report_id, club_id, token, created_at, revoked_at, views)
         VALUES (2, 1, 1, 'tok_odwolany_00000000000000000', :t, :t, 3)", ['t' => $teraz]);
Db::run("INSERT INTO share_links (id, report_id, club_id, token, created_at, expires_at, views)
         VALUES (3, 3, 1, 'tok_wygasly_000000000000000000', :t, '2020-01-01 00:00:00', 2)", ['t' => $teraz]);

Db::run("INSERT INTO imports (id, match_id, csv_path, checksum_csv, created_at)
         VALUES (1, 1, '/x/a.csv', 'abc', :t)", ['t' => $teraz]);

// ================================================================ RAPORTY
echo "== lista raportów ==\n";

$wynik = Reports::search([]);
check('widać wszystkie raporty', $wynik['total'] === 3, (string) $wynik['total']);
check('domyślnie od najnowszych', (int) $wynik['rows'][0]['id'] === 3, (string) $wynik['rows'][0]['id']);

$wgId = [];
foreach ($wynik['rows'] as $r) {
    $wgId[(int) $r['id']] = $r;
}

check('nazwy drużyn dołączone', $wgId[2]['home_name'] === 'Klub A' && $wgId[2]['away_name'] === 'Klub B');
check('sezon dołączony', $wgId[2]['season_label'] === '2025/2026');
check('wersja silnika z raportu', $wgId[1]['engine_version'] === '0.8.0');

echo "\n== stan linku publicznego ==\n";
check('aktywny', $wgId[2]['link_stan'] === 'active', (string) $wgId[2]['link_stan']);
check('odwołany', $wgId[1]['link_stan'] === 'revoked', (string) $wgId[1]['link_stan']);
check('wygasły', $wgId[3]['link_stan'] === 'expired', (string) $wgId[3]['link_stan']);
check('liczba wejść', (int) $wgId[2]['views'] === 7, (string) $wgId[2]['views']);

// Raport bez żadnego linku musi mieć stan 'none', a nie pustkę.
Db::run("INSERT INTO reports (id, match_id, html_path, engine_version, generated_at)
         VALUES (4, 2, '/x/d.html', '0.8.1', '2026-04-04 09:00:00')");
$bezLinku = Reports::search([])['rows'];
$czwarty = null;
foreach ($bezLinku as $r) {
    if ((int) $r['id'] === 4) { $czwarty = $r; }
}
check('brak linku to osobny stan, nie pustka', $czwarty !== null && $czwarty['link_stan'] === 'none');

echo "\n== najnowszy raport meczu ==\n";
$po = Reports::search([]);
$wgId = [];
foreach ($po['rows'] as $r) { $wgId[(int) $r['id']] = $r; }
check('raport 2 oznaczony jako najnowszy dla meczu 1', $wgId[2]['is_latest'] === true);
check('raport 1 NIE jest najnowszy', $wgId[1]['is_latest'] === false);
check('liczba raportów meczu policzona', (int) $wgId[1]['siblings'] === 2, (string) $wgId[1]['siblings']);
check('starszy raport nadal jest na liście', isset($wgId[1]));

echo "\n== filtry i stronicowanie ==\n";
check('filtr po sezonie', Reports::search(['season' => 1])['total'] === 4);
check('filtr po nieistniejącym sezonie daje pustkę', Reports::search(['season' => 99])['total'] === 0);
check('filtr po klubie łapie obie strony', Reports::search(['club' => 2])['total'] === 4);
check('sortowanie rosnąco', (int) Reports::search(['sort' => 'date_asc'])['rows'][0]['id'] === 1);
check('nieznane sortowanie wraca do domyślnego', Reports::normalizeSort('DROP TABLE') === 'date_desc');
check('numer strony przycięty do zakresu', Reports::search(['page' => 999])['page'] === 1);

// ================================================================ POWIADOMIENIA
echo "\n== powiadomienia w aplikacji ==\n";

check('na start licznik zerowy', Notifications::unreadCount(1) === 0);

$n1 = Notifications::create(1, [
    'type'      => Notifications::TYP_READY,
    'title'     => 'Raport gotowy: Klub A — Klub B',
    'entity'    => 'report',
    'entity_id' => 2,
    'url'       => '/raport/2',
]);
check('powiadomienie zapisane', $n1 !== null);
check('licznik nieodczytanych rośnie', Notifications::unreadCount(1) === 1);

$zapis = Notifications::find((int) $n1);
check('treść zachowana', $zapis['title'] === 'Raport gotowy: Klub A — Klub B');
check('bez SMTP mail ma stan „nie wysyłamy"', $zapis['mail_status'] === 'none', (string) $zapis['mail_status']);
check('bez SMTP nie powstaje zadanie wysyłki',
    Db::one("SELECT COUNT(*) AS ile FROM jobs WHERE type = 'send_mail'")['ile'] == 0);

Notifications::create(1, ['type' => Notifications::TYP_FAILED, 'title' => 'Awaria']);
check('licznik liczy oba', Notifications::unreadCount(1) === 2);

$lista = Notifications::forUser(1);
check('lista od najnowszych', $lista[0]['title'] === 'Awaria', (string) $lista[0]['title']);

check('oznaczenie odczytu zwraca liczbę', Notifications::markAllRead(1) === 2);
check('licznik wyzerowany po wejściu', Notifications::unreadCount(1) === 0);
check('powiadomienia zostają na liście', count(Notifications::forUser(1)) === 2);

// ================================================================ WARSTWA MAILOWA
echo "\n== warstwa mailowa wyłącza się po cichu ==\n";
check('bez konfiguracji Mailer jest nieaktywny', Mailer::isConfigured() === false);

// Włączamy SMTP i sprawdzamy, że powiadomienia zaczynają kolejkować wysyłkę.
// `Config::reset()` przyjmuje gotowe wartosci — konfiguracja w produkcji
// czyta plik raz i ten szew nie ma tego zmieniac.
Config::reset([
    'APP_ENV' => 'test', 'DB_DRIVER' => 'sqlite', 'DB_PATH' => $baza,
    'STORAGE_PATH' => $here, 'APP_URL' => 'https://app.example.test',
    'SESSION_NAME' => 'ca_test',
    'SMTP_HOST' => 'poczta.example.test', 'SMTP_PORT' => '587',
    'MAIL_FROM' => 'raporty@example.test',
]);
check('po uzupełnieniu .env Mailer jest aktywny', Mailer::isConfigured() === true);

Db::run("UPDATE users SET email = 'trener@example.test' WHERE id = 1");

$n3 = Notifications::create(1, [
    'type'      => Notifications::TYP_READY,
    'title'     => 'Raport gotowy',
    'entity'    => 'report',
    'entity_id' => 2,
    'url'       => '/raport/2',
]);
$zapis3 = Notifications::find((int) $n3);
check('mail zaplanowany', $zapis3['mail_status'] === 'pending', (string) $zapis3['mail_status']);
check('adres zapamiętany z chwili powstania', $zapis3['mail_to'] === 'trener@example.test');

$zadanie = Db::one("SELECT * FROM jobs WHERE type = 'send_mail' ORDER BY id DESC LIMIT 1");
check('powstało zadanie wysyłki', $zadanie !== null);
$payload = json_decode((string) $zadanie['payload_json'], true);
check('zadanie wskazuje powiadomienie', (int) $payload['notification_id'] === (int) $n3);
check('bez zwłoki zadanie jest dostępne od razu', $zadanie['available_at'] === null);

echo "\n== przełącznik użytkownika wyłącza mail ==\n";
Notifications::savePreferences(1, [
    Notifications::TYP_READY   => false,
    Notifications::TYP_PENDING => true,
    Notifications::TYP_FAILED  => true,
]);
$user = Db::one('SELECT * FROM users WHERE id = 1');
$prefs = Notifications::preferences($user);
check('preferencja zapisana', $prefs[Notifications::TYP_READY] === false);
check('pozostałe nietknięte', $prefs[Notifications::TYP_FAILED] === true);

$n4 = Notifications::create(1, ['type' => Notifications::TYP_READY, 'title' => 'Kolejny raport']);
$zapis4 = Notifications::find((int) $n4);
check('wyłączony typ nie idzie mailem', $zapis4['mail_status'] === 'none', (string) $zapis4['mail_status']);
check('ale powiadomienie w panelu POWSTAJE', $zapis4 !== null && $zapis4['title'] === 'Kolejny raport');

echo "\n== zwloka maila o przetwarzaniu w toku ==\n";
Notifications::savePreferences(1, [
    Notifications::TYP_READY   => true,
    Notifications::TYP_PENDING => true,
    Notifications::TYP_FAILED  => true,
]);

$n5 = Notifications::create(1, [
    'type'      => Notifications::TYP_PENDING,
    'title'     => 'Import wgrany, przetwarzanie w toku',
    'entity'    => 'match',
    'entity_id' => 2,          // mecz w stanie `queued`
    'delay'     => Notifications::OPOZNIENIE_PENDING,
]);
$zapis5 = Notifications::find((int) $n5);
check('zwłoka zapisana', $zapis5['send_after'] !== null);
check('zwłoka to dwie minuty',
    abs(strtotime((string) $zapis5['send_after']) - strtotime(Stats::now()) - 120) <= 2,
    (string) $zapis5['send_after']);

$zad5 = Db::one("SELECT * FROM jobs WHERE type = 'send_mail' ORDER BY id DESC LIMIT 1");
check('zadanie wysyłki czeka na swój termin', $zad5['available_at'] !== null);

// Sedno wymagania: po dwóch minutach sprawdzamy stan PONOWNIE.
check('dopóki mecz się przetwarza, powód obowiązuje', Notifications::stillRelevant($zapis5) === true);

Db::run("UPDATE matches SET status = 'done' WHERE id = 2");
check('gdy raport zdążył powstać, mail zostaje odwołany',
    Notifications::stillRelevant($zapis5) === false);

Notifications::markSkipped((int) $n5, 'Powód zdezaktualizował się przed wysyłką.');
check('stan „odwołany" różni się od „nie wysyłamy"',
    Notifications::find((int) $n5)['mail_status'] === 'skipped');

// Powiadomienia innych typów nie podlegają tej regule.
check('„raport gotowy" jest zawsze aktualny',
    Notifications::stillRelevant(Notifications::find((int) $n3)) === true);

echo "\n== nieudana wysyłka nie przewraca udanego zadania ==\n";

// Zadanie renderu, które SIĘ POWIODŁO.
Db::run("INSERT INTO jobs (id, type, payload_json, status, exit_code, created_at, finished_at)
         VALUES (900, 'build_report', :p, 'done', 0, :t, :t)",
    ['p' => json_encode(['import_id' => 1, 'match_id' => 1]), 't' => Stats::now()]);

Notifications::markFailed((int) $n3, 'Serwer poczty odrzucił polecenie (kod 550).');

$render = Db::one('SELECT * FROM jobs WHERE id = 900');
check('zadanie renderu nadal ma status done', $render['status'] === 'done');
check('kod wyjścia renderu nietknięty', (int) $render['exit_code'] === 0);
check('awaria maila zapisana przy powiadomieniu', Notifications::find((int) $n3)['mail_status'] === 'failed');
check('powód awarii po polsku, bez rozmowy SMTP',
    str_contains((string) Notifications::find((int) $n3)['mail_error'], 'odrzucił polecenie'));
check('licznik prób podbity', (int) Notifications::find((int) $n3)['mail_attempts'] === 1);

// ================================================================ PONAWIANIE
echo "\n== polityka ponawiania wysylki ==\n";

check('pierwsza awaria: kolejna proba za minute', Notifications::retryDelay(0) === 60);
check('druga: za 5 minut',  Notifications::retryDelay(1) === 300);
check('trzecia: za 15 minut', Notifications::retryDelay(2) === 900);
check('czwarta: za godzine', Notifications::retryDelay(3) === 3600);
check('piata konczy sprawe', Notifications::retryDelay(4) === null);
check('powyzej limitu tez konczy', Notifications::retryDelay(99) === null);

$rosna = true;
$poprzedni = 0;
foreach ([0, 1, 2, 3] as $i) {
    $d = (int) Notifications::retryDelay($i);
    if ($d <= $poprzedni) { $rosna = false; }
    $poprzedni = $d;
}
check('odstepy rosna, nie stoja w miejscu', $rosna,
    'powtarzanie co minute pod przeciazony serwer pogarsza sprawe');

echo "\n== nieudana proba odklada zadanie, nie przewraca go ==\n";

Db::run("INSERT INTO jobs (id, type, payload_json, status, attempts, created_at)
         VALUES (910, 'send_mail', :p, 'running', 1, :t)",
    ['p' => json_encode(['notification_id' => (int) $n3]), 't' => Stats::now()]);

Jobs::requeueLater(910, 60, "Próba 1 z 5 nie powiodła się, kolejna za 1 min.\n\nSerwer nie odpowiedział.");

$zad = Db::one('SELECT * FROM jobs WHERE id = 910');
check('zadanie WROCILO do kolejki, nie padlo', $zad['status'] === 'queued', (string) $zad['status']);
check('ma wyznaczony termin kolejnej proby', $zad['available_at'] !== null);
check('termin to okolo minuta',
    abs(strtotime((string) $zad['available_at']) - strtotime(Stats::now()) - 60) <= 3,
    (string) $zad['available_at']);
check('powod widoczny juz teraz, nie po piaciu probach',
    str_contains((string) $zad['error_text'], 'nie odpowiedział'), (string) $zad['error_text']);
check('komunikat mowi, ktora to proba',
    str_contains((string) $zad['error_text'], 'Próba 1 z 5'), (string) $zad['error_text']);
check('kod wyjscia wyczyszczony — zadanie nie jest nieudane', $zad['exit_code'] === null);

echo "\n== powiadomienie zostaje pending miedzy probami ==\n";
Db::run("UPDATE notifications SET mail_status = 'pending', mail_attempts = 0, mail_error = NULL
          WHERE id = :id", ['id' => (int) $n3]);
Notifications::markRetry((int) $n3, 'Serwer poczty nie odpowiedział.');

$pow = Notifications::find((int) $n3);
check('stan NADAL pending — inaczej kolejna proba nic by nie zrobila',
    $pow['mail_status'] === 'pending', (string) $pow['mail_status']);
check('licznik prob podbity', (int) $pow['mail_attempts'] === 1);
check('powod zapisany', str_contains((string) $pow['mail_error'], 'nie odpowiedział'));

echo "\n== dopiero wyczerpanie prob konczy sprawe ==\n";
Notifications::markFailed((int) $n3, 'Serwer poczty odrzucił polecenie (kod 550).');
check('powiadomienie dopiero TERAZ dostaje failed',
    Notifications::find((int) $n3)['mail_status'] === 'failed');

echo "\n== awaria poczty nie rusza raportu ==\n";
$render = Db::one('SELECT * FROM jobs WHERE id = 900');
check('zadanie renderu nadal done', $render['status'] === 'done');
check('kod wyjscia renderu nadal 0', (int) $render['exit_code'] === 0);
check('raporty na miejscu',
    (int) Db::one('SELECT COUNT(*) AS ile FROM reports WHERE match_id = 1')['ile'] > 0);

echo "\n== reczne ponowienie czysci termin oczekiwania ==\n";
Db::run("UPDATE jobs SET status = 'failed', available_at = :k WHERE id = 910",
    ['k' => Stats::now('+3600 seconds')]);
check('Jobs::retry przyjmuje nieudane zadanie', Jobs::retry(910) === true);
$zad = Db::one('SELECT * FROM jobs WHERE id = 910');
check('available_at wyczyszczone — proba ma byc TERAZ', $zad['available_at'] === null);
check('zadanie znow w kolejce', $zad['status'] === 'queued');

// ================================================================ CHMURKI
echo "\n== punkt koncowy chmurek: wylacznie wlasne powiadomienia ==\n";

// Drugi uzytkownik z wlasnym powiadomieniem — identyfikatory sa kolejnymi
// liczbami, wiec zgadniecie cudzego jest darmowe.
Db::run("INSERT INTO users (id, email, pass_hash, display_name, created_at)
         VALUES (77, 'obcy@example.test', 'x', 'Obcy', :t)", ['t' => Stats::now()]);
$obce = Notifications::create(77, [
    'type' => Notifications::TYP_READY, 'title' => 'SEKRET CUDZEGO KONTA',
]);

Db::run("UPDATE notifications SET read_at = NULL WHERE user_id = 1");
$moje = Notifications::unreadForToasts(1);
$tytuly = array_column($moje, 'title');

check('widac wlasne powiadomienia', count($moje) > 0);
check('NIE widac cudzych', !in_array('SEKRET CUDZEGO KONTA', $tytuly, true),
    implode(' | ', $tytuly));
check('cudze widoczne na swoim koncie',
    in_array('SEKRET CUDZEGO KONTA', array_column(Notifications::unreadForToasts(77), 'title'), true));

echo "\n== oznaczanie odczytu ma filtr konta ==\n";
check('nie da sie oznaczyc cudzego powiadomienia',
    Notifications::markRead((int) $obce, 1) === false);
check('cudze nadal nieodczytane',
    Notifications::find((int) $obce)['read_at'] === null);
check('wlasne da sie oznaczyc',
    Notifications::markRead((int) $moje[0]['id'], 1) === true);
check('powtorne oznaczenie nie udaje sukcesu',
    Notifications::markRead((int) $moje[0]['id'], 1) === false);

echo "\n== odmiany chmurek ==\n";
check('raport gotowy to odmiana „gotowe”',
    Notifications::kind(Notifications::TYP_READY) === 'ready');
check('awaria to odmiana „blad”',
    Notifications::kind(Notifications::TYP_FAILED) === 'failed');
check('przetwarzanie to odmiana „w toku”',
    Notifications::kind(Notifications::TYP_PENDING) === 'pending');
check('nieznany typ sprowadzony do „w toku”, nie do pustki',
    Notifications::kind('cokolwiek.innego') === 'pending');

echo "\n== podpowiedz o odstepie odpytywania ==\n";
Db::run("UPDATE matches SET status = 'done'");
check('bez zadan w toku: brak podpowiedzi', Notifications::hasActiveWork(1) === false);
Db::run("UPDATE matches SET status = 'running' WHERE id = 1");
check('z zadaniem w toku: podpowiedz wlaczona', Notifications::hasActiveWork(1) === true);
check('cudze zadania nie wlaczaja podpowiedzi u innych',
    Notifications::hasActiveWork(77) === false);

// ================================================================ USTERKI Z PRODUKCJI
echo "\n== powiadomienie o raporcie nie moze wskazywac zera ==\n";

// USTERKA: pierwszy mail z produkcji prowadzil do /raport/0.
$zerowe = Notifications::create(1, [
    'type' => Notifications::TYP_READY, 'title' => 'Raport gotowy',
    'entity' => 'report', 'entity_id' => 0, 'url' => '/raport/0',
]);
check('powiadomienie z identyfikatorem 0 NIE powstaje', $zerowe === null);

$puste = Notifications::create(1, [
    'type' => Notifications::TYP_READY, 'title' => 'Raport gotowy',
    'entity' => 'report', 'entity_id' => null, 'url' => '/raport/',
]);
check('powiadomienie z pustym identyfikatorem NIE powstaje', $puste === null);

$ujemne = Notifications::create(1, [
    'type' => Notifications::TYP_READY, 'title' => 'Raport gotowy',
    'entity' => 'report', 'entity_id' => -3, 'url' => '/raport/-3',
]);
check('ujemny identyfikator tez odrzucony', $ujemne === null);

$zeroWAdresie = Notifications::create(1, [
    'type' => Notifications::TYP_READY, 'title' => 'Raport gotowy',
    'entity' => 'report', 'entity_id' => 5, 'url' => '/raport/0',
]);
check('zero w SAMYM ADRESIE tez odrzucone', $zeroWAdresie === null,
    'identyfikator moze byc poprawny, a adres i tak zepsuty');

$dobre = Notifications::create(1, [
    'type' => Notifications::TYP_READY, 'title' => 'Raport gotowy',
    'entity' => 'report', 'entity_id' => 7, 'url' => '/raport/7',
]);
check('prawidlowe powiadomienie powstaje normalnie', $dobre !== null);
check('adres zachowany', Notifications::find((int) $dobre)['url'] === '/raport/7');

// Zadania i importy tez wskazuja konkretny wiersz.
check('powiadomienie o zadaniu z zerem odrzucone',
    Notifications::create(1, [
        'type' => Notifications::TYP_FAILED, 'title' => 'Awaria',
        'entity' => 'job', 'entity_id' => 0, 'url' => '/zadania/0',
    ]) === null);

// Powiadomienie bez encji (np. ogolny komunikat) ma dzialac dalej.
check('powiadomienie bez encji nadal dziala',
    Notifications::create(1, ['type' => Notifications::TYP_FAILED, 'title' => 'Ogolne']) !== null);

// ================================================================ HISTORIA
echo "\n== historia zdarzeń meczu ==\n";

Db::run("UPDATE imports SET coverage_json = '{\"events\":10}', engine_version = '0.8.1' WHERE id = 1");
Db::run("INSERT INTO jobs (id, type, payload_json, status, error_text, created_at, finished_at)
         VALUES (901, 'build_report', :p, 'failed', 'Silnik nie odczytał pliku.\nlinia druga', :t, :t)",
    ['p' => json_encode(['import_id' => 1]), 't' => '2026-03-15 09:00:00']);

$historia = Matches::history(1);
$rodzaje = array_column($historia, 'kind');

check('wgranie eksportu w historii', in_array('import', $rodzaje, true));
check('pokrycie w historii', in_array('coverage', $rodzaje, true));
check('wygenerowanie raportu w historii', in_array('report', $rodzaje, true));
check('udostępnienie w historii', in_array('share', $rodzaje, true));
check('odwołanie linku jako osobne zdarzenie', in_array('share_revoked', $rodzaje, true));
check('nieudane przetwarzanie w historii', in_array('failed', $rodzaje, true));

$posortowane = array_column($historia, 'at');
$kopia = $posortowane;
sort($kopia);
check('zdarzenia ułożone w czasie', $posortowane === $kopia);

foreach ($historia as $z) {
    if ($z['kind'] === 'failed') {
        check('powód awarii skrócony do jednej linii', !str_contains((string) $z['detail'], "\n"));
        break;
    }
}

check('mecz bez zdarzeń daje pustą historię, nie błąd', Matches::history(999) === []);

// ================================================================ TEKSTY
echo "\n== teksty interfejsu ==\n";

$brakujace = [];
foreach ([
    'nav.reports', 'nav.notifications', 'reports.title', 'reports.empty',
    'reports.act.regen', 'reports.latest', 'reports.older',
    'link.status.none', 'link.status.active', 'link.status.expired', 'link.status.revoked',
    'notif.title', 'notif.empty', 'notif.unread',
    'notif.mail.none', 'notif.mail.pending', 'notif.mail.sent',
    'notif.mail.failed', 'notif.mail.skipped',
    'notif.prefs.title', 'notif.prefs.no_smtp', 'notif.prefs.saved',
    'notif.prefs.import.pending', 'notif.prefs.report.ready', 'notif.prefs.report.failed',
    'history.title', 'history.empty',
    'history.kind.import', 'history.kind.coverage', 'history.kind.report',
    'history.kind.share', 'history.kind.share_revoked', 'history.kind.failed',
    'job.done', 'job.done.report', 'job.done.open',
] as $klucz) {
    if (View::t($klucz) === $klucz) {
        $brakujace[] = $klucz;
    }
}
check('wszystkie klucze mają polskie teksty', $brakujace === [], implode(', ', $brakujace));

// ---------------------------------------------------------------- sprzątanie
@unlink($baza);
@unlink($envFile);

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
