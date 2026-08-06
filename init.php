<?php

declare(strict_types=1);

define('ROOT_DIR', __DIR__);
define('SRC_DIR', ROOT_DIR . '/src');
define('CLASSES_DIR', SRC_DIR . '/classes');

// Every file the running crawler writes lives here, and nothing else does.
// One directory rather than loose files at the project root because the
// crawler runs as its own system user (see bin/install.php): that user needs
// write permission on whatever directory its state files live in, and this
// way that's exactly one directory - the checkout itself stays owned by
// whoever cloned it, with nothing in it writable by a service at all.
define('VAR_DIR', ROOT_DIR . '/var');

// Where bin/crawler.php records which itemId it's currently working on, so
// bin/crawler-manager.php can identify a hung process after killing it (the
// process itself can't report back once it's been force-killed). One per
// worker slot, suffixed with the slot number.
define('CURRENT_CRAWL_ITEM_FILE', VAR_DIR . '/crawler-current-item');

// Where bin/crawler-manager.php writes the shared, persistent Chrome
// instance's "host:port" DevTools endpoint - each fresh bin/crawler.php
// worker process reads this to talk to the one already-running browser
// rather than launching its own (see ChromeProcess).
define('CHROME_DEVTOOLS_ENDPOINT_FILE', VAR_DIR . '/chrome-devtools-endpoint');

// The focused-crawl topic (Setting) - set from watch.php's control panel and
// read by bin/crawler.php at the start of each run.
define('CRAWL_TOPIC_SETTING', 'crawlTopic');

// When bin/crawler-manager.php last confirmed it was alive (Setting, a unix
// timestamp refreshed every few seconds while it runs) - how the watch page
// can say "the crawler is running" without any way to see the process.
define('CRAWLER_HEARTBEAT_SETTING', 'crawlerHeartbeatTime');

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(function (string $class): void {
    $path = CLASSES_DIR . '/' . $class . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require SRC_DIR . '/functions.php';
