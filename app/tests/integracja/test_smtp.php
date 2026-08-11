<?php
declare(strict_types=1);

/**
 * Klient SMTP — rozmowa z atrapą serwera.
 *
 * To jedyny test, który wykonuje protokół napisany ręcznie. Bez niego wiadomo
 * tylko tyle, że kod się parsuje.
 *
 * Uruchomienie:  php test_smtp.php
 */

use CoachAnalyze\Config;
use CoachAnalyze\Mailer;

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

require $root . '/app/src/bootstrap.php';

Config::reset(['APP_ENV' => 'test', 'APP_URL' => 'https://app.example.test']);

/** Uruchomienie atrapy i odczekanie, aż zacznie nasłuchiwać. */
function atrapa(int $port, string $log, string $tryb = 'ok', string $pem = ''): void
{
    $cmd = sprintf(
        'php %s %d %s %s %s > /dev/null 2>&1 &',
        escapeshellarg(__DIR__ . '/fake_smtp.php'),
        $port,
        escapeshellarg($log),
        escapeshellarg($tryb),
        escapeshellarg($pem)
    );
    @unlink($log . '.ready');
    exec($cmd);

    // Czekamy na ZNACZNIK, nie na udane połączenie: atrapa obsługuje jedno
    // połączenie i kończy, więc sondowanie zjadałoby to właściwe.
    for ($i = 0; $i < 200; $i++) {
        if (is_file($log . '.ready')) {
            return;
        }
        usleep(25000);
    }
}

/**
 * Zapis rozmowy, gdy atrapa zdazy go odlozyc.
 *
 * `send()` wraca zaraz po QUIT, a atrapa zapisuje log dopiero po zamknieciu
 * polaczenia — bez tego czekania test czytalby pusty plik i zglaszal blad
 * w kodzie, ktory dziala poprawnie.
 */
function rozmowa(string $log): string
{
    for ($i = 0; $i < 200; $i++) {
        $tresc = is_file($log) ? (string) file_get_contents($log) : '';
        if (trim($tresc) !== '') {
            return $tresc;
        }
        usleep(25000);
    }
    return '';
}

// ---------------------------------------------------------------- wysyłka udana
echo "== poprawna wysyłka ==\n";

$log = $here . '/smtp_ok.log';
atrapa(2531, $log, 'ok');

$mailer = new Mailer('127.0.0.1', 2531, null, null, 'raporty@example.test', 'CoachAnalyze', 5.0);

$blad = null;
try {
    $mailer->send('trener@example.test', 'Raport gotowy: Klub Ą — Klub B', "Treść po polsku.\nDruga linia.");
} catch (\Throwable $e) {
    $blad = $e->getMessage();
}

check('wysyłka bez wyjątku', $blad === null, (string) $blad);

$rozmowa = rozmowa($log);
check('atrapa zapisała rozmowę', $rozmowa !== '');
check('klient przedstawił się przez EHLO', str_contains($rozmowa, 'EHLO'));
check('nazwa w EHLO wzięta z APP_URL', str_contains($rozmowa, 'EHLO app.example.test'), $rozmowa);
check('koperta: nadawca', str_contains($rozmowa, 'MAIL FROM:<raporty@example.test>'));
check('koperta: odbiorca', str_contains($rozmowa, 'RCPT TO:<trener@example.test>'));
check('treść wysłana komendą DATA', str_contains($rozmowa, 'DATA'));
check('rozmowa domknięta przez QUIT', str_contains($rozmowa, 'QUIT'));

echo "\n== nagłówki wiadomości ==\n";
check('nagłówek From z nazwą', str_contains($rozmowa, 'From: CoachAnalyze <raporty@example.test>'));
check('kodowanie znaków zadeklarowane', str_contains($rozmowa, 'Content-Type: text/plain; charset=utf-8'));
check('treść w base64', str_contains($rozmowa, 'Content-Transfer-Encoding: base64'));
check('oznaczone jako automatyczne', str_contains($rozmowa, 'Auto-Submitted: auto-generated'));

// Temat z polskimi znakami MUSI być zakodowany — inaczej trafia do skrzynki
// jako „Raport gotowy: Klub ? — Klub ?".
check('temat z polskimi znakami zakodowany (RFC 2047)',
    str_contains($rozmowa, 'Subject: =?UTF-8?B?'), 'temat poszedł surowy');

$tematLinia = '';
foreach (explode("\n", $rozmowa) as $l) {
    if (str_starts_with($l, 'Subject:')) {
        $tematLinia = $l;
    }
}
$zdekodowany = base64_decode((string) preg_replace('/^Subject: =\?UTF-8\?B\?(.*)\?=$/', '$1', $tematLinia));
check('zakodowany temat daje z powrotem oryginał',
    $zdekodowany === 'Raport gotowy: Klub Ą — Klub B', $zdekodowany);

