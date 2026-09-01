<?php

declare(strict_types=1);

/**
 * One page target (tab) opened in an already-running, shared Chrome instance
 * (see ChromeProcess) - callers (ChromeConnection, HeadlessBrowser) get their
 * own isolated CDP session this way without paying for a whole new browser
 * process per fetch. Opening/closing a tab is cheap; the tab is always
 * closed (close()) once its caller is done so a persistent browser running
 * for an hour doesn't accumulate one leaked tab per item crawled.
 *
 * Created inside its own fresh browser context (Target.createBrowserContext -
 * Chrome's incognito-style isolation, not the default shared one every plain
 * "/json/new" tab would otherwise land in) - two unrelated items crawled an
 * hour apart on the same host would otherwise share cookies/localStorage
 * through nothing but both happening to use the same long-lived browser,
 * which isn't what a fresh, unrelated fetch of either one should look like.
 * disposeBrowserContext() in close() tears down everything created within it
 * (this tab included) in one step.
 */
class ChromeTab
{
    private const HTTP_TIMEOUT_SECONDS = 3;
    private const CONTEXT_TIMEOUT_SECONDS = 3.0;

    private WebSocketClient $browserWs;
    private int $nextBrowserCommandId = 1;
    private string $browserContextId;
    private WebSocketClient $ws;
    private int $nextCommandId = 1;

    public function __construct(string $hostAndPort, float $handshakeTimeoutSeconds)
    {
        $browserWsURL = self::browserWebSocketURL($hostAndPort);

        if ($browserWsURL === null) {
            throw new \RuntimeException('Failed to discover the Chrome browser-level DevTools endpoint');
        }

        $this -> browserWs = new WebSocketClient($browserWsURL, $handshakeTimeoutSeconds);

        try {
            $this -> browserContextId = $this -> createBrowserContext();

            $targetId = $this -> createTarget($this -> browserContextId);
            $pageWsURL = 'ws://' . $hostAndPort . '/devtools/page/' . $targetId;
            $this -> ws = new WebSocketClient($pageWsURL, $handshakeTimeoutSeconds);
        } catch (\Throwable $exception) {
            // Whatever already exists inside the shared browser has to be torn
            // down right here: a caller that never received a usable tab has
            // no close() to call, so an orphaned context would sit there for
            // the rest of that browser's hour-long life.
            if (isset($this -> browserContextId)) {
                $this -> disposeBrowserContext();
            }

            $this -> browserWs -> close();

            throw $exception;
        }
    }

    /**
     * (object) forces {} rather than [] for an empty $params (e.g.
     * Page.enable) - json_encode() of a plain empty PHP array is always
     * "[]", which CDP rejects as a malformed params object. Casting only
     * affects the top level, so nested array values still encode as real
     * JSON arrays.
     */
    public function sendCommand(string $method, array $params = []): int
    {
        $id = $this -> nextCommandId++;
        $this -> ws -> sendText(json_encode(['id' => $id, 'method' => $method, 'params' => (object) $params]));

        return $id;
    }

    /**
     * One decoded CDP message (an event, or a command's response), or null
     * if $deadline passes or the tab's connection closes first.
     */
    public function receiveMessage(float $deadline): ?array
    {
        $raw = $this -> ws -> receiveMessage($deadline);

        if ($raw === null) {
            return null;
        }

        $message = json_decode($raw, true);

        return is_array($message) ? $message : null;
    }

    /**
     * Pumps messages, handing every one to $onMessage, until either it
     * returns true (this is the response/event the caller was waiting for)
     * or $deadline passes (returns null either way).
     */
    public function waitUntil(callable $onMessage, float $deadline): ?array
    {
        while (true) {
            $message = $this -> receiveMessage($deadline);

            if ($message === null) {
                return null;
            }

            if ($onMessage($message) === true) {
                return $message;
            }
        }
    }

    public function close(): void
    {
        $this -> ws -> close();
        $this -> disposeBrowserContext();
        $this -> browserWs -> close();
    }

    private function createBrowserContext(): string
    {
        $id = $this -> sendBrowserCommand('Target.createBrowserContext');
        $response = $this -> waitForBrowserResponse($id, microtime(true) + self::CONTEXT_TIMEOUT_SECONDS);
        $contextId = $response['result']['browserContextId'] ?? null;

        if ($contextId === null) {
            throw new \RuntimeException('Failed to create a Chrome browser context');
        }

        // Nothing this browser does should ever put a file on disk. A page
        // that renders for real (HeadlessBrowser::resolveChallenge()) can
        // start a download without being asked to - a Content-Disposition
        // header on any subresource is enough - and it would land in the
        // service user's home directory, unbounded and read by nothing.
        // Sent without waiting for the reply: CDP answers commands in order,
        // so this is in force before the target created next exists.
        $this -> sendBrowserCommand('Browser.setDownloadBehavior', [
            'behavior' => 'deny',
            'browserContextId' => $contextId,
        ]);

        return $contextId;
    }

    private function createTarget(string $browserContextId): string
    {
        $id = $this -> sendBrowserCommand('Target.createTarget', [
            'url' => 'about:blank',
            'browserContextId' => $browserContextId,
        ]);
        $response = $this -> waitForBrowserResponse($id, microtime(true) + self::CONTEXT_TIMEOUT_SECONDS);
        $targetId = $response['result']['targetId'] ?? null;

        if ($targetId === null) {
            throw new \RuntimeException('Failed to create a Chrome page target');
        }

        return $targetId;
    }

    private function disposeBrowserContext(): void
    {
        $id = $this -> sendBrowserCommand('Target.disposeBrowserContext', ['browserContextId' => $this -> browserContextId]);
        $this -> waitForBrowserResponse($id, microtime(true) + self::CONTEXT_TIMEOUT_SECONDS);
    }

    private function sendBrowserCommand(string $method, array $params = []): int
    {
        $id = $this -> nextBrowserCommandId++;
        $this -> browserWs -> sendText(json_encode(['id' => $id, 'method' => $method, 'params' => (object) $params]));

        return $id;
    }

    private function waitForBrowserResponse(int $id, float $deadline): ?array
    {
        while (true) {
            $raw = $this -> browserWs -> receiveMessage($deadline);

            if ($raw === null) {
                return null;
            }

            $message = json_decode($raw, true);

            if (is_array($message) && ($message['id'] ?? null) === $id) {
                return $message;
            }
        }
    }

    /**
     * The browser-level DevTools URL, discovered once per process. It's a
     * property of the running browser, not of a tab, and never changes while
     * that browser lives - so paying an HTTP round trip for it on every
     * single tab was one avoidable request per fetch.
     */
    private static function browserWebSocketURL(string $hostAndPort): ?string
    {
        static $cache = [];

        if (isset($cache[$hostAndPort])) {
            return $cache[$hostAndPort];
        }

        $ch = curl_init('http://' . $hostAndPort . '/json/version');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::HTTP_TIMEOUT_SECONDS,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $url = $decoded['webSocketDebuggerUrl'] ?? null;

        if ($url !== null) {
            $cache[$hostAndPort] = $url;
        }

        return $url;
    }
}
