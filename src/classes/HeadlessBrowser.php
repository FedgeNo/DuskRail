<?php

declare(strict_types=1);

/**
 * Resolves a JS bot-challenge interstitial (Cloudflare's "Just a moment...",
 * similar from other WAFs) by handing already-fetched HTML to a tab in the
 * shared, persistent Chrome instance (ChromeProcess/ChromeTab) and letting it
 * run the challenge script. Fetching the page for real is ChromeConnection's
 * job now (see its docblock) - this class never does a top-level fetch of
 * its own either way. Instead it navigates a fresh tab to the real URL and,
 * via the Fetch domain, intercepts that one top-level document request and
 * fulfills it locally with $html - so the page ends up with the real origin
 * (cookies, relative URLs, same-origin fetches all resolve correctly)
 * without a second independent request to the target host. Everything the
 * challenge script itself triggers afterwards (its own verification fetch,
 * redirect, reload) is real network activity - that's unavoidable, since
 * defeating the challenge is the entire point.
 */
class HeadlessBrowser
{
    private const HANDSHAKE_TIMEOUT_SECONDS = 3.0;

    // Kept short deliberately - the total (this plus whatever the caller's
    // own per-item budget already spent) must stay comfortably under
    // bin/crawler-manager.php's 30-second hang-kill, so a slow/stuck
    // challenge fails this attempt on its own rather than costing the whole
    // worker a hang strike.
    private const NAVIGATION_TIMEOUT_SECONDS = 10.0;

    // Extra grace period after load, folded into NAVIGATION_TIMEOUT_SECONDS
    // above rather than added on top of it - plenty of challenge scripts run
    // their verification round trip and auto-reload a few seconds after the
    // interstitial's own load event, not before it.
    private const SETTLE_SECONDS = 4.0;

    private const EVALUATE_TIMEOUT_SECONDS = 3.0;

    // Same fake identity ChromeConnection presents - real Chrome's own UA
    // says "HeadlessChrome" outright, which would be visible to (and could
    // itself trip) the very challenge script this is trying to get past.
    private const CHROME_VERSION = '149';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/' . self::CHROME_VERSION . '.0.0.0 Safari/537.36';

    /**
     * The resolved page's outer HTML, or null if the shared browser isn't
     * reachable, or if anything along the way (navigation, the challenge
     * itself) didn't finish inside its budget.
     */
    public static function resolveChallenge(string $hostAndPort, string $html, URL $pageURL): ?string
    {
        try {
            $tab = new ChromeTab($hostAndPort, self::HANDSHAKE_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        try {
            return self::run($tab, $html, $pageURL);
        } catch (\Throwable) {
            return null;
        } finally {
            $tab -> close();
        }
    }

    /**
     * Drives one navigation end to end: enable interception, navigate,
     * fulfill the top-level document request with $html, wait for the page
     * (and, per SETTLE_SECONDS, whatever the challenge does right after) to
     * settle, then read back the resulting DOM.
     */
    private static function run(ChromeTab $tab, string $html, URL $pageURL): ?string
    {
        $pageURLString = $pageURL -> toString();

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
        $tab -> sendCommand('Fetch.enable', ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']]]);
        $tab -> sendCommand('Page.enable');
        $tab -> sendCommand('Page.navigate', ['url' => $pageURLString]);

        $mainDocumentFulfilled = false;
        $loadEventFired = false;
        $settleDeadline = null;
        $overallDeadline = microtime(true) + self::NAVIGATION_TIMEOUT_SECONDS;

        while (true) {
            $deadline = $settleDeadline !== null ? min($overallDeadline, $settleDeadline) : $overallDeadline;
            $message = $tab -> receiveMessage($deadline);

            if ($message === null) {
                break;
            }

            if (($message['method'] ?? null) === 'Fetch.requestPaused') {
                self::handleRequestPaused($tab, $message['params'], $pageURLString, $html, $mainDocumentFulfilled);
                continue;
            }

            if (($message['method'] ?? null) === 'Page.loadEventFired' && !$loadEventFired) {
                $loadEventFired = true;
                $settleDeadline = microtime(true) + self::SETTLE_SECONDS;
            }

            if ($settleDeadline !== null && microtime(true) >= $settleDeadline) {
                break;
            }
        }

        if (!$loadEventFired) {
            // Never even got past the interception/navigate stage - nothing
            // usable to read back, and the challenge is still whatever it
            // was before this attempt.
            return null;
        }

        $evaluateId = $tab -> sendCommand('Runtime.evaluate', [
            'expression' => 'document.documentElement.outerHTML',
            'returnByValue' => true,
        ]);

        $response = $tab -> waitUntil(
            fn (array $message): bool => ($message['id'] ?? null) === $evaluateId,
            microtime(true) + self::EVALUATE_TIMEOUT_SECONDS
        );

        return $response['result']['result']['value'] ?? null;
    }

    private static function handleRequestPaused(ChromeTab $tab, array $params, string $pageURLString, string $html, bool &$mainDocumentFulfilled): void
    {
        $requestId = $params['requestId'];
        $isMainDocument = !$mainDocumentFulfilled
            && ($params['resourceType'] ?? null) === 'Document'
            && ($params['request']['url'] ?? null) === $pageURLString;

        if ($isMainDocument) {
            $mainDocumentFulfilled = true;
            $tab -> sendCommand('Fetch.fulfillRequest', [
                'requestId' => $requestId,
                'responseCode' => 200,
                'responseHeaders' => [['name' => 'Content-Type', 'value' => 'text/html; charset=utf-8']],
                'body' => base64_encode($html),
            ]);

            return;
        }

        // Everything else (the challenge's own scripts, its verification
        // fetch, images, iframes, ...) proceeds over the real network
        // unchanged - only the top-level document is ever swapped out here.
        $tab -> sendCommand('Fetch.continueRequest', ['requestId' => $requestId]);
    }
}
