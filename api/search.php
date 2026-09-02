<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// Public endpoint - anyone can call this, and it queries an index that only
// grows. RateLimit answers 429 and stops here if the caller is over budget.
RateLimit::enforcePublicAPI();

$query = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($query, 'UTF-8') > SearchResults::MAX_QUERY_LENGTH) {
    http_response_code(400);
    echo json_encode(['error' => 'query is too long']);
    exit;
}

echo json_encode((new SearchResults(
    $query,
    (string) ($_GET['type'] ?? 'html'),
    (int) ($_GET['offset'] ?? 0)
)) -> toJSON());
