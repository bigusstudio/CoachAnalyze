<?php
declare(strict_types=1);
/** Atrapa Redisa mówiąca RESP przez gniazdo uniksowe — wyłącznie do testów.
 *  Obsługuje wiele równoczesnych połączeń (stream_select), bo limiter otwiera
 *  osobne połączenie na każdy egzemplarz klienta. */

$path = $argv[1] ?? '/tmp/fake_redis.sock';
@unlink($path);
$server = stream_socket_server('unix://' . $path, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "nie mogę nasłuchiwać: $errstr\n");
    exit(1);
}

$store = [];
$expiry = [];
$clients = [];
$deadline = time() + 900;

function readArgs($conn): ?array
{
    $line = fgets($conn);
    if ($line === false || $line === '') return null;
    if ($line[0] !== '*') return [];
    $argc = (int) substr($line, 1);
    $args = [];
    for ($i = 0; $i < $argc; $i++) {
        $head = fgets($conn);
        if ($head === false) return null;
        $len = (int) substr($head, 1);
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($conn, $len - strlen($data));
            if ($chunk === false || $chunk === '') return null;
            $data .= $chunk;
        }
        fread($conn, 2);
        $args[] = $data;
    }
    return $args;
}

while (time() < $deadline) {
    $read = array_merge([$server], $clients);
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) === false) break;

    foreach ($read as $sock) {
        if ($sock === $server) {
            $conn = @stream_socket_accept($server, 0);
            if ($conn !== false) $clients[] = $conn;
            continue;
        }

        $args = readArgs($sock);
        if ($args === null) {
            fclose($sock);
            $clients = array_values(array_filter($clients, fn($c) => $c !== $sock));
            continue;
        }
        if ($args === []) continue;

        $cmd = strtoupper($args[0]);
        $key = $args[1] ?? '';
        if (isset($expiry[$key]) && $expiry[$key] <= time()) {
            unset($store[$key], $expiry[$key]);
        }

        switch ($cmd) {
            case 'PING':   $reply = "+PONG\r\n"; break;
            case 'INCR':   $store[$key] = (int) ($store[$key] ?? 0) + 1;
                           $reply = ':' . $store[$key] . "\r\n"; break;
            case 'EXPIRE': if (!array_key_exists($key, $store)) { $reply = ":0\r\n"; break; }
                           $expiry[$key] = time() + (int) $args[2];
                           $reply = ":1\r\n"; break;
            case 'TTL':    $reply = ':' . (isset($expiry[$key]) ? max(0, $expiry[$key] - time()) : -1) . "\r\n"; break;
            case 'GET':    $reply = array_key_exists($key, $store)
                               ? '$' . strlen((string) $store[$key]) . "\r\n" . $store[$key] . "\r\n"
                               : "$-1\r\n"; break;
            case 'DEL':    $had = array_key_exists($key, $store);
                           unset($store[$key], $expiry[$key]);
                           $reply = ':' . ($had ? 1 : 0) . "\r\n"; break;
            default:       $reply = "-ERR nieobsługiwane: $cmd\r\n";
        }
        fwrite($sock, $reply);
    }
}
@unlink($path);
