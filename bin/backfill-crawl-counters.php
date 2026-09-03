<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$operation_lock = @fopen('php://fd/3', 'rb');

if ($operation_lock === false) {
    fwrite(STDERR, 'Run this command through Pochine\'s with-operation-lock.php wrapper.' . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../init.php';

$connection = Database::connection();
mysqli_query($connection, '
LOCK TABLES `Items` READ, `Hosts` READ, `DeadURLs` READ, `CrawlCounters` WRITE
');

try {
    mysqli_query($connection, '
INSERT INTO `CrawlCounters` (
        `counterId`, `found`, `indexed`, `searchable`, `queued`, `pages`, `images`, `hosts`, `dead`, `initializedAt`
    )
SELECT 1,
        COUNT(*),
        COUNT(`crawledTime`),
        SUM(`crawledTime` IS NOT NULL AND `noindex` = 0),
        SUM(`crawledTime` IS NULL),
        SUM(`crawledTime` IS NOT NULL AND `type` NOT LIKE \'image/%\'),
        SUM(`crawledTime` IS NOT NULL AND `type` LIKE \'image/%\'),
        (SELECT COUNT(*) FROM `Hosts`),
        (SELECT COUNT(*) FROM `DeadURLs`),
        UTC_TIMESTAMP()
    FROM `Items` FORCE INDEX (`crawledTime_itemId_type_noindex`)
ON DUPLICATE KEY UPDATE
    `found` = VALUES(`found`),
    `indexed` = VALUES(`indexed`),
    `searchable` = VALUES(`searchable`),
    `queued` = VALUES(`queued`),
    `pages` = VALUES(`pages`),
    `images` = VALUES(`images`),
    `hosts` = VALUES(`hosts`),
    `dead` = VALUES(`dead`),
    `initializedAt` = VALUES(`initializedAt`)
');
} finally {
    mysqli_query($connection, 'UNLOCK TABLES');
}

echo 'Crawl counters backfilled.' . PHP_EOL;
