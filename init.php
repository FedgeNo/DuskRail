<?php

declare(strict_types=1);

define('ROOT_DIR', __DIR__);
define('SRC_DIR', ROOT_DIR . '/src');
define('CLASSES_DIR', SRC_DIR . '/classes');

// Where bin/crawler.php records which itemId it's currently working on, so
// bin/crawler-manager.php can identify a hung process after killing it (the
// process itself can't report back once it's been force-killed).
define('CURRENT_CRAWL_ITEM_FILE', ROOT_DIR . '/crawler-current-item');

// Optional focused-crawl topic, set from the watch.php control panel via
// api/set-topic.php and read by bin/crawler.php at the start of each run -
// the file is the only channel between the long-running web request that
// sets it and the fresh CLI process that needs to see it next.
define('CRAWL_TOPIC_FILE', ROOT_DIR . '/crawl-topic');

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(function (string $class): void {
    $path = CLASSES_DIR . '/' . $class . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require SRC_DIR . '/functions.php';
