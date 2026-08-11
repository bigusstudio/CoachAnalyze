<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Klient SMTP mówiący protokołem wprost, przez `stream_socket_client`.
 *
 * POWÓD WŁASNEJ IMPLEMENTACJI: ta sama zasada, która wyrzuciła `pandas` z silnika
 * i rozszerzenie `redis` z warstwy PHP — katalog domowy na lh.pl jest zamontowany
 * z `noexec` (docs/OGRANICZENIA_HOSTINGU.md). PHPMailer i Symfony Mailer są
 * wprawdzie czystym PHP, ale ciągną Composera i kilkadziesiąt plików na coś,
 * co tutaj sprowadza się do kilkunastu linii rozmowy tekstowej.
 *
 * DLACZEGO NIE `mail()`: funkcja `mail()` wymaga sendmaila na serwerze i nie daje
 * żadnej informacji zwrotnej poza `true`/`false` — nie da się odróżnić „przyjęto"
 * od „odrzucono jako spam". Przy powiadomieniach, których nikt nie ogląda na
 * bieżąco, cicha porażka jest gorsza niż brak funkcji.
 *
 * ZAKRES: jedna wiadomość tekstowa do jednego odbiorcy. Rozrost tej klasy
 * w stronę załączników, HTML-a i list odbiorców oznacza, że produkt potrzebuje
 * prawdziwej usługi mailowej, a nie większego pliku.
 *
 * KLASA NIE JEST WOŁANA Z WARSTWY ŻĄDAŃ. Wysyłka idzie z kolejki, jako osobny
 * typ zadania — patrz `app/bin/run_job.php`. Powód jest ten sam, co przy silniku:
 * żądanie HTTP nie ma czekać na cudzy serwer.
 */
final class Mailer
{
    /**
     * Sposób szyfrowania połączenia.
     *
     * `ssl` (SMTPS, zwykle port 465) — szyfrowane od pierwszego bajtu, klient
     *       łączy się przez `ssl://` i NIE wysyła komendy STARTTLS.
     * `tls` (STARTTLS, zwykle port 587) — rozmowa zaczyna się otwartym tekstem
     *       i jest podnoszona komendą STARTTLS po EHLO.
     * `none` — bez szyfrowania. Dopuszczalne tylko dla przekaźnika w sieci
     *       lokalnej i tylko bez hasła (patrz `handshake()`).
     *
     * To są DWA RÓŻNE PROTOKOŁY, nie wariant tego samego. Wysłanie `STARTTLS`
     * do serwera SMTPS kończy się błędem, a pominięcie go na serwerze STARTTLS
     * wysyła hasło otwartym tekstem.
     */
    public const SZYFROWANIE = ['ssl', 'tls', 'none'];

    /** @var resource|null */
    private $socket = null;

    /** Ostatnia rozmowa z serwerem — do logu przy awarii, nigdy do przeglądarki. */
    private string $transcript = '';

    public function __construct(
        private string $host,
        private int $port,
        private ?string $user,
        private ?string $pass,
        private string $from,
        private string $fromName = 'CoachAnalyze',
        private float $timeout = 10.0,
        private string $encryption = 'tls',
        private ?string $caFile = null,
        private ?string $replyTo = null,
    ) {
        $this->encryption = self::normalizeEncryption($encryption);
    }

    /**
     * Sposób szyfrowania z konfiguracji.
     *
     * Wartość spoza listy NIE JEST po cichu poprawiana na domyślną: literówka
     * w `SMTP_ENCRYPTION` cofnęłaby wdrożenie do słabszego szyfrowania, a to
     * jest dokładnie ta klasa błędu, której nikt nie zauważy — poczta chodzi,
     * tylko hasło lata otwartym tekstem.
     */
    public static function normalizeEncryption(string $value): string
    {
        $v = strtolower(trim($value));
        if ($v === '') {
            return 'tls';
        }
        if (!in_array($v, self::SZYFROWANIE, true)) {
            throw new \RuntimeException(
                'Nieznana wartość SMTP_ENCRYPTION: „' . $value . '”. Dozwolone: '
                . implode(', ', self::SZYFROWANIE) . '.'
            );
        }
        return $v;
    }

    /**
     * Czy warstwa mailowa ma z czego działać.
     *
     * Brak konfiguracji NIE JEST BŁĘDEM. Powiadomienia w aplikacji mają działać
     * dalej, a wdrożenie bez SMTP jest normalnym stanem — nie każdy klub chce
     * maili i nie każdy hosting daje działający serwer poczty wychodzącej.
     */
    public static function isConfigured(): bool
    {
        return trim((string) Config::get('SMTP_HOST', '')) !== ''
            && trim((string) Config::get('MAIL_FROM', '')) !== '';
    }

