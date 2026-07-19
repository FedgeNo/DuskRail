<?php

declare(strict_types=1);

/**
 * Launches and owns one long-lived headless Chrome process - the "host:port"
 * it exposes is shared by every fetch the crawler makes (ChromeConnection,
 * HeadlessBrowser), each opening its own tab (ChromeTab) against it rather
 * than paying Chrome's ~1-2 second startup cost per item. Only
 * bin/crawler-manager.php ever constructs one: it launches this once, health
 * -checks it every supervisory tick, and replaces it periodically (see
 * MAX_AGE_SECONDS) - a crashed or stale browser is this class's problem to
 * report via isHealthy(), not something callers work around themselves.
 */
class ChromeProcess
{
    private const CANDIDATE_BINARIES = ['chromium-browser', 'google-chrome', 'chromium', 'google-chrome-stable'];
    private const LAUNCH_TIMEOUT_SECONDS = 5.0;
    private const HEALTH_CHECK_TIMEOUT_SECONDS = 3;

    /** @var resource */
    private $process;
    private array $pipes = [];
    private string $userDataDir = '';
    private float $startedAt;

    public readonly string $hostAndPort;

    public function __construct()
    {
        $binary = self::findBinary();

        if ($binary === null) {
            throw new \RuntimeException('No headless Chrome/Chromium binary found');
        }

        $this -> userDataDir = sys_get_temp_dir() . '/duskrail-chrome-' . bin2hex(random_bytes(8));
        mkdir($this -> userDataDir, 0700, true);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([
            $binary,
            '--headless=new',
            '--disable-gpu',
            '--remote-debugging-port=0',
            '--user-data-dir=' . $this -> userDataDir,
            'about:blank',
        ], $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Couldn\'t start ' . $binary);
        }

        $this -> process = $process;
        $this -> pipes = $pipes;
        fclose($this -> pipes[0]);
        stream_set_blocking($this -> pipes[1], false);
        stream_set_blocking($this -> pipes[2], false);
        $this -> startedAt = microtime(true);

        $devtoolsURL = $this -> waitForDevtoolsURL();

        if ($devtoolsURL === null || !preg_match('#^ws://([^/]+)/#', $devtoolsURL, $match)) {
            $this -> shutdown();

            throw new \RuntimeException('Chrome DevTools never came up');
        }

        $this -> hostAndPort = $match[1];
    }

    public function ageSeconds(): float
    {
        return microtime(true) - $this -> startedAt;
    }

    /**
     * A real request against the browser's own HTTP endpoint, not just "is
     * the OS process still running" - a wedged-but-still-alive Chrome
     * (unresponsive but not crashed) would pass a bare proc_get_status()
     * check while every fetch through it hangs.
     */
    public function isHealthy(): bool
    {
        if (!proc_get_status($this -> process)['running']) {
            return false;
        }

        $ch = curl_init('http://' . $this -> hostAndPort . '/json/version');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::HEALTH_CHECK_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::HEALTH_CHECK_TIMEOUT_SECONDS,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        return is_string($response) && $response !== '';
    }

    public function shutdown(): void
    {
        if (isset($this -> process) && is_resource($this -> process)) {
            $this -> killProcessTree();

            foreach ($this -> pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($this -> process);
        }

        if ($this -> userDataDir !== '' && is_dir($this -> userDataDir)) {
            self::removeDirectory($this -> userDataDir);
        }
    }

    private function waitForDevtoolsURL(): ?string
    {
        $deadline = microtime(true) + self::LAUNCH_TIMEOUT_SECONDS;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fread($this -> pipes[2], 8192);

            if ($chunk !== false && $chunk !== '' && preg_match('#DevTools listening on (ws://\S+)#', $buffer .= $chunk, $match)) {
                return $match[1];
            }

            if (!proc_get_status($this -> process)['running']) {
                return null;
            }

            usleep(50_000);
        }

        return null;
    }

    private static function findBinary(): ?string
    {
        $config = require ROOT_DIR . '/src/config.php';

        if ($config['chromeBinary'] !== '') {
            return is_executable($config['chromeBinary']) ? $config['chromeBinary'] : null;
        }

        foreach (self::CANDIDATE_BINARIES as $name) {
            $resolved = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

            if ($resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    private function killProcessTree(): void
    {
        if (!proc_get_status($this -> process)['running']) {
            return;
        }

        proc_terminate($this -> process, SIGTERM);

        for ($i = 0; $i < 20; $i++) {
            usleep(100_000);

            if (!proc_get_status($this -> process)['running']) {
                return;
            }
        }

        proc_terminate($this -> process, SIGKILL);
    }

    private static function removeDirectory(string $path): void
    {
        $entries = @scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            if (is_dir($full) && !is_link($full)) {
                self::removeDirectory($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
