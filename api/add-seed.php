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

$url = new URL(trim((string) ($_POST['url'] ?? '')));

if (!$url -> isValid()) {
    http_response_code(400);
    echo json_encode(['error' => 'not a crawlable URL']);
    exit;
}

// A seed is the operator saying "crawl this" - that outranks any earlier
// verdict that the URL was dead, which is the one thing that would otherwise
// make findOrCreateByURL() silently refuse it.
DeadURL::forget($url -> toString());

$item = Item::findOrCreateByURL($url, 'unknown');

if ($item === null) {
    http_response_code(409);
    echo json_encode(['error' => 'this host already has a full queue']);
    exit;
}

echo json_encode(['itemId' => $item -> itemId, 'url' => $item -> url]);