// Treść też musi przejść w całości, z polskimi znakami.
$tresc = '';
$poNaglowkach = false;
foreach (explode("\n", $rozmowa) as $l) {
    if ($poNaglowkach && preg_match('#^[A-Za-z0-9+/=]+$#', trim($l)) === 1) {
        $tresc .= trim($l);
    }
    if (trim($l) === '' ) {
        $poNaglowkach = true;
    }
}
check('treść dotarła bez zniekształceń',
    str_contains(base64_decode($tresc), 'Treść po polsku.'), substr(base64_decode($tresc), 0, 80));
check('treść niesie odnośnik do panelu lub sam tekst', $tresc !== '');

// ---------------------------------------------------------------- wybor transportu
echo "\n== SMTP_ENCRYPTION decyduje o transporcie ==\n";

$ssl = new Mailer('mail-serwer400227.lh.pl', 465, null, null, 'a@b.test', 'X', 5.0, 'ssl');
check('ssl laczy sie przez ssl://',
    $ssl->transport() === 'ssl://mail-serwer400227.lh.pl:465', $ssl->transport());

$tls = new Mailer('mail-serwer400227.lh.pl', 587, null, null, 'a@b.test', 'X', 5.0, 'tls');
check('tls laczy sie przez tcp:// i podnosi STARTTLS',
    $tls->transport() === 'tcp://mail-serwer400227.lh.pl:587', $tls->transport());

$zaden = new Mailer('localhost', 25, null, null, 'a@b.test', 'X', 5.0, 'none');
check('none laczy sie przez tcp://', $zaden->transport() === 'tcp://localhost:25');

check('wielkosc liter bez znaczenia',
    (new Mailer('h', 465, null, null, 'a@b.test', 'X', 5.0, 'SSL'))->encryption() === 'ssl');
check('puste ustawienie daje tls',
    (new Mailer('h', 587, null, null, 'a@b.test', 'X', 5.0, ''))->encryption() === 'tls');

// Literowka NIE MOZE cicho cofnac wdrozenia do slabszego szyfrowania.
$bledne = null;
try {
    new Mailer('h', 465, null, null, 'a@b.test', 'X', 5.0, 'sll');
} catch (\Throwable $e) {
    $bledne = $e->getMessage();
}
check('literowka w SMTP_ENCRYPTION przerywa, zamiast cofac do slabszego', $bledne !== null);
check('komunikat wymienia dozwolone wartosci',
    $bledne !== null && str_contains($bledne, 'ssl, tls, none'), (string) $bledne);

// Konfiguracja lh.pl czytana z .env — bez tego test sprawdzalby tylko konstruktor.
Config::reset([
    'APP_ENV' => 'test', 'APP_URL' => 'https://app.example.test',
    'SMTP_HOST' => 'mail-serwer400227.lh.pl', 'SMTP_PORT' => '465',
    'SMTP_ENCRYPTION' => 'ssl', 'SMTP_USER' => 'raporty@example.test',
    'SMTP_PASS' => 'tajne', 'SMTP_TIMEOUT' => '7',
    'MAIL_FROM' => 'raporty@example.test',
]);
$zEnv = Mailer::fromConfig();
check('konfiguracja lh.pl daje ssl://mail-serwer400227.lh.pl:465',
    $zEnv->transport() === 'ssl://mail-serwer400227.lh.pl:465', $zEnv->transport());

// Zgodnosc wstecz: wdrozenie sprzed SMTP_ENCRYPTION, sam port 465.
Config::reset([
    'APP_ENV' => 'test', 'SMTP_HOST' => 'stary.test', 'SMTP_PORT' => '465',
    'MAIL_FROM' => 'a@b.test',
]);
check('brak SMTP_ENCRYPTION przy porcie 465 domysla sie ssl',
    Mailer::fromConfig()->encryption() === 'ssl');

Config::reset([
    'APP_ENV' => 'test', 'SMTP_HOST' => 'stary.test', 'SMTP_PORT' => '587',
    'MAIL_FROM' => 'a@b.test',
]);
check('brak SMTP_ENCRYPTION przy porcie 587 domysla sie tls',
    Mailer::fromConfig()->encryption() === 'tls');

Config::reset(['APP_ENV' => 'test', 'APP_URL' => 'https://app.example.test']);

// ---------------------------------------------------------------- SMTPS
echo "\n== pelna rozmowa po SMTPS (jak lh.pl) ==\n";

