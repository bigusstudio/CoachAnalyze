<?php
declare(strict_types=1);

namespace CoachAnalyze;

/**
 * Minimalny klient Redis mówiący protokołem RESP przez gniazdo uniksowe.
 *
 * POWÓD WŁASNEJ IMPLEMENTACJI: rozszerzenie `redis` (PECL) to biblioteka
 * kompilowana, a katalog domowy na lh.pl jest zamontowany z `noexec`
 * (docs/OGRANICZENIA_HOSTINGU.md). Ta sama zasada, która wyrzuciła pandas
 * z silnika, dotyczy warstwy PHP.
 *
 * Obsługujemy tylko to, czego potrzebuje limiter logowania: INCR, EXPIRE, TTL,
 * GET, DEL, PING. Rozrost tej klasy w stronę pełnego klienta oznacza, że ktoś
 * używa Redisa do czegoś, do czego w tym projekcie służy MySQL.
 */
final class RedisClient
{
    /** @var resource|null */
    private $socket = null;
    private string $prefix;

    public function __construct(
        private string $socketPath,
        ?string $prefix = null,
        private float $timeout = 1.0,
    ) {
        $this->prefix = $prefix ?? '';
    }

    public static function fromConfig(): self
    {
        // REDIS_PORT=0 w .env nie jest pomyłką: lh.pl wystawia Redis wyłącznie
        // przez gniazdo uniksowe, połączenie po TCP nie zadziała.
        return new self(
            Config::require('REDIS_SOCKET'),
            Config::get('REDIS_PREFIX', 'ca:'),
            (float) Config::int('REDIS_TIMEOUT', 1)
        );
    }

    /** @throws \RuntimeException gdy gniazdo jest niedostępne */
    private function connect(): void
    {
        if (is_resource($this->socket)) {
            return;
        }
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            $this->timeout
        );
        if ($socket === false) {
            throw new \RuntimeException(
                "Redis niedostępny ({$this->socketPath}): {$errstr}"
            );
        }
        stream_set_timeout($socket, (int) $this->timeout);
        $this->socket = $socket;
    }

    /** Polecenie w formacie RESP: tablica bulk stringów. */
    private function command(string ...$args): mixed
    {
        $this->connect();
        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }
        if (@fwrite($this->socket, $payload) === false) {
            throw new \RuntimeException('Zapis do gniazda Redis nie powiódł się');
        }
        return $this->readReply();
    }

    private function readLine(): string
    {
        $line = @fgets($this->socket);
        if ($line === false) {
            throw new \RuntimeException('Brak odpowiedzi z Redis (przekroczony czas?)');
        }
        return rtrim($line, "\r\n");
    }

    private function readReply(): mixed
    {
        $line = $this->readLine();
        $type = $line[0] ?? '';
        $body = substr($line, 1);

        return match ($type) {
            '+'     => $body,
            ':'     => (int) $body,
            '-'     => throw new \RuntimeException('Redis: ' . $body),
            '$'     => $this->readBulk((int) $body),
            '*'     => $this->readArray((int) $body),
            default => throw new \RuntimeException('Nieznana odpowiedź Redis: ' . $line),
        };
    }

    private function readBulk(int $length): ?string
    {
        if ($length === -1) {
            return null;
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('Urwana odpowiedź Redis');
            }
            $data .= $chunk;
        }
        @fread($this->socket, 2); // CRLF
        return $data;
    }

    /** @return list<mixed>|null */
    private function readArray(int $count): ?array
    {
        if ($count === -1) {
            return null;
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $this->readReply();
        }
        return $out;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    public function incr(string $key): int
    {
        return (int) $this->command('INCR', $this->key($key));
    }

    public function expire(string $key, int $seconds): bool
    {
        return (int) $this->command('EXPIRE', $this->key($key), (string) $seconds) === 1;
    }

    public function ttl(string $key): int
    {
        return (int) $this->command('TTL', $this->key($key));
    }

    public function get(string $key): ?string
    {
        $value = $this->command('GET', $this->key($key));
        return $value === null ? null : (string) $value;
    }

    public function del(string $key): int
    {
        return (int) $this->command('DEL', $this->key($key));
    }

    /**
     * Blokada: ustaw, jeśli klucza nie ma, i wygaś po `ttl` sekundach.
     *
     * `SET k v NX EX ttl` jest ATOMOWE — sprawdzenie i zapis w jednym poleceniu.
     * Wariant „sprawdź GET, potem SET" pozwoliłby dwóm procesom przejść naraz.
     * TTL jest obowiązkowy: proces ubity w połowie nie może zablokować kolejki
     * na zawsze.
     */
    public function setNx(string $key, string $value, int $ttl): bool
    {
        return $this->command('SET', $this->key($key), $value, 'NX', 'EX', (string) $ttl) === 'OK';
    }

    public function ping(): bool
    {
        return $this->command('PING') === 'PONG';
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}
