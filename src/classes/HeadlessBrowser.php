<?php

declare(strict_types=1);

/**
 * Renders already-fetched HTML, including JS bot-challenge interstitials,
 * in the shared Chrome instance before the crawler extracts content.
 * Fetching the page for real is ChromeConnection's
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
class HeadlessBrowser {
    private const HANDSHAKE_TIMEOUT_SECONDS = 3.0;

    // A navigation that never produces a loaded challenge document should
    // fail promptly; the challenge's own verification window follows.
    private const NAVIGATION_TIMEOUT_SECONDS = 10.0;

    // The challenge itself gets up to thirty seconds after its document has
    // loaded to complete verification, reload, or redirect.
    private const SETTLE_SECONDS = 30.0;
    private const DOCUMENT_SETTLE_SECONDS = 3.0;

    private const EVALUATE_TIMEOUT_SECONDS = 3.0;

    /**
     * Advertising and analytics requests compete with content scripts while
     * a document renders, without helping populate the searchable content.
     *
     * Matched as domain suffixes, so a subdomain is covered by its parent.
     */
    private const BLOCKED_HOST_SUFFIXES = [
        'doubleclick.net', 'googlesyndication.com', 'googleadservices.com',
        'google-analytics.com', 'googletagmanager.com', 'googletagservices.com',
        'adservice.google.com', 'adnxs.com', 'rubiconproject.com', 'pubmatic.com',
        'criteo.com', 'criteo.net', 'taboola.com', 'outbrain.com', 'openx.net',
        'scorecardresearch.com', 'quantserve.com', 'moatads.com', 'amazon-adsystem.com',
        'facebook.net', 'connect.facebook.net', 'hotjar.com', 'mixpanel.com',
        'segment.io', 'segment.com', 'newrelic.com', 'nr-data.net', 'branch.io',
        'chartbeat.com', 'parsely.com', 'sharethis.com', 'addthis.com', 'zdassets.com',
    ];

    // The exact third-party host a Cloudflare challenge may legitimately use.
    // Everything else must stay on the page's own registrable domain: a page
    // opts into this execution path with nothing more than its <title>, so it
    // does not get to turn the crawler into a request source for arbitrary
    // unrelated services.
    private const CHALLENGE_PROVIDER_HOSTS = [
        'challenges.cloudflare.com',
    ];

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
    public static function resolveChallenge(string $hostAndPort, string $html, URL $pageURL): ?string {
        return self::renderDocument($hostAndPort, $html, $pageURL, true);
    }

    public static function render(string $hostAndPort, string $html, URL $pageURL): ?string {
        return self::renderDocument($hostAndPort, $html, $pageURL, false);
    }

    private static function renderDocument(string $hostAndPort, string $html, URL $pageURL, bool $challenge): ?string {
        try {
            $tab = new ChromeTab($hostAndPort, self::HANDSHAKE_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        try {
            return self::run($tab, $html, $pageURL, $challenge);
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
    private static function run(ChromeTab $tab, string $html, URL $pageURL, bool $challenge): ?string {
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
        $settle_deadline = null;
        $navigation_deadline = microtime(true) + self::NAVIGATION_TIMEOUT_SECONDS;

        while (true) {
            $deadline = $settle_deadline ?? $navigation_deadline;

            if (microtime(true) >= $deadline) {
                break;
            }

            $message = $tab -> receiveMessage($deadline);

            if ($message === null) {
                break;
            }

            if (($message['method'] ?? null) === 'Fetch.requestPaused') {
                self::handleRequestPaused($tab, $message['params'], $pageURL, $html, $mainDocumentFulfilled, $challenge);
                continue;
            }

            if (($message['method'] ?? null) === 'Page.loadEventFired' && !$loadEventFired) {
                $loadEventFired = true;
                $settle_deadline = microtime(true) + ($challenge ? self::SETTLE_SECONDS : self::DOCUMENT_SETTLE_SECONDS);
            }

            if ($settle_deadline !== null && microtime(true) >= $settle_deadline) {
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
            function (array $message) use ($tab, $pageURL, $html, &$mainDocumentFulfilled, $challenge, $evaluateId): bool {
                if (($message['method'] ?? null) === 'Fetch.requestPaused') {
                    self::handleRequestPaused($tab, $message['params'], $pageURL, $html, $mainDocumentFulfilled, $challenge);
                }

                return ($message['id'] ?? null) === $evaluateId;
            },
            microtime(true) + self::EVALUATE_TIMEOUT_SECONDS
        );

        return $response['result']['result']['value'] ?? null;
    }

    private static function handleRequestPaused(ChromeTab $tab, array $params, URL $pageURL, string $html, bool &$mainDocumentFulfilled, bool $challenge): void {
        $requestId = $params['requestId'];
        $isMainDocument = !$mainDocumentFulfilled
            && ($params['resourceType'] ?? null) === 'Document'
            && ($params['request']['url'] ?? null) === $pageURL -> toString();

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

        $url = (string) ($params['request']['url'] ?? '');

        // Normal document navigations still belong to the crawler's redirect
        // and robots checks. Scripts, styles and data may use public CDNs.
        $allowed = $challenge ? self::isAllowedChallengeTarget($url, $pageURL) : self::isAllowedRenderTarget($url);

        if ((!$challenge && ($params['resourceType'] ?? null) === 'Document') || !$allowed || self::isBlocked($url)) {
            $tab -> sendCommand('Fetch.failRequest', ['requestId' => $requestId, 'errorReason' => 'BlockedByClient']);

            return;
        }

        // Everything else (the challenge's own scripts, its verification
        // fetch, images, iframes, ...) proceeds over the real network
        // unchanged - only the top-level document is ever swapped out here.
        $tab -> sendCommand('Fetch.continueRequest', ['requestId' => $requestId]);
    }

    /**
     * Whether $url is somewhere this crawler is willing to let a page reach.
     *
     * Challenges retain their narrower domain policy; ordinary rendering
     * also allows public CDNs used by the page's scripts and data requests.
     */
    public static function isAllowedChallengeTarget(string $url, URL $pageURL): bool {
        return self::isAllowedChallengeHost((string) parse_url($url, PHP_URL_HOST), $pageURL)
            && self::isAllowedRenderTarget($url);
    }

    public static function isAllowedRenderTarget(string $url): bool {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        // parse_url keeps an IPv6 literal's brackets ("[::1]"), which are URL
        // syntax rather than part of the address.
        $host = trim($host, '[]');

        // Browser subresources use DNS names, checked for public addresses
        // before continuing the request. Literal addresses stay disallowed.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return IPAddress::hostResolvesPublicly($host);
    }

    /** Pure hostname scope policy, kept public so its boundary is testable. */
    public static function isAllowedChallengeHost(string $host, URL $pageURL): bool {
        $host = strtolower(trim($host, '.'));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return in_array($host, self::CHALLENGE_PROVIDER_HOSTS, true)
            || PublicSuffixList::registrableDomain($host) === PublicSuffixList::registrableDomain($pageURL -> host);
    }

    /**
     * Whether $url belongs to an ad or analytics host (see
     * BLOCKED_HOST_SUFFIXES). Suffix matching is anchored on a dot boundary
     * so "evilgoogletagmanager.com" isn't caught by "googletagmanager.com".
     */
    private static function isBlocked(string $url): bool {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }
}
