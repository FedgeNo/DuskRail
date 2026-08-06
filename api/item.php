<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// Public endpoint - anyone can call this, and it queries an index that only
// grows. RateLimit answers 429 and stops here if the caller is over budget.
RateLimit::enforcePublicAPI();

$itemId = isset($_GET['itemId']) ? (int) $_GET['itemId'] : 0;

if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'itemId required']);
    return;
}

$preview = ItemPreview::findById($itemId);

if ($preview === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    return;
}

echo json_encode($preview -> toJSON());
