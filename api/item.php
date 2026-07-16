<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

$itemId = isset($_GET['itemId']) ? (int) $_GET['itemId'] : 0;

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'itemId required']);
    return;
}

$connection = Database::connection();

$select = mysqli_prepare($connection, '
SELECT `itemId`, `url`, `type`, `title`, `description`
    FROM `Items`
    WHERE `itemId` = ?
        AND `crawledTime` IS NOT NULL
    LIMIT 1
');
mysqli_stmt_bind_param($select, 'i', $itemId);
mysqli_stmt_execute($select);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

if ($row === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    return;
}

// One page this was linked from, if any - an item can be (and often is)
// linked from several different pages, so this just picks one (the
// earliest-discovered) rather than trying to rank "the" canonical source
// the way search relevance elsewhere does.
$parentSelect = mysqli_prepare($connection, '
SELECT `Items`.`url`
    FROM `Links`
    INNER JOIN `Items` ON `Items`.`itemId` = `Links`.`parentId`
    WHERE `Links`.`childId` = ?
    ORDER BY `Links`.`parentId` ASC
    LIMIT 1
');
mysqli_stmt_bind_param($parentSelect, 'i', $itemId);
mysqli_stmt_execute($parentSelect);
$parentRow = mysqli_fetch_assoc(mysqli_stmt_get_result($parentSelect));

echo json_encode([
    'itemId' => (int) $row['itemId'],
    'url' => $row['url'],
    'type' => $row['type'],
    'title' => $row['title'],
    'description' => $row['description'],
    'parentUrl' => $parentRow['url'] ?? null,
    'thumbnailUrl' => str_starts_with($row['type'], 'image/') ? ImageLoader::thumbnailURL((int) $row['itemId']) : null,
]);
