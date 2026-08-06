<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// Public endpoint - anyone can call this, and it queries an index that only
// grows. RateLimit answers 429 and stops here if the caller is over budget.
RateLimit::enforcePublicAPI();

echo json_encode((new SearchResults(
    trim((string) ($_GET['q'] ?? '')),
    (string) ($_GET['type'] ?? 'html'),
    (int) ($_GET['offset'] ?? 0)
)) -> toJSON());
