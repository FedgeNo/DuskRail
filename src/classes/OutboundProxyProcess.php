<?php

declare(strict_types=1);

/**
 * Owns the loopback SOCKS5 proxy all Chromium traffic crosses. The proxy is
 * the point where a hostname is resolved, every answer is classified, and a
 * socket is opened to one exact approved address. Chromium still sees the
 * original hostname, so TLS SNI and certificate verification remain intact.
 */
class OutboundProxyProcess
{
    private const START_TIMEOUT_SECONDS = 5.0;

    /** @var resource */
    private $process;
    private array $pipes;

    public readonly string $hostAndPort;

    public function __construct()
    {
        $command = [PHP_BINARY, ROOT_DIR . '/bin/outbound-proxy.php', (string) getmypid()];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start the outbound proxy');
        }

        $this -> process = $process;
        $this -> pipes = $pipes;
        fclose($this -> pipes[0]);
        stream_set_blocking($this -> pipes[1], false);
        stream_set_blocking($this -> pipes[2], false);

        $deadline = microtime(true) + self::START_TIMEOUT_SECONDS;
        $line = '';

        while (microtime(true) < $deadline && !str_contains($line, "\n")) {
            $read = [$this -> pipes[1]];
            $write = null;
            $except = null;
            $seconds = max(0, (int) ceil($deadline - microtime(true)));

            if (stream_select($read, $write, $except, $seconds) > 0) {
                $line .= (string) fgets($this -> pipes[1]);
            }

            if (!proc_get_status($this -> process)['running']) {
                break;
            }
        }

        $endpoint = trim($line);

        if (preg_match('/^127\.0\.0\.1:[0-9]{1,5}$/', $endpoint) !== 1) {
            $error = trim(stream_get_contents($this -> pipes[2]));
            $this -> shutdown();
            throw new \RuntimeException('Outbound proxy failed to start' . ($error === '' ? '' : ': ' . $error));
        }

        $this -> hostAndPort = $endpoint;
    }

    public function isHealthy(): bool
    {
        return proc_get_status($this -> process)['running'];
    }

    public function drainOutput(): void
    {
        // stdout contains only the already-consumed startup endpoint. stderr
        // is diagnostic and must still be drained so a noisy failure cannot
        // fill the pipe and block the proxy parent.
        stream_get_contents($this -> pipes[1]);
        $error = stream_get_contents($this -> pipes[2]);

        if ($error !== '') {
            fwrite(STDERR, $error);
        }
    }

    public function shutdown(): void
    {
        if (!isset($this -> process)) {
            return;
        }

        if (proc_get_status($this -> process)['running']) {
            proc_terminate($this -> process);
        }

        foreach ($this -> pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($this -> process);
        unset($this -> process);
    }

    public function __destruct()
    {
        $this -> shutdown();
    }
}
