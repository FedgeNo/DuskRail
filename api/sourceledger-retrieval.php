<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

$config = require ROOT_DIR . '/src/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$configured_token = trim((string) $config['sourceLedgerToken']);
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$provided_token = str_starts_with($authorization, 'Bearer ')
    ? trim(substr($authorization, 7))
    : '';

if ($configured_token === '' || $provided_token === '' || !hash_equals($configured_token, $provided_token)) {
    http_response_code(401);
    echo json_encode(['error' => 'authentication required']);
    exit;
}

$url = new URL((string) ($_POST['url'] ?? ''));

if (!$url -> isValid()) {
    http_response_code(400);
    echo json_encode(['error' => 'not a crawlable URL']);
    exit;
}

$item = Item::enqueuePriority($url);

if ($item === null) {
    http_response_code(422);
    echo json_encode(['error' => 'URL does not resolve to a public address']);
    exit;
}

http_response_code(202);
echo json_encode([
    'itemId' => $item -> itemId,
    'url' => $item -> url,
    'priority' => 'interactive',
]);
