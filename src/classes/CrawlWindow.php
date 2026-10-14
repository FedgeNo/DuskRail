<?php

declare(strict_types=1);

/**
 * Whether ordinary crawling may pick work right now. Interactive Retrieval
 * API requests are always eligible, so the crawler manager can stay running
 * for them while ordinary crawling is confined to scheduled windows.
 *
 * With CRAWL_WINDOWED unset (the default), ordinary crawling runs whenever
 * the manager does. With CRAWL_WINDOWED=1 it runs only while a window opened
 * by bin/crawl-window.php is current. Either way, the interactiveOnly setting
 * still restricts crawling to interactive requests.
 */
class CrawlWindow {
    // A window is held open by short leases rather than set once for its
    // whole length, so a window holder that dies without closing it stops
    // ordinary crawling within this many seconds.
    public const LEASE_SECONDS = 60;

    public static function ordinaryCrawlAllowed(): bool {
        $config = require ROOT_DIR . '/src/config.php';

        return self::allows(
            Setting::value(INTERACTIVE_ONLY_SETTING),
            $config['crawlWindowed'],
            Setting::value(ORDINARY_CRAWL_UNTIL_SETTING),
            time()
        );
    }

    /** The pure decision behind ordinaryCrawlAllowed(). */
    public static function allows(?string $interactiveOnly, bool $windowed, ?string $openUntil, int $now): bool {
        if ($interactiveOnly === '1') {
            return false;
        }

        return !$windowed || (int) $openUntil > $now;
    }

    public static function extend(int $closesAt): void {
        Setting::store(ORDINARY_CRAWL_UNTIL_SETTING, (string) min($closesAt, time() + self::LEASE_SECONDS));
    }

    public static function close(): void {
        Setting::store(ORDINARY_CRAWL_UNTIL_SETTING, '0');
    }
}
