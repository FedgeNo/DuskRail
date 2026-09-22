<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

$config = require ROOT_DIR . '/src/config.php';

header('Content-Type: application/json');

$configured_token = (string) $config['sourceLedgerToken'];
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$provided_token = str_starts_with($authorization, 'Bearer ')
    ? substr($authorization, 7)
    : '';

if ($configured_token === '' || $provided_token === '' || !hash_equals($configured_token, $provided_token)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    return;
}

$item_id = isset($_GET['itemId']) ? (int) $_GET['itemId'] : 0;
if ($item_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'itemId required']);
    return;
}

$request = InteractiveRetrieval::find($item_id);
$item = Item::findById($request -> itemId ?? $item_id);
if ($request !== null && $request -> state($item, time()) === 'failed') {
    $reason = $request -> failure ?? (time() >= $request -> deadline
        ? 'Interactive retrieval exceeded its 60-second deadline.'
        : 'The requested page is unavailable.');
    $request -> fail($reason);
    echo json_encode(['itemId' => $request -> itemId, 'url' => $item -> url ?? (string) ($_GET['url'] ?? ''), 'state' => 'failed', 'reason' => $reason]);
    return;
}
if ($item === null) {
    $redirect = mysqli_prepare(Database::connection(), '
SELECT `targetItemId`
    FROM `SourceLedgerRedirects`
    WHERE `sourceItemId` = ?
    LIMIT 1
');
    mysqli_stmt_bind_param($redirect, 'i', $item_id);
    mysqli_stmt_execute($redirect);
    $redirect_row = mysqli_fetch_assoc(mysqli_stmt_get_result($redirect));
    if ($redirect_row !== null) {
        $item = Item::findById((int) $redirect_row['targetItemId']);
    }
}

if ($item === null) {
    $url = isset($_GET['url']) ? (string) $_GET['url'] : '';
    $reason = $url !== '' ? DeadURL::reasonFor($url) : null;
    http_response_code(404);
    echo json_encode($reason === null
        ? ['error' => 'not found']
        : ['error' => 'tombstoned', 'reason' => $reason]);
    return;
}

$state = $item -> crawledTime !== null
    ? 'available'
    : (($item -> claimedUntil !== null && $item -> claimedUntil > time()) ? 'reading' : 'queued');

echo json_encode([
    'itemId' => $item -> itemId,
    'url' => $item -> url,
    'state' => $state,
    'title' => $item -> title,
    'type' => $item -> type,
    'crawledTime' => $item -> crawledTime,
]);
