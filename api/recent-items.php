<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// How many rows a single poll can return, whether it's the initial seed or a
// normal forward-poll batch.
const BATCH_SIZE = 50;

// crawledTime doubles as the poll cursor - the client passes back the
// highest one it's already seen, and only rows stamped since then come
// back. Every row here is guaranteed real, presentable content (nothing
// with crawledTime set was left that way after a failure - see
// Item::delete()/redirectTo()), so this feed can never surface junk.
$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;

$connection = Database::connection();

if ($since === 0) {
    // The client's very first poll, with no cursor yet - seed it with only
    // the most recently crawled items instead of replaying the whole crawl
    // history forward from the beginning 50 rows at a time. Selected newest
    // first to get the right 50, then handed back oldest first so the
    // client can append them in its usual top-to-bottom order and derive
    // its next cursor from the last one the same way it always does.
    $select = mysqli_prepare($connection, '
SELECT `itemId`, `url`, `type`, `title`, `description`, `crawledTime`
    FROM `Items`
    WHERE `crawledTime` IS NOT NULL
    ORDER BY `crawledTime` DESC
    LIMIT ' . BATCH_SIZE . '
');
    mysqli_stmt_execute($select);
    $result = mysqli_stmt_get_result($select);
    $rows = array_reverse(mysqli_fetch_all($result, MYSQLI_ASSOC));
} else {
    $select = mysqli_prepare($connection, '
SELECT `itemId`, `url`, `type`, `title`, `description`, `crawledTime`
    FROM `Items`
    WHERE `crawledTime` > ?
    ORDER BY `crawledTime` ASC
    LIMIT ' . BATCH_SIZE . '
');
    mysqli_stmt_bind_param($select, 'i', $since);
    mysqli_stmt_execute($select);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
}

$items = [];

foreach ($rows as $row) {
    $itemId = (int) $row['itemId'];

    $items[] = [
        'itemId' => $itemId,
        'url' => $row['url'],
        'type' => $row['type'],
        'title' => $row['title'],
        'description' => $row['description'],
        'crawledTime' => (int) $row['crawledTime'],
        'thumbnailUrl' => str_starts_with($row['type'], 'image/') ? ImageLoader::thumbnailURL($itemId) : null,
    ];
}

echo json_encode($items);
