<?php

declare(strict_types=1);

/**
 * Opens a window for ordinary crawling when CRAWL_WINDOWED=1, holds it while
 * this process runs, and closes it on exit. The always-running crawler
 * manager serves interactive Retrieval API requests outside windows.
 *
 *   php bin/crawl-window.php          # until SIGTERM or SIGINT
 *   php bin/crawl-window.php 600      # for ten minutes
 *
 * The window is held by short leases (CrawlWindow::LEASE_SECONDS), so it
 * also closes shortly after this process is killed outright. Workers that
 * already picked an ordinary item finish it after the window closes.
 *
 * Exits 0 after closing the window, 1 when the crawler manager is not
 * running (the window would have nothing to open).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

const RENEW_INTERVAL_SECONDS = 15;

if (isset($argv[1]) && preg_match('/^[1-9][0-9]*$/', $argv[1]) !== 1 || isset($argv[2])) {
    fwrite(STDERR, "Usage: php bin/crawl-window.php [seconds]\n");
    exit(1);
}

$closes_at = isset($argv[1]) ? time() + (int) $argv[1] : PHP_INT_MAX;
$stop_requested = false;

// Without pcntl a signal ends the process at once; the lease then closes the
// window within CrawlWindow::LEASE_SECONDS instead of immediately.
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$stop_requested): void {
        $stop_requested = true;
    });
    pcntl_signal(SIGINT, static function () use (&$stop_requested): void {
        $stop_requested = true;
    });
}

if (!(new CrawlerStatus()) -> running) {
    fwrite(STDERR, "The crawler manager is not running; no window opened.\n");
    exit(1);
}

echo 'Ordinary crawl window open' . ($closes_at === PHP_INT_MAX ? '' : ' for ' . $argv[1] . ' seconds') . ".\n";

try {
    while (!$stop_requested && time() < $closes_at) {
        CrawlWindow::extend($closes_at);
        $renew_at = time() + RENEW_INTERVAL_SECONDS;

        while (!$stop_requested && time() < min($renew_at, $closes_at)) {
            sleep(1);
        }

        if (!$stop_requested && !(new CrawlerStatus()) -> running) {
            fwrite(STDERR, "The crawler manager stopped; closing the window.\n");
            CrawlWindow::close();
            exit(1);
        }
    }
} finally {
    CrawlWindow::close();
}

echo "Ordinary crawl window closed.\n";
exit(0);
