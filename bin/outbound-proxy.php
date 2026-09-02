<?php

declare(strict_types=1);

/**
 * Private SOCKS5 boundary for the shared Chromium process. Not a general
 * proxy: it binds only to loopback, accepts CONNECT only, refuses empty or
 * mixed/private DNS answers, and connects to one address from the exact set
 * that passed validation. Chromium is configured not to resolve destinations
 * itself, preserving the hostname solely for TLS SNI/certificate checks.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "ext-pcntl is required by the Chromium outbound proxy.\n");
    exit(1);
}

const PROXY_HANDSHAKE_TIMEOUT_SECONDS = 5;
const PROXY_CONNECT_TIMEOUT_SECONDS = 10;
const PROXY_IDLE_TIMEOUT_SECONDS = 30;
const PROXY_MAX_CHILDREN = 64;

$parentPid = max(1, (int) ($argv[1] ?? 0));
$errorNumber = 0;
$errorMessage = '';
$server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

if ($server === false) {
    fwrite(STDERR, 'Could not bind outbound proxy: ' . $errorMessage . "\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);

if (!is_string($address)) {
    fwrite(STDERR, "Could not discover outbound proxy address.\n");
    exit(1);
}

echo $address . "\n";
flush();

$children = [];
pcntl_async_signals(true);
pcntl_signal(SIGTERM, static function () use ($server): void {
    fclose($server);
    exit(0);
});
pcntl_signal(SIGINT, static function () use ($server): void {
    fclose($server);
    exit(0);
});
pcntl_signal(SIGCHLD, static function () use (&$children): void {
    while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        unset($children[$pid]);
    }
});

while (is_resource($server)) {
    // A SIGKILLed manager cannot run OutboundProxyProcess::shutdown(). The
    // proxy notices its owner disappearing and exits rather than leaving an
    // unaffiliated loopback listener behind indefinitely.
    if (!is_dir('/proc/' . $parentPid)) {
        break;
    }

    $read = [$server];
    $write = null;
    $except = null;

    if (@stream_select($read, $write, $except, 1) !== 1) {
        continue;
    }

    $client = @stream_socket_accept($server, 0);

    if ($client === false) {
        continue;
    }

    if (count($children) >= PROXY_MAX_CHILDREN) {
        fclose($client);
        continue;
    }

    $pid = pcntl_fork();

    if ($pid === -1) {
        fclose($client);
        continue;
    }

    if ($pid > 0) {
        $children[$pid] = true;
        fclose($client);
        continue;
    }

    fclose($server);
    proxy_handle_client($client);
    fclose($client);
    exit(0);
}

fclose($server);

/** @param resource $client */
function proxy_handle_client($client): void
{
    proxy_debug('client accepted');
    stream_set_timeout($client, PROXY_HANDSHAKE_TIMEOUT_SECONDS);
    $header = proxy_read_exact($client, 2);

    if ($header === null || ord($header[0]) !== 5) {
        proxy_debug('invalid greeting');
        return;
    }

    $methods = proxy_read_exact($client, ord($header[1]));

    if ($methods === null || !str_contains($methods, "\x00")) {
        proxy_debug('no supported authentication method');
        fwrite($client, "\x05\xff");
        return;
    }

    fwrite($client, "\x05\x00");
    $request = proxy_read_exact($client, 4);

    if ($request === null || ord($request[0]) !== 5 || ord($request[1]) !== 1) {
        proxy_debug('unsupported request');
        proxy_reply($client, 7);
        return;
    }

    $type = ord($request[3]);
    $host = null;

    if ($type === 1) {
        $raw = proxy_read_exact($client, 4);
        $host = $raw === null ? null : inet_ntop($raw);
    } elseif ($type === 3) {
        $length = proxy_read_exact($client, 1);
        $host = $length === null ? null : proxy_read_exact($client, ord($length));
    } elseif ($type === 4) {
        $raw = proxy_read_exact($client, 16);
        $host = $raw === null ? null : inet_ntop($raw);
    }

    $rawPort = proxy_read_exact($client, 2);
    $port = $rawPort === null ? 0 : unpack('n', $rawPort)[1];

    if (!is_string($host) || $host === '' || $port < 1) {
        proxy_debug('invalid destination');
        proxy_reply($client, 8);
        return;
    }

    $addresses = IPAddress::publicAddressesFor($host);
    proxy_debug('request ' . $host . ':' . $port . ' approved=' . ($addresses === [] ? 'no' : $addresses[0]));

    if ($addresses === []) {
        proxy_reply($client, 2);
        return;
    }

    $address = $addresses[0];
    $endpoint = str_contains($address, ':') ? '[' . $address . ']:' . $port : $address . ':' . $port;
    $errorNumber = 0;
    $errorMessage = '';
    $upstream = @stream_socket_client(
        'tcp://' . $endpoint,
        $errorNumber,
        $errorMessage,
        PROXY_CONNECT_TIMEOUT_SECONDS,
        STREAM_CLIENT_CONNECT
    );

    if ($upstream === false) {
        proxy_debug('connect failed: ' . $errorMessage);
        proxy_reply($client, 5);
        return;
    }

    proxy_reply($client, 0);
    proxy_debug('connected');
    stream_set_blocking($client, false);
    stream_set_blocking($upstream, false);
    $lastActivity = microtime(true);

    while (microtime(true) - $lastActivity < PROXY_IDLE_TIMEOUT_SECONDS) {
        $read = [$client, $upstream];
        $write = null;
        $except = null;

        if (@stream_select($read, $write, $except, 1) === false) {
            break;
        }

        foreach ($read as $source) {
            $chunk = fread($source, 65536);

            if ($chunk === false) {
                fclose($upstream);
                return;
            }

            if ($chunk === '' && feof($source)) {
                fclose($upstream);
                return;
            }

            if ($chunk === '') {
                continue;
            }

            $destination = $source === $client ? $upstream : $client;

            if (!proxy_write_all($destination, $chunk)) {
                fclose($upstream);
                return;
            }

            $lastActivity = microtime(true);
        }
    }

    fclose($upstream);
}

/** @param resource $stream */
function proxy_read_exact($stream, int $length): ?string
{
    $data = '';

    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $data .= $chunk;
    }

    return $data;
}

/** @param resource $stream */
function proxy_write_all($stream, string $data): bool
{
    while ($data !== '') {
        $written = fwrite($stream, $data);

        if ($written === false || $written === 0) {
            return false;
        }

        $data = substr($data, $written);
    }

    return true;
}

/** @param resource $client */
function proxy_reply($client, int $status): void
{
    fwrite($client, "\x05" . chr($status) . "\x00\x01\x00\x00\x00\x00\x00\x00");
}

function proxy_debug(string $message): void
{
    if (getenv('DUSKRAIL_PROXY_DEBUG') === '1') {
        fwrite(STDERR, '[outbound-proxy] ' . $message . "\n");
    }
}
