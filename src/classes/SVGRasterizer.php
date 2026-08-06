<?php

declare(strict_types=1);

/**
 * Renders SVG markup to a PNG using a tab in the shared, persistent Chrome
 * instance (ChromeProcess/ChromeTab) - the only real SVG renderer this
 * project has, since GD has no decoder for it and ImageLoader would otherwise
 * throw every SVG away as undecodable.
 *
 * The markup is handed to the page locally, exactly the way
 * HeadlessBrowser::resolveChallenge() does it: Fetch-domain interception
 * fulfills the one document request with these bytes rather than fetching
 * anything. The URL navigated to is about:blank's own origin, so an SVG that
 * references external resources gets nothing back - which is the point. This
 * is untrusted markup off the open web, and rendering it must not turn into a
 * second, unaccounted-for round of requests on this crawler's behalf.
 */
class SVGRasterizer
{
    private const HANDSHAKE_TIMEOUT_SECONDS = 3.0;
    private const RENDER_TIMEOUT_SECONDS = 8.0;
    private const SCREENSHOT_TIMEOUT_SECONDS = 5.0;

    // What the SVG is rendered into. Big enough that a diagram or logo comes
    // out at a usable resolution for a 300px thumbnail, small enough to stay
    // far below ImageLoader's own megapixel ceiling.
    private const VIEWPORT_WIDTH = 800;
    private const VIEWPORT_HEIGHT = 800;

    // A backstop against an absurd SVG (deeply nested, millions of paths)
    // before it's ever handed to a browser to render.
    private const MAX_SOURCE_BYTES = 4 * 1024 * 1024;

    /**
     * The rendered PNG bytes, or null if the shared browser isn't reachable
     * or anything along the way didn't finish inside its budget.
     */
    public static function toPNG(string $hostAndPort, string $svg): ?string
    {
        if (strlen($svg) > self::MAX_SOURCE_BYTES || !str_contains($svg, '<svg')) {
            return null;
        }

        try {
            $tab = new ChromeTab($hostAndPort, self::HANDSHAKE_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }

        try {
            return self::render($tab, $svg);
        } catch (\Throwable) {
            return null;
        } finally {
            $tab -> close();
        }
    }

    private static function render(ChromeTab $tab, string $svg): ?string
    {
        // A fixed, real http:// URL rather than a data: or about: one - the
        // Fetch domain only intercepts requests that look like navigations to
        // a normal origin, and nothing is ever actually requested from it
        // (the interception below answers it locally, and every other request
        // the document makes is refused).
        $documentURL = 'http://svg.invalid/';

        $tab -> sendCommand('Page.enable');
        $tab -> sendCommand('Emulation.setDeviceMetricsOverride', [
            'width' => self::VIEWPORT_WIDTH,
            'height' => self::VIEWPORT_HEIGHT,
            'deviceScaleFactor' => 1,
            'mobile' => false,
        ]);
        $tab -> sendCommand('Fetch.enable', ['patterns' => [['urlPattern' => '*', 'requestStage' => 'Request']]]);
        $tab -> sendCommand('Page.navigate', ['url' => $documentURL]);

        $loadEventFired = false;

        $tab -> waitUntil(function (array $message) use ($tab, $documentURL, $svg, &$loadEventFired): bool {
            if (($message['method'] ?? null) === 'Fetch.requestPaused') {
                self::handleRequestPaused($tab, $message['params'], $documentURL, $svg);

                return false;
            }

            if (($message['method'] ?? null) === 'Page.loadEventFired') {
                $loadEventFired = true;

                return true;
            }

            return false;
        }, microtime(true) + self::RENDER_TIMEOUT_SECONDS);

        if (!$loadEventFired) {
            return null;
        }

        $screenshotId = $tab -> sendCommand('Page.captureScreenshot', ['format' => 'png']);

        $response = $tab -> waitUntil(
            fn (array $message): bool => ($message['id'] ?? null) === $screenshotId,
            microtime(true) + self::SCREENSHOT_TIMEOUT_SECONDS
        );

        $encoded = $response['result']['data'] ?? null;

        return $encoded !== null ? base64_decode($encoded) : null;
    }

    /**
     * The document request is answered with the SVG wrapped in a minimal HTML
     * page - an <img> pointed at the SVG as a data: URL, which renders it as
     * an image rather than as a document, so its scripts never run at all.
     * Everything else the page tries to load is failed outright: an SVG off
     * the open web has no business pulling in fonts, images or anything else
     * over the network while this crawler renders it.
     */
    private static function handleRequestPaused(ChromeTab $tab, array $params, string $documentURL, string $svg): void
    {
        $requestId = $params['requestId'];

        if (($params['request']['url'] ?? null) !== $documentURL) {
            $tab -> sendCommand('Fetch.failRequest', ['requestId' => $requestId, 'errorReason' => 'BlockedByClient']);

            return;
        }

        $page = '<!DOCTYPE html><html><head><style>'
            . 'html,body{margin:0;padding:0;background:#fff;}'
            . 'img{display:block;width:100vw;height:100vh;object-fit:contain;}'
            . '</style></head><body><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '"></body></html>';

        $tab -> sendCommand('Fetch.fulfillRequest', [
            'requestId' => $requestId,
            'responseCode' => 200,
            'responseHeaders' => [['name' => 'Content-Type', 'value' => 'text/html; charset=utf-8']],
            'body' => base64_encode($page),
        ]);
    }
}