    public static function fromConfig(): self
    {
        $user = trim((string) Config::get('SMTP_USER', ''));
        $pass = (string) Config::get('SMTP_PASS', '');

        $port = Config::int('SMTP_PORT', 587);

        return new self(
            trim((string) Config::require('SMTP_HOST')),
            $port,
            $user !== '' ? $user : null,
            $pass !== '' ? $pass : null,
            trim((string) Config::require('MAIL_FROM')),
            trim((string) Config::get('MAIL_FROM_NAME', 'CoachAnalyze')),
            (float) Config::int('SMTP_TIMEOUT', 10),
            // Domyślna wartość wyprowadzona z portu istnieje wyłącznie dla
            // konfiguracji sprzed wprowadzenia SMTP_ENCRYPTION. Przy nowym
            // wdrożeniu wpisujemy to jawnie: numer portu jest zwyczajem,
            // a nie deklaracją protokołu, i serwer może słuchać gdzie indziej.
            (string) Config::get('SMTP_ENCRYPTION', $port === 465 ? 'ssl' : 'tls'),
            Config::get('SMTP_CA_FILE'),
            // USTERKA Z PRODUKCJI: `MAIL_REPLY_TO` stalo w `.env` i nie bylo
            // czytane, wiec odpowiedz klienta trafiala pod adres nadawcy —
            // czyli w prozne. Adres zwrotny to czesto INNA skrzynka niz
            // techniczny nadawca i musi dac sie ustawic osobno.
            Config::get('MAIL_REPLY_TO')
        );
    }

    /**
     * Adres połączenia. Wydzielone, bo to jedyne miejsce, gdzie wybór szyfrowania
     * zmienia zachowanie jeszcze PRZED wymianą jakiejkolwiek komendy — i jedyne,
     * które da się sprawdzić testem bez stawiania serwera TLS.
     */
    public function transport(): string
    {
        return ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
    }

    public function encryption(): string
    {
        return $this->encryption;
    }

    /**
     * Wysyłka jednej wiadomości.
     *
     * @throws \RuntimeException z komunikatem po polsku; pełna rozmowa w `transcript()`
     */
    public function send(string $to, string $subject, string $body, ?string $html = null): void
    {
        $this->transcript = '';

        try {
            $this->connect();
            $this->handshake();
            $this->authenticate();
            $this->envelope($to);
            $this->data($to, $subject, $body, $html);
            $this->command('QUIT', [221]);
        } finally {
            $this->disconnect();
        }
    }

    /** Zapis rozmowy z serwerem — wyłącznie do logu. Hasło jest w nim zamaskowane. */
    public function transcript(): string
    {
        return $this->transcript;
    }

    // ---------------------------------------------------------------- protokół

    private function connect(): void
    {
        $errno = 0;
        $errstr = '';
        $adres = $this->transport();

        // Limit czasu POŁĄCZENIA — osobny argument `stream_socket_client`.
        // Bez niego PHP używa `default_socket_timeout` (zwykle 60 s), a proces
        // roboczy stałby minutę na każdym powiadomieniu, gdy serwer poczty
        // przestanie odpowiadać. Cron chodzi co minutę: kolejka stanęłaby.
        $socket = @stream_socket_client(
            $adres,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $this->context()
        );

        if ($socket === false) {
            throw new \RuntimeException(sprintf(
                'Nie udało się połączyć z serwerem poczty %s (szyfrowanie: %s, limit %ds) — %s',
                $adres,
                $this->encryption,
                (int) $this->timeout,
                $errstr !== '' ? $errstr : 'brak odpowiedzi'
            ));
        }

        $this->socket = $socket;

        // Limit czasu ODCZYTU. To inny limit niż powyższy: połączenie może wstać
        // od razu, a serwer i tak milczeć w odpowiedzi na komendę.
        stream_set_timeout($socket, max(1, (int) $this->timeout));

        // Powitanie serwera przychodzi bez pytania, jeszcze przed pierwszą komendą.
        // Przy `ssl://` dzieje się to już po zestawieniu szyfrowania.
        $this->expect([220]);
    }

    /**
     * Kontekst połączenia szyfrowanego.
     *
     * Weryfikacja certyfikatu WŁĄCZONA — to wartości domyślne PHP i nie ma
     * powodu ich osłabiać. Wyłączenie sprawdzania nazwy hosta zamieniłoby
     * szyfrowanie w ozdobę: połączenie byłoby szyfrowane z kimkolwiek, kto
     * odpowie pod tym adresem, łącznie z hasłem do SMTP.
     *
     * @return resource
     */
    private function context()
    {
        $ssl = [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'SNI_enabled'       => true,
            'peer_name'         => $this->host,
        ];

        // Własny zasób urzędów certyfikacji. Potrzebny, gdy hosting ma nieaktualny
        // zestaw CA — bez tej możliwości jedynym „rozwiązaniem", jakie się znajduje
        // w takiej sytuacji, jest wyłączenie weryfikacji, czyli utrata szyfrowania
        // przy zachowaniu wrażenia, że się je ma.
        if ($this->caFile !== null && $this->caFile !== '') {
            $ssl['cafile'] = $this->caFile;
        }

        return stream_context_create(['ssl' => $ssl]);
    }

