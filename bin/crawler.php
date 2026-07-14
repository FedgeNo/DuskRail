<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$item = Item::nextToCrawl();

if ($item === null) {
    echo "Nothing to crawl.\n";
    exit(0);
}

echo 'Next up: ' . $item -> url . ' (itemId ' . $item -> itemId . ")\n";
