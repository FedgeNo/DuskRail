<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// crawledTime doubles as the poll cursor - the client passes back the
// highest one it's already seen, and only rows stamped since then come
// back. Every row here is guaranteed real, presentable content (nothing
// with crawledTime set was left that way after a failure - see
// Item::delete()/redirectTo()), so this feed can never surface junk.
$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;

$connection = Database::connection();

$select = mysqli_prepare($connection, '
SELECT `itemId`, `url`, `type`, `title`, `description`, `crawledTime`
    FROM `Items`
    WHERE `crawledTime` > ?
    ORDER BY `crawledTime` ASC
    LIMIT 50
');
mysqli_stmt_bind_param($select, 'i', $since);
mysqli_stmt_execute($select);
$result = mysqli_stmt_get_result($select);

$items = [];

while ($row = mysqli_fetch_assoc($result)) {
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