/*
 * Certyfikat testowy wytwarzamy TUTAJ, zamiast liczyc na plik z zewnatrz —
 * inaczej test po cichu pomijalby najwazniejsza sciezke i nadal pokazywal
 * same zielone linijki.
 */
$pem  = $here . '/smtp_test.pem';
$cert = $here . '/smtp_test_cert.pem';
$klucz = $here . '/smtp_test_key.pem';

exec(sprintf(
    'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes '
    . '-subj "/CN=localhost" -addext "subjectAltName=DNS:localhost" 2>/dev/null',
    escapeshellarg($klucz),
    escapeshellarg($cert)
), $out, $kodSsl);

if ($kodSsl === 0 && is_file($cert) && is_file($klucz)) {
    file_put_contents($pem, file_get_contents($cert) . file_get_contents($klucz));
}

if (!is_file($pem)) {
    echo "  --   nie udalo sie wytworzyc certyfikatu testowego (openssl), pomijam\n";
    $fail++;
    echo "  BLAD sciezka ssl:// nie zostala sprawdzona\n";
} else {
    $logS = $here . '/smtp_ssl.log';
    atrapa(2534, $logS, 'smtps', $pem);

    // Certyfikat jest samopodpisany, wiec do TESTU wylaczamy weryfikacje.
    // W produkcji weryfikacja jest wlaczona (Mailer::context) i tak ma zostac:
    // szyfrowanie bez sprawdzenia, z kim rozmawiamy, jest ozdoba.
    $mailerS = new Mailer('127.0.0.1', 2534, 'uzytkownik', 'tajne-haslo',
        'raporty@example.test', 'CoachAnalyze', 5.0, 'ssl');

    $bladS = null;
    try {
        $mailerS->send('trener@example.test', 'Raport gotowy', 'Tresc');
    } catch (\Throwable $e) {
        $bladS = $e->getMessage();
    }

    // Samopodpisany certyfikat MUSI zostac odrzucony — to dowod, ze weryfikacja
    // dziala. Gdyby przeszedl, znaczyloby ze Mailer akceptuje kogokolwiek.
    check('samopodpisany certyfikat odrzucony (weryfikacja dziala)', $bladS !== null, 'polaczenie przeszlo');
    check('powod mowi o polaczeniu i szyfrowaniu',
        $bladS !== null && str_contains($bladS, 'ssl://'), (string) $bladS);
    check('hasło NIE poszło do niezweryfikowanego serwera',
        !str_contains($mailerS->transcript(), base64_encode('tajne-haslo')));

    @unlink($logS);
    @unlink($logS . '.ready');

    // ------------------------------------------------------------ udana rozmowa
    //
    // Odrzucenie certyfikatu dowodzi, ze weryfikacja dziala, ale NIE dowodzi,
    // ze sciezka ssl:// w ogole dziala. Bez tego testu zmiana pod lh.pl bylaby
    // sprawdzona wylacznie w czesci, ktora ma zawiesc.
    //
    // Certyfikat testowy podajemy jako ZAUFANY URZAD (SMTP_CA_FILE), zamiast
    // wylaczac weryfikacje — dokladnie tak, jak zrobiloby sie to na hostingu
    // z nieaktualnym zasobem CA.
    echo "\n== udana rozmowa po SMTPS ==\n";

    $logD = $here . '/smtp_ssl_ok.log';
    atrapa(2535, $logD, 'smtps', $pem);

    $mailerD = new Mailer(
        'localhost', 2535, 'uzytkownik', 'tajne-haslo',
        'raporty@example.test', 'CoachAnalyze', 5.0, 'ssl', $cert
    );

    $bladD = null;
    try {
        $mailerD->send('trener@example.test', 'Raport gotowy: Klub A — Klub Z', 'Tresc raportu.');
    } catch (\Throwable $e) {
        $bladD = $e->getMessage();
    }

    check('wysylka po ssl:// bez wyjatku', $bladD === null, (string) $bladD);

    $rozmowaD = rozmowa($logD);
    check('rozmowa doszla do skutku', $rozmowaD !== '');
    check('klient NIE wyslal STARTTLS na SMTPS',
        !str_contains(strtoupper($rozmowaD), 'STARTTLS'),
        'komenda STARTTLS na porcie 465 jest bledem');
    check('uwierzytelnienie AUTH LOGIN przeszlo', str_contains($rozmowaD, 'AUTH LOGIN'));
    check('koperta dostarczona', str_contains($rozmowaD, 'RCPT TO:<trener@example.test>'));
    check('tresc dostarczona', str_contains($rozmowaD, 'DATA'));
    check('haslo poszlo dopiero po zestawieniu szyfrowania',
        str_contains($rozmowaD, base64_encode('tajne-haslo')),
        'na kanale szyfrowanym haslo MA prawo sie pojawic');

    @unlink($logD);
    @unlink($logD . '.ready');
}

