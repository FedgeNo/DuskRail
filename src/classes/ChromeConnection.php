<?php

declare(strict_types=1);

/**
 * Fetches one URL as a real navigation in the shared, persistent Chrome
 * instance (ChromeProcess/ChromeTab) - an actual Chrome-originated request,
 * so the TLS handshake, HTTP/2 framing, and every header are genuinely
 * Chrome's. HTTPConnection (plain cURL) handles fetches that aren't real
 * crawler traffic against the target site at all (robots.txt, and similar).
 *
 * Deliberately does NOT let Chrome auto-follow a redirect or render the page
 * - bin/crawler.php's own per-hop loop (robots.txt check, politeness
 * recording) stays fully in control, constructing one of these per hop:
 * Fetch domain is paused at both the Request and Response stage, scoped to
 * resourceType "Document" only. The Request-stage pause is let through
 * unconditionally (bin/crawler.php already decided this URL is worth
 * fetching before constructing this class); the Response-stage pause is
 * where the real status/headers are read - a 2xx has its body pulled via
 * Fetch.getResponseBody, anything else (a redirect, an error status) is
 * failed immediately since nothing downstream needs an error page's body.
 * Either way the request is always ultimately failed rather than continued,
 * so Chrome never proceeds to parse/render the document or fetch anything
 * it references - confirmed empirically that this alone is enough to
 * prevent any subresource requests, without needing to separately intercept
 * and block them one by one.
 */
class ChromeConnection
{
    private const NAVIGATION_TIMEOUT_SECONDS = 15.0;
    private const HANDSHAKE_TIMEOUT_SECONDS = 3.0;

    // Matches HTTPConnection's own cap - a backstop against a truly extreme
    // response, not a tight budget.
    private const MAX_BODY_SIZE = 20 * 1024 * 1024;

    // Same identity HTTPConnection presents - kept here too (instead of a
    // shared constant) since this class overrides Chrome's own real UA
    // entirely, for a different reason: Chrome's real one says
    // "HeadlessChrome" and a Linux platform outright, which is a bot tell no
    // amount of header-order tuning fixes.
    private const CHROME_VERSION = '149';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/' . self::CHROME_VERSION . '.0.0.0 Safari/537.36';

    public ?int $statusCode = null;
    public array $headers = [];
    public bool $bodyTruncated = false;
    private string $body = '';

    /**
     * $referrer, when given, is passed to Chrome as the navigation's real
     * referrer - it sets the actual Referer header and lets Chrome derive
     * Sec-Fetch-Site itself, rather than this class guessing either.
     */
    public function __construct(string $hostAndPort, URL $url, ?URL $referrer = null)
    {
        $tab = new ChromeTab($hostAndPort, self::HANDSHAKE_TIMEOUT_SECONDS);

        try {
            $this -> fetch($tab, $url, $referrer);
        } finally {
            $tab -> close();
        }
    }

    /**
     * The parsed Content-Type header (e.g. "text/html; charset=UTF-8"), or
     * null if the response didn't send one.
     */
    public function contentType(): ?ContentType
    {
        return isset($this -> headers['content-type']) ? new ContentType($this -> headers['content-type']) : null;
    }

    /**
     * The body is always already fully in hand by the time the constructor
     * returns (Fetch.getResponseBody has to be called before the paused
     * response is let go, so there's no "pause here, read on demand later"
     * option the way curl's pause/resume gave HTTPConnection) - this just
     * hands it back.
     */
    public function readBody(): string
    {
        return $this -> body;
    }

