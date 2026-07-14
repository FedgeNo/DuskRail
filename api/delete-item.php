<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$itemId = isset($_POST['itemId']) ? (int) $_POST['itemId'] : 0;

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'itemId required']);
    exit;
}

$connection = Database::connection();

$select = mysqli_prepare($connection, '
SELECT *
    FROM `Items`
    WHERE `itemId` = ?
');
mysqli_stmt_bind_param($select, 'i', $itemId);
mysqli_stmt_execute($select);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

if ($row === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

Item::fromRow($row) -> delete();

echo json_encode(['deleted' => $itemId]);
