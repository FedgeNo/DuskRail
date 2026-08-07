<?php

declare(strict_types=1);

/**
 * Minimal RFC 6455 client - just enough to drive Chrome DevTools Protocol
 * over its ws:// endpoint (HeadlessBrowser). No Composer/PSR-4 in this
 * project, so this hand-rolls the handshake + text-frame framing rather than
 * pulling in a package for it. Text frames only (CDP never sends binary),
 * client-to-server frames masked per spec, server-to-client assumed
 * unmasked (real per spec, but tolerated either way).
 */
class WebSocketClient
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    // How long one frame may spend waiting for the socket's send buffer to
    // drain before it's given up on. This is a real wait, not a formality -
    // a Fetch.fulfillRequest command carries a whole page's base64'd HTML,
    // far more than the kernel accepts in a single write.
    private const WRITE_TIMEOUT_SECONDS = 10.0;

    // How much to pull off the socket at once when more is available than the
    // caller asked for. A page's base64'd HTML arrives in many segments, and
    // reading it in frame-sized slices was a syscall per slice.
    private const READ_CHUNK_SIZE = 65536;

    /** @var resource */
    private $socket;

    public function __construct(string $url, float $timeoutSeconds)
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host'], $parts['port'])) {
            throw new \RuntimeException('Not a valid ws:// URL: ' . $url);
        }

        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $socket = @stream_socket_client(
            'tcp://' . $parts['host'] . ':' . $parts['port'],
            $errno,
            $errstr,
            $timeoutSeconds
        );

        if ($socket === false) {
            throw new \RuntimeException('Couldn\'t connect to ' . $url . ': ' . $errstr);
        }

        $this -> socket = $socket;
        stream_set_blocking($this -> socket, false);

        $key = base64_encode(random_bytes(16));

        $this -> writeAll(implode("\r\n", [
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . $parts['host'] . ':' . $parts['port'],
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            "\r\n",
        ]));

        $deadline = microtime(true) + $timeoutSeconds;
        $response = '';

        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = $this -> readExactly(1, $deadline);

            if ($chunk === null) {
                fclose($this -> socket);

                throw new \RuntimeException('Timed out waiting for the WebSocket handshake response from ' . $url);
            }

            $response .= $chunk;
        }

        $expectedAccept = base64_encode(sha1($key . self::GUID, true));

        if (
            !str_starts_with($response, 'HTTP/1.1 101')
            || !preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $response, $match)
            || $match[1] !== $expectedAccept
        ) {
            fclose($this -> socket);

            throw new \RuntimeException('WebSocket handshake with ' . $url . ' failed or returned a bad Sec-WebSocket-Accept');
        }
    }

    public function close(): void
    {
        if (is_resource($this -> socket)) {
            fclose($this -> socket);
        }
    }

    /**
     * Sends one unfragmented masked text frame - every CDP command this
     * class ever sends fits in a single frame, so fragmentation on the way
     * out is never needed.
     */
    public function sendText(string $payload): void
    {
        $length = strlen($payload);
        $frame = chr(0x81); // FIN + text opcode

        if ($length <= 125) {
            $frame .= chr($length | 0x80);
        } elseif ($length <= 0xFFFF) {
            $frame .= chr(126 | 0x80) . pack('n', $length);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        // String XOR is applied byte-for-byte between two equal-length
        // strings - repeating the 4-byte mask out to the payload's length
        // turns the masking into one C-level operation instead of a
        // byte-by-byte PHP loop, which matters here since a fulfilled
        // Fetch response's body is the whole page's base64'd HTML.
        $frame .= $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        $this -> writeAll($frame);
    }

    /**
     * One complete message (all continuation frames concatenated), or null
     * if $deadline passes or the socket closes first. Pings are answered
     * with a pong and otherwise ignored; a close frame ends the read as if
     * the deadline had passed, since there's nothing more to receive.
     */
    public function receiveMessage(float $deadline): ?string
    {
        $message = '';

        while (true) {
            $frame = $this -> readFrame($deadline);

            if ($frame === null) {
                return null;
            }

            if ($frame['opcode'] === 0x8) { // close
                return null;
            }

            if ($frame['opcode'] === 0x9) { // ping
                $this -> sendPong($frame['payload']);
                continue;
            }

            if ($frame['opcode'] === 0xA) { // pong
                continue;
            }

            $message .= $frame['payload'];

            if ($frame['fin']) {
                return $message;
            }
        }
    }

    private function sendPong(string $payload): void
    {
        $length = strlen($payload);
        $mask = random_bytes(4);
        $frame = chr(0x8A) . chr($length | 0x80) . $mask;
        $frame .= $payload ^ substr(str_repeat($mask, intdiv($length, 4) + 1), 0, $length);

        $this -> writeAll($frame);
    }

    /**
     * Writes every byte, however many fwrite() calls that takes. The socket is
     * non-blocking, so a single fwrite() only ever writes what fits in the
     * kernel's send buffer right now and reports how much that was - the rest
     * is simply dropped unless it's written again once the buffer drains.
     * Frames here routinely exceed that buffer (a fulfilled Fetch response's
     * base64'd page HTML), and a half-written frame doesn't just lose its own
     * tail: the peer reads the next frame's header out of the middle of it,
     * and the connection is desynchronized from then on.
     */
    private function writeAll(string $payload): void
    {
        $deadline = microtime(true) + self::WRITE_TIMEOUT_SECONDS;

        while ($payload !== '') {
            $written = fwrite($this -> socket, $payload);

            if ($written === false) {
                return;
            }

            $payload = substr($payload, $written);

            if ($payload === '') {
                return;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return;
            }

            $read = null;
            $write = [$this -> socket];
            $except = null;

            if (@stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000)) === false) {
                return;
            }
        }
    }

    /**
     * @return array{fin: bool, opcode: int, payload: string}|null
     */
    private function readFrame(float $deadline): ?array
    {
        $header = $this -> readExactly(2, $deadline);

        if ($header === null) {
            return null;
        }

        $firstByte = ord($header[0]);
        $secondByte = ord($header[1]);
        $fin = ($firstByte & 0x80) !== 0;
        $opcode = $firstByte & 0x0F;
        $masked = ($secondByte & 0x80) !== 0;
        $length = $secondByte & 0x7F;

        if ($length === 126) {
            $extended = $this -> readExactly(2, $deadline);

            if ($extended === null) {
                return null;
            }

            $length = unpack('n', $extended)[1];
        } elseif ($length === 127) {
            $extended = $this -> readExactly(8, $deadline);

            if ($extended === null) {
                return null;
            }

            $length = unpack('J', $extended)[1];
        }

        $maskKey = null;

        if ($masked) {
            $maskKey = $this -> readExactly(4, $deadline);

            if ($maskKey === null) {
                return null;
            }
        }

        $payload = $length > 0 ? $this -> readExactly($length, $deadline) : '';

        if ($payload === null) {
            return null;
        }

        if ($masked) {
            $payload = $payload ^ substr(str_repeat($maskKey, intdiv($length, 4) + 1), 0, $length);
        }

        return ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload];
    }

    /**
     * Bytes already read past what a caller asked for. A CDP response and the
     * events around it routinely arrive in one TCP segment, and reading
     * exactly the requested count meant a stream_select() syscall per frame
     * header, per length field, per payload - most of them returning
     * instantly from data already in userspace. Anything over-read is kept
     * here and served without touching the socket at all.
     */
    private string $buffer = '';

    private function readExactly(int $byteCount, float $deadline): ?string
    {
        while (strlen($this -> buffer) < $byteCount) {
            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return null;
            }

            $read = [$this -> socket];
            $write = null;
            $except = null;
            $selected = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1_000_000));

            if ($selected === false || $selected === 0) {
                return null;
            }

            // Deliberately more than the caller needs: whatever else the peer
            // has already sent comes along for free rather than costing
            // another wait.
            $chunk = fread($this -> socket, max($byteCount - strlen($this -> buffer), self::READ_CHUNK_SIZE));

            if ($chunk === false) {
                return null;
            }

            if ($chunk === '') {
                if (feof($this -> socket)) {
                    return null;
                }

                continue;
            }

            $this -> buffer .= $chunk;
        }

        $data = substr($this -> buffer, 0, $byteCount);
        $this -> buffer = substr($this -> buffer, $byteCount);

        return $data;
    }
}
