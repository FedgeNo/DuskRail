<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

$configured_token = trim((string) Config::get('sourceLedgerToken', ''));
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$provided_token = str_starts_with($authorization, 'Bearer ')
    ? trim(substr($authorization, 7))
    : '';

if ($configured_token === '' || $provided_token === '' || !hash_equals($configured_token, $provided_token)) {
    http_response_code(401);
    echo json_encode(['error' => 'authentication required']);
    exit;
}

$item_id = isset($_GET['itemId']) ? (int) $_GET['itemId'] : 0;
if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'itemId required']);
    exit;
}

$item = Item::findWithContentById($item_id);
if ($item === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

if ($item -> crawledTime === null) {
    http_response_code(409);
    echo json_encode(['error' => 'content not available']);
    exit;
}

$text = (string) ($item -> fullText ?? '');
$maximum_characters = 120000;
$capture_text = function_exists('mb_substr')
    ? mb_substr($text, 0, $maximum_characters)
    : substr($text, 0, $maximum_characters);

echo json_encode([
    'itemId' => $item -> itemId,
    'url' => $item -> url,
    'title' => $item -> title,
    'type' => $item -> type,
    'crawledTime' => $item -> crawledTime,
    'text' => $capture_text,
    'textTruncated' => strlen($text) > strlen($capture_text),
]);