@unlink($pem);
@unlink($cert);
@unlink($klucz);

// ---------------------------------------------------------------- odrzucenie
echo "\n== serwer odrzuca odbiorcę ==\n";

$log2 = $here . '/smtp_reject.log';
atrapa(2532, $log2, 'reject');

$mailer2 = new Mailer('127.0.0.1', 2532, null, null, 'raporty@example.test', 'CoachAnalyze', 5.0);
$blad2 = null;
try {
    $mailer2->send('nikt@example.test', 'Temat', 'Treść');
} catch (\Throwable $e) {
    $blad2 = $e->getMessage();
}

check('odrzucenie kończy się wyjątkiem', $blad2 !== null);
check('komunikat po polsku', $blad2 !== null && str_contains($blad2, 'odrzucił polecenie'), (string) $blad2);
check('komunikat niesie kod serwera', $blad2 !== null && str_contains($blad2, '550'), (string) $blad2);
check('zapis rozmowy dostępny do logu', $mailer2->transcript() !== '');

// ---------------------------------------------------------------- brak serwera
echo "\n== serwer niedostępny ==\n";

$mailer3 = new Mailer('127.0.0.1', 2599, null, null, 'raporty@example.test', 'CoachAnalyze', 1.0);
$blad3 = null;
try {
    $mailer3->send('trener@example.test', 'Temat', 'Treść');
} catch (\Throwable $e) {
    $blad3 = $e->getMessage();
}
check('brak serwera kończy się wyjątkiem', $blad3 !== null);
check('komunikat mówi o połączeniu', $blad3 !== null && str_contains($blad3, 'połączyć'), (string) $blad3);

// ---------------------------------------------------------------- hasło a szyfrowanie
echo "\n== hasło nie idzie otwartym tekstem ==\n";

// Serwer bez STARTTLS, a konfiguracja z hasłem: wysyłka MUSI się przerwać.
$log4 = $here . '/smtp_nostarttls.log';
atrapa(2533, $log4, 'ok');

$mailer4 = new Mailer('127.0.0.1', 2533, 'uzytkownik', 'tajne-haslo', 'raporty@example.test', 'CoachAnalyze', 5.0);
$blad4 = null;
try {
    $mailer4->send('trener@example.test', 'Temat', 'Treść');
} catch (\Throwable $e) {
    $blad4 = $e->getMessage();
}

check('wysyłka przerwana', $blad4 !== null, 'hasło poszłoby otwartym tekstem');
check('powód nazwany wprost',
    $blad4 !== null && str_contains($blad4, 'otwartym tekstem'), (string) $blad4);

$rozmowa4 = rozmowa($log4);
check('hasło NIE pojawiło się w rozmowie',
    !str_contains($rozmowa4, base64_encode('tajne-haslo')) && !str_contains($rozmowa4, 'tajne-haslo'));

echo "\n== hasło zamaskowane w zapisie do logu ==\n";
check('zapis rozmowy nie zawiera hasła',
    !str_contains($mailer4->transcript(), base64_encode('tajne-haslo')),
    'hasło trafiłoby do logu');

// ================================================================ USTERKI Z PRODUKCJI
echo "\n== Reply-To z konfiguracji, nie adres nadawcy ==\n";

$logR = $here . '/smtp_reply.log';
atrapa(2536, $logR, 'ok');

$mailerR = new Mailer('127.0.0.1', 2536, null, null, 'noreply@coachanalyze.pl',
    'CoachAnalyze', 5.0, 'none', null, 'hello@coachanalyze.pl');

try { $mailerR->send('trener@example.test', 'Temat', 'Tresc'); } catch (\Throwable $e) {}
$rozmowaR = rozmowa($logR);

check('naglowek Reply-To obecny', str_contains($rozmowaR, 'Reply-To:'), 'odpowiedz trafialaby w prozne');
check('Reply-To ma wartosc z konfiguracji',
    str_contains($rozmowaR, 'Reply-To: <hello@coachanalyze.pl>'),
    'wstawiono adres nadawcy zamiast MAIL_REPLY_TO');
check('Reply-To ROZNI sie od nadawcy',
    !str_contains($rozmowaR, 'Reply-To: <noreply@coachanalyze.pl>'));

