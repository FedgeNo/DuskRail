<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

Auth::requireWriteAPI();

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

$item = Item::findById($itemId);

if ($item === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

// No dead-URL reason: this is someone clearing a row out of the index by
// hand, not the crawler concluding the URL is unusable, so it stays eligible
// to be discovered and crawled again later.
$item -> delete();

echo json_encode(['deleted' => $itemId]);