    private function handshake(): void
    {
        $ehlo = $this->command('EHLO ' . $this->heloName(), [250]);

        // SMTPS: połączenie było szyfrowane od pierwszego bajtu. Komenda STARTTLS
        // jest tu BŁĘDEM — serwer odpowie „503 already using TLS" albo zerwie
        // rozmowę. To właśnie ten przypadek obsługuje lh.pl (port 465).
        if ($this->encryption === 'ssl') {
            return;
        }

        if ($this->encryption === 'none') {
            // Bez szyfrowania nie wolno wysłać hasła. Konfiguracja, która o to
            // prosi, jest sprzeczna sama ze sobą i lepiej ją zatrzymać tutaj
            // niż tłumaczyć później, skąd wyciekło hasło do poczty.
            if ($this->user !== null) {
                throw new \RuntimeException(
                    'SMTP_ENCRYPTION=none wyklucza uwierzytelnianie: hasło poszłoby otwartym tekstem. '
                    . 'Ustaw SMTP_ENCRYPTION na ssl albo tls, albo usuń SMTP_USER i SMTP_PASS.'
                );
            }
            return;
        }

        // STARTTLS podnosimy, GDY SERWER GO OGŁASZA. Wymuszanie go na serwerze,
        // który nie umie TLS, zerwałoby połączenie; pomijanie go tam, gdzie umie,
        // wysłałoby hasło otwartym tekstem.
        if (stripos($ehlo, 'STARTTLS') === false) {
            if ($this->user !== null) {
                throw new \RuntimeException(
                    'Serwer poczty nie oferuje szyfrowania (STARTTLS), a konfiguracja zawiera hasło. '
                    . 'Wysyłka przerwana, żeby nie wysłać danych logowania otwartym tekstem. '
                    . 'Jeśli serwer używa SMTPS, ustaw SMTP_ENCRYPTION=ssl i port 465.'
                );
            }
            return;
        }

        $this->command('STARTTLS', [220]);

        $ok = @stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );
        if ($ok !== true) {
            throw new \RuntimeException('Nie udało się nawiązać szyfrowanego połączenia (STARTTLS).');
        }

