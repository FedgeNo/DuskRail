<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$processed = 0;

while (($page = SearchIndexQueue::processPending(1000, true)) > 0) {
    $processed += $page;
}

if ($processed !== 0) {
    echo 'Synchronized ' . $processed . ' queued item(s).' . PHP_EOL;
}
