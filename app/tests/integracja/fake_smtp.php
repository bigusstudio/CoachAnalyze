<?php
declare(strict_types=1);

/**
 * Atrapa serwera SMTP — wyłącznie do testów.
 *
 * Mówi tyle protokołu, ile potrzebuje Mailer: powitanie, EHLO z listą możliwości,
 * AUTH LOGIN, MAIL FROM, RCPT TO, DATA zakończone kropką, QUIT.
 * Odebraną rozmowę zapisuje do pliku, żeby test mógł sprawdzić, co dokładnie poszło.
 *
 *   php fake_smtp.php <port> <plik-zapisu> [tryb] [pem]
 *
 * Tryby:
 *   ok        — wszystko przyjmuje, bez szyfrowania (domyślny)
 *   smtps     — szyfrowanie od pierwszego bajtu (jak lh.pl na porcie 465);
 *               wymaga podania pliku PEM z certyfikatem i kluczem
 *   starttls  — ogłasza STARTTLS w EHLO
 *   reject    — odrzuca RCPT TO kodem 550
 *   authfail  — odrzuca AUTH kodem 535
 */

$port  = (int) ($argv[1] ?? 2525);
$zapis = (string) ($argv[2] ?? '/tmp/fake_smtp.log');
$tryb  = (string) ($argv[3] ?? 'ok');

$pem = (string) ($argv[4] ?? '');

// SMTPS: gniazdo jest szyfrowane ZANIM padnie pierwsze słowo protokołu.
// To nie jest STARTTLS z innym numerem portu — klient musi połączyć się
// przez `ssl://`, a nie przez `tcp://` i komendę.
$kontekst = stream_context_create($tryb === 'smtps' ? ['ssl' => [
    'local_cert'       => $pem,
    'allow_self_signed' => true,
    'verify_peer'      => false,
]] : []);

$server = stream_socket_server(
    ($tryb === 'smtps' ? 'ssl://' : 'tcp://') . '127.0.0.1:' . $port,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $kontekst
);
if ($server === false) {
    fwrite(STDERR, "nie mogę nasłuchiwać: {$errstr}\n");
    exit(1);
}

@unlink($zapis);

// Znacznik gotowości. Sondowanie portu połączeniem zużywałoby jedyne
// połączenie, jakie atrapa obsługuje, i właściwy test trafiałby w pustkę.
file_put_contents($zapis . '.ready', '1');

$deadline = time() + 60;

while (time() < $deadline) {
    $conn = @stream_socket_accept($server, 5);
    if ($conn === false) {
        continue;
    }

    $rozmowa = [];
    $wDanych = false;

    fwrite($conn, "220 atrapa.test ESMTP\r\n");

    while (!feof($conn)) {
        $linia = fgets($conn, 4096);
        if ($linia === false) {
            break;
        }
        $rozmowa[] = rtrim($linia, "\r\n");
        $t = rtrim($linia, "\r\n");

        if ($wDanych) {
            if ($t === '.') {
                $wDanych = false;
                fwrite($conn, "250 OK przyjęto\r\n");
            }
            continue;
        }

        $gora = strtoupper($t);

        if (str_starts_with($gora, 'EHLO') || str_starts_with($gora, 'HELO')) {
            fwrite($conn, "250-atrapa.test\r\n");
            fwrite($conn, "250-SIZE 10240000\r\n");
            // STARTTLS ogłaszamy wyłącznie na żądanie: atrapa nie umie TLS,
            // więc w każdym innym teście rozmowa kończyłaby się na szyfrowaniu,
            // zanim doszłaby do sprawy, której ten test dotyczy.
            if ($tryb === 'starttls') {
                fwrite($conn, "250-STARTTLS\r\n");
            }
            fwrite($conn, "250 AUTH LOGIN PLAIN\r\n");
            continue;
        }

        // STARTTLS w atrapie tylko potwierdzamy — test nie sprawdza samego TLS,
        // tylko to, że klient nie wysyła hasła bez szyfrowania.
        if ($gora === 'STARTTLS') {
            fwrite($conn, "454 TLS niedostępne w atrapie\r\n");
            continue;
        }

        if ($gora === 'AUTH LOGIN') {
            fwrite($conn, "334 VXNlcm5hbWU6\r\n");
            continue;
        }

        if (str_starts_with($gora, 'MAIL FROM')) {
            fwrite($conn, "250 OK\r\n");
            continue;
        }

        if (str_starts_with($gora, 'RCPT TO')) {
            fwrite($conn, $tryb === 'reject'
                ? "550 Skrzynka nie istnieje\r\n"
                : "250 OK\r\n");
            continue;
        }

        if ($gora === 'DATA') {
            $wDanych = true;
            fwrite($conn, "354 Dawaj treść, zakończ kropką\r\n");
            continue;
        }

        if ($gora === 'QUIT') {
            fwrite($conn, "221 Do widzenia\r\n");
            break;
        }

        // Odpowiedź na base64 z AUTH LOGIN: pierwsza to login, druga to hasło.
        if (preg_match('#^[A-Za-z0-9+/=]+$#', $t) === 1) {
            $ileBase64 = count(array_filter(
                $rozmowa,
                static fn($l) => preg_match('#^[A-Za-z0-9+/=]+$#', $l) === 1
            ));
            if ($ileBase64 >= 2) {
                fwrite($conn, $tryb === 'authfail'
                    ? "535 Błędne dane logowania\r\n"
                    : "235 Uwierzytelniono\r\n");
            } else {
                fwrite($conn, "334 UGFzc3dvcmQ6\r\n");
            }
            continue;
        }

        fwrite($conn, "500 Nie rozumiem\r\n");
    }

    file_put_contents($zapis, implode("\n", $rozmowa));
    fclose($conn);
    break;
}

fclose($server);
@unlink($zapis . '.ready');
