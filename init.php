<?php

declare(strict_types=1);

define('ROOT_DIR', __DIR__);
define('SRC_DIR', ROOT_DIR . '/src');
define('CLASSES_DIR', SRC_DIR . '/classes');

// Where bin/crawler.php records which itemId it's currently working on, so
// bin/crawler-manager.php can identify a hung process after killing it (the
// process itself can't report back once it's been force-killed).
define('CURRENT_CRAWL_ITEM_FILE', ROOT_DIR . '/crawler-current-item');

error_reporting(E_ALL);
ini_set('display_errors', '1');

spl_autoload_register(function (string $class): void {
    $path = CLASSES_DIR . '/' . $class . '.php';

    if (is_file($path)) {
        require $path;
    }
});

require SRC_DIR . '/functions.php';