        // Po podniesieniu TLS rozmowa zaczyna się od nowa — lista możliwości
        // sprzed szyfrowania przestaje obowiązywać.
        $this->command('EHLO ' . $this->heloName(), [250]);
    }

    private function authenticate(): void
    {
        if ($this->user === null || $this->pass === null) {
            return; // Serwer bez uwierzytelniania (np. przekaźnik w sieci lokalnej).
        }

        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($this->user), [334], 'AUTH LOGIN <użytkownik>');
        $this->command(base64_encode($this->pass), [235], 'AUTH LOGIN <hasło ukryte>');
    }

    private function envelope(string $to): void
    {
        $this->command('MAIL FROM:<' . $this->from . '>', [250]);
        $this->command('RCPT TO:<' . $to . '>', [250, 251]);
    }

    /**
     * Zbudowanie i wyslanie tresci wiadomosci.
     *
     * MULTIPART/ALTERNATIVE: ta sama tresc w dwoch postaciach. Czytnik wybiera
     * te, ktora umie pokazac — nowoczesny wezmie HTML, tekstowy i czytnik
     * ekranu wezma czysty tekst. Kolejnosc czesci jest ISTOTNA: od najprostszej
     * do najbogatszej, bo klient bierze OSTATNIA, ktora rozumie.
     */
    private function data(string $to, string $subject, string $body, ?string $html = null): void
    {
        $this->command('DATA', [354]);

        $granica = 'ca_' . bin2hex(random_bytes(12));

        $naglowki = [
            'From: ' . $this->encodeHeader($this->fromName) . ' <' . $this->from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
        ];

        // Adres zwrotny. Bez niego odpowiedz idzie na adres nadawcy, ktory bywa
        // skrzynka techniczna nikogo nieczytana — dokladnie to zglosila produkcja.
        if ($this->replyTo !== null && trim($this->replyTo) !== '') {
            $naglowki[] = 'Reply-To: <' . trim($this->replyTo) . '>';
        }

        $naglowki[] = 'Auto-Submitted: auto-generated';
        $naglowki[] = 'X-Auto-Response-Suppress: All';

        if ($html === null || trim($html) === '') {
            $naglowki[] = 'Content-Type: text/plain; charset=utf-8';
            $naglowki[] = 'Content-Transfer-Encoding: base64';
            $tresc = chunk_split(base64_encode($body), 76, "\r\n");
        } else {
            $naglowki[] = 'Content-Type: multipart/alternative; boundary="' . $granica . '"';

            $tresc = implode("\r\n", [
                // Zdanie dla klientow, ktore nie rozumieja multipart. W praktyce
                // nikt go nie zobaczy, ale jego brak jest bledem formatu.
                'Ta wiadomosc jest w formacie MIME multipart/alternative.',
                '',
                '--' . $granica,
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: base64',
                '',
                rtrim(chunk_split(base64_encode($body), 76, "\r\n")),
                '',
                '--' . $granica,
                'Content-Type: text/html; charset=utf-8',
                'Content-Transfer-Encoding: base64',
                '',
                rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
                '',
                '--' . $granica . '--',
                '',
            ]);
        }

        $wiadomosc = implode("\r\n", $naglowki) . "\r\n\r\n" . $tresc;

        // Kropka na poczatku linii konczy DATA — trzeba ja podwoic.
        // Kodowanie base64 czyni to niemozliwym w samej tresci, ale
        // zabezpieczenie zostaje: zmiana kodowania na 8bit nie moze po cichu
        // otworzyc wstrzykniecia.
        $wiadomosc = str_replace("\r\n.", "\r\n..", $wiadomosc);

        $this->write($wiadomosc . "\r\n.\r\n");
        $this->expect([250]);
    }

    // ---------------------------------------------------------------- warstwa niższa

    /** @param list<int> $oczekiwane */
    private function command(string $linia, array $oczekiwane, ?string $doLogu = null): string
    {
        $this->transcript .= '> ' . ($doLogu ?? $linia) . "\n";
        $this->write($linia . "\r\n");
        return $this->expect($oczekiwane);
    }

    private function write(string $dane): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('Połączenie z serwerem poczty zostało zerwane.');
        }
        if (@fwrite($this->socket, $dane) === false) {
            throw new \RuntimeException('Nie udało się wysłać danych do serwera poczty.');
        }
    }

    /**
     * Odczyt odpowiedzi wraz z liniami ciągłymi (`250-...` przed `250 ...`).
     *
     * @param list<int> $oczekiwane
     */
    private function expect(array $oczekiwane): string
    {
        $odpowiedz = '';

        while (true) {
            if (!is_resource($this->socket)) {
                throw new \RuntimeException('Połączenie z serwerem poczty zostało zerwane.');
            }

            $linia = fgets($this->socket, 1024);

            if ($linia === false) {
                $info = stream_get_meta_data($this->socket);
                if (!empty($info['timed_out'])) {
                    throw new \RuntimeException(
                        'Serwer poczty nie odpowiedział w ciągu ' . (int) $this->timeout . ' s.'
                    );
                }
                throw new \RuntimeException('Serwer poczty przerwał połączenie bez odpowiedzi.');
            }

            $this->transcript .= '< ' . rtrim($linia) . "\n";
            $odpowiedz .= $linia;

            // `250-` znaczy „ciąg dalszy nastąpi", `250 ` kończy odpowiedź.
            if (strlen($linia) < 4 || $linia[3] !== '-') {
                break;
            }
        }

        $kod = (int) substr($odpowiedz, 0, 3);
        if (!in_array($kod, $oczekiwane, true)) {
            throw new \RuntimeException(
                'Serwer poczty odrzucił polecenie (kod ' . $kod . '): ' . trim($this->firstLine($odpowiedz))
            );
        }

        return $odpowiedz;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    /**
     * Nazwa w EHLO. Serwery bywają wybredne, a nazwa hosta z `$_SERVER` nie
     * istnieje przy uruchomieniu z crona — bierzemy ją z APP_URL.
     */
    private function heloName(): string
    {
        $url = (string) Config::get('APP_URL', '');
        $host = $url !== '' ? (string) parse_url($url, PHP_URL_HOST) : '';
        return $host !== '' ? $host : 'localhost';
    }

    /** Nagłówek z polskimi znakami wymaga kodowania (RFC 2047). */
    private function encodeHeader(string $tekst): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $tekst) === 1) {
            return $tekst;
        }
        return '=?UTF-8?B?' . base64_encode($tekst) . '?=';
    }

    private function firstLine(string $tekst): string
    {
        $koniec = strpos($tekst, "\n");
        return $koniec === false ? $tekst : substr($tekst, 0, $koniec);
    }
}