// Brak konfiguracji = brak naglowka, a nie naglowek z adresem nadawcy.
$logR2 = $here . '/smtp_noreply.log';
atrapa(2537, $logR2, 'ok');
$mailerR2 = new Mailer('127.0.0.1', 2537, null, null, 'noreply@coachanalyze.pl',
    'CoachAnalyze', 5.0, 'none', null, null);
try { $mailerR2->send('trener@example.test', 'Temat', 'Tresc'); } catch (\Throwable $e) {}
check('bez MAIL_REPLY_TO naglowka nie ma wcale',
    !str_contains(rozmowa($logR2), 'Reply-To:'));

check('Mailer::fromConfig czyta MAIL_REPLY_TO', (function () {
    Config::reset([
        'APP_ENV' => 'test', 'SMTP_HOST' => 'h', 'SMTP_PORT' => '465',
        'MAIL_FROM' => 'noreply@example.test', 'MAIL_REPLY_TO' => 'hello@example.test',
    ]);
    $m = Mailer::fromConfig();
    $r = new ReflectionClass($m);
    $p = $r->getProperty('replyTo');
    $p->setAccessible(true);
    return $p->getValue($m) === 'hello@example.test';
})());
Config::reset(['APP_ENV' => 'test', 'APP_URL' => 'https://app.example.test']);

echo "\n== wiadomosc ma obie czesci (multipart/alternative) ==\n";

$logM = $here . '/smtp_multipart.log';
atrapa(2538, $logM, 'ok');
$mailerM = new Mailer('127.0.0.1', 2538, null, null, 'raporty@example.test',
    'CoachAnalyze', 5.0, 'none');

$html = '<!doctype html><html><body><h1>Raport gotowy</h1>'
    . '<a href="https://app.example.test/raport/7">Otwórz raport</a>'
    . '<p>ustawieniach konta</p></body></html>';

try {
    $mailerM->send('trener@example.test', 'Raport gotowy', "Tresc tekstowa.", $html);
} catch (\Throwable $e) {}

$rozmowaM = rozmowa($logM);
check('typ tresci to multipart/alternative',
    str_contains($rozmowaM, 'Content-Type: multipart/alternative'), 'mail poszedl samym tekstem');
check('granica czesci zadeklarowana', preg_match('/boundary="ca_[a-f0-9]+"/', $rozmowaM) === 1);
check('czesc tekstowa obecna', str_contains($rozmowaM, 'Content-Type: text/plain; charset=utf-8'));
check('czesc HTML obecna', str_contains($rozmowaM, 'Content-Type: text/html; charset=utf-8'));

// Kolejnosc jest ISTOTNA: klient bierze OSTATNIA czesc, ktora rozumie.
$pozTekst = strpos($rozmowaM, 'text/plain; charset=utf-8');
$pozHtml  = strpos($rozmowaM, 'text/html; charset=utf-8');
check('tekst PRZED HTML-em — inaczej czytnik tekstowy dostaje znaczniki',
    $pozTekst !== false && $pozHtml !== false && $pozTekst < $pozHtml);

// Obie czesci musza dowiezc tresc.
$czesci = [];
foreach (explode("\n", $rozmowaM) as $l) {
    $l = trim($l);
    if (preg_match('#^[A-Za-z0-9+/=]{20,}$#', $l) === 1) {
        $czesci[] = base64_decode($l);
    }
}
$polaczone = implode('', $czesci);
check('tresc tekstowa dowieziona', str_contains($polaczone, 'Tresc tekstowa.'));
check('tresc HTML dowieziona', str_contains($polaczone, '<h1>Raport gotowy</h1>'));
check('HTML niesie przycisk do raportu', str_contains($polaczone, 'Otwórz raport'));
check('HTML niesie informacje o wylaczeniu powiadomien',
    str_contains($polaczone, 'ustawieniach konta'));

check('bez HTML-a wiadomosc zostaje zwyklym tekstem', (function () use ($here) {
    $log = $here . '/smtp_plain.log';
    atrapa(2539, $log, 'ok');
    $m = new Mailer('127.0.0.1', 2539, null, null, 'a@b.test', 'X', 5.0, 'none');
    try { $m->send('c@d.test', 'T', 'Tresc'); } catch (\Throwable $e) {}
    $r = rozmowa($log);
    @unlink($log); @unlink($log . '.ready');
    return !str_contains($r, 'multipart') && str_contains($r, 'text/plain');
})());

foreach ([$logR, $logR2, $logM] as $f) { @unlink($f); @unlink($f . '.ready'); }

// ---------------------------------------------------------------- sprzątanie
foreach ([$log, $log2, $log4] as $f) {
    @unlink($f);
}

echo "\n=== OK: {$ok}, BŁĘDÓW: {$fail} ===\n";
exit($fail === 0 ? 0 : 1);
