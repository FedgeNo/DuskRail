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

    // Chrome's cold start is normally a second or two, but a machine that's
    // already busy crawling - or one that just tore down the outgoing
    // instance of a rotation - can take considerably longer. Too tight a
    // budget here doesn't fail safe: it reports a browser that was coming up
    // fine as dead, kills it, and starts another one behind it.
    private const LAUNCH_TIMEOUT_SECONDS = 20.0;

    private const HEALTH_CHECK_TIMEOUT_SECONDS = 3;

    // Every instance's private profile directory lives under this prefix in
    // the system temp directory, with the browser's pid written inside - so a
    // manager starting up can find and clean up after one that was killed
    // outright and never got to shut its own browser down (see
    // sweepAbandoned()).
    private const USER_DATA_DIR_PREFIX = 'duskrail-chrome-';
    private const PID_FILE = 'duskrail-chrome.pid';

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

        $this -> userDataDir = sys_get_temp_dir() . '/' . self::USER_DATA_DIR_PREFIX . bin2hex(random_bytes(8));
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
        file_put_contents($this -> userDataDir . '/' . self::PID_FILE, (string) proc_get_status($process)['pid']);
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
     * Kills any browser left behind by a previous manager and removes its
     * profile directory. shutdown() handles the ordinary exits; this is for
     * the ones where the manager never got to run it - SIGKILL, a crash, a
     * machine that went down - after which the browser it owned goes on
     * running, holding its memory and its port, with nothing left that knows
     * about it. Called once at startup, before the first instance is
     * launched.
     *
     * The pid is only acted on once /proc confirms it's still the same
     * browser: pids get reused, and killing whatever happens to hold one now
     * because a dead Chrome held it an hour ago would be far worse than
     * leaving a stray process alone.
     */
    public static function sweepAbandoned(): int
    {
        $swept = 0;

        foreach (glob(sys_get_temp_dir() . '/' . self::USER_DATA_DIR_PREFIX . '*') ?: [] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $pidFile = $directory . '/' . self::PID_FILE;
            $pid = is_file($pidFile) ? (int) file_get_contents($pidFile) : 0;

            if ($pid > 0 && self::isStillThisBrowser($pid, $directory)) {
                kill_process($pid);
                $swept++;
            }

            self::removeDirectory($directory);
        }

        return $swept;
    }

    private static function isStillThisBrowser(int $pid, string $userDataDir): bool
    {
        $commandLine = @file_get_contents('/proc/' . $pid . '/cmdline');

        return is_string($commandLine) && str_contains($commandLine, '--user-data-dir=' . $userDataDir);
    }

    /**
     * Reads and discards whatever Chrome has written to stdout/stderr since
     * the last call. Chrome logs continuously (and noisily, on a page that
     * upsets it), and a pipe nobody ever reads from stops accepting writes
     * once it holds a bufferful - at which point Chrome's own write() blocks
     * and the entire browser wedges, for a reason nothing else here could
     * diagnose. bin/crawler-manager.php calls this every supervisory tick,
     * for every generation it's still holding, so the pipes never get near
     * that point.
     */
    public function drainOutput(): void
    {
        foreach ($this -> pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_get_contents($pipe);
            }
        }
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