    private function fetch(ChromeTab $tab, URL $url, ?URL $referrer): void
    {
        $tab -> sendCommand('Network.setUserAgentOverride', [
            'userAgent' => self::USER_AGENT,
            'platform' => 'Win32',
            'userAgentMetadata' => [
                'brands' => [
                    ['brand' => 'Chromium', 'version' => self::CHROME_VERSION],
                    ['brand' => 'Not:A-Brand', 'version' => '24'],
                    ['brand' => 'Google Chrome', 'version' => self::CHROME_VERSION],
                ],
                'fullVersionList' => [
                    ['brand' => 'Chromium', 'version' => self::CHROME_VERSION . '.0.0.0'],
                    ['brand' => 'Not:A-Brand', 'version' => '24.0.0.0'],
                    ['brand' => 'Google Chrome', 'version' => self::CHROME_VERSION . '.0.0.0'],
                ],
                'platform' => 'Windows',
                'platformVersion' => '10.0.0',
                'architecture' => 'x86',
                'model' => '',
                'mobile' => false,
                'bitness' => '64',
            ],
        ]);
        $tab -> sendCommand('Fetch.enable', ['patterns' => [
            ['urlPattern' => '*', 'requestStage' => 'Request', 'resourceType' => 'Document'],
            ['urlPattern' => '*', 'requestStage' => 'Response', 'resourceType' => 'Document'],
        ]]);

        $navigateParams = ['url' => $url -> toString()];

        if ($referrer !== null) {
            $navigateParams['referrer'] = $referrer -> toString();
        }

        $navigateCommandId = $tab -> sendCommand('Page.navigate', $navigateParams);

        $bodyRequestId = null;
        $pendingRequestId = null;

        $tab -> waitUntil(function (array $message) use ($tab, $navigateCommandId, &$bodyRequestId, &$pendingRequestId): bool {
            if (($message['method'] ?? null) === 'Fetch.requestPaused') {
                return $this -> handleRequestPaused($tab, $message['params'], $bodyRequestId, $pendingRequestId);
            }

            if ($bodyRequestId !== null && ($message['id'] ?? null) === $bodyRequestId) {
                $this -> handleResponseBody($tab, $message, $pendingRequestId);

                return true;
            }

            // The navigation itself never reached a response at all - a DNS
            // failure, connection refused, TLS handshake failure, and so on.
            // Same "nothing usable" outcome as HTTPConnection's statusCode
            // staying null for the same class of failure.
            if (($message['id'] ?? null) === $navigateCommandId && isset($message['result']['errorText'])) {
                return true;
            }

            return false;
        }, microtime(true) + self::NAVIGATION_TIMEOUT_SECONDS);
    }

    private function handleRequestPaused(ChromeTab $tab, array $params, ?int &$bodyRequestId, ?string &$pendingRequestId): bool
    {
        if (!isset($params['responseStatusCode'])) {
            // Request stage - bin/crawler.php already decided this exact URL
            // is worth fetching (robots.txt, politeness) before constructing
            // this class, so there's nothing left to check here.
            $tab -> sendCommand('Fetch.continueRequest', ['requestId' => $params['requestId']]);

            return false;
        }

        $this -> statusCode = $params['responseStatusCode'];

        foreach ($params['responseHeaders'] as $header) {
            $this -> headers[strtolower($header['name'])] = $header['value'];
        }

        $contentLength = isset($this -> headers['content-length']) ? (int) $this -> headers['content-length'] : null;
        $isSuccess = $this -> statusCode >= 200 && $this -> statusCode < 300;

        if ($isSuccess && ($contentLength === null || $contentLength <= self::MAX_BODY_SIZE)) {
            $pendingRequestId = $params['requestId'];
            $bodyRequestId = $tab -> sendCommand('Fetch.getResponseBody', ['requestId' => $params['requestId']]);

            return false;
        }

        if ($isSuccess) {
            // Content-Length alone already rules out ever bringing this body
            // into memory - same backstop as MAX_BODY_SIZE, just decided
            // before downloading anything instead of after.
            $this -> bodyTruncated = true;
        }

        // A redirect or an error status - nothing downstream ever reads the
        // body for either (bin/crawler.php only drains/discards it), so
        // there's no reason to pull it out of Chrome at all.
        $tab -> sendCommand('Fetch.failRequest', ['requestId' => $params['requestId'], 'errorReason' => 'BlockedByClient']);

        return true;
    }

    private function handleResponseBody(ChromeTab $tab, array $message, string $pendingRequestId): void
    {
        if (isset($message['result'])) {
            $raw = $message['result']['base64Encoded']
                ? base64_decode($message['result']['body'])
                : $message['result']['body'];

            if (strlen($raw) > self::MAX_BODY_SIZE) {
                $this -> bodyTruncated = true;
            } else {
                $this -> body = $raw;
            }
        }

        // Always failed, never continued - the body's already in hand, and
        // letting Chrome proceed from here would mean it actually renders
        // the page and starts fetching everything it references.
        $tab -> sendCommand('Fetch.failRequest', ['requestId' => $pendingRequestId, 'errorReason' => 'BlockedByClient']);
    }
}
