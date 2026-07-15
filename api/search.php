<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// STUB - the frontend (index.php/search.js) is fully wired up to this
// endpoint's shape, but nothing here actually searches yet. Deliberately
// always returns zero results rather than querying Items at all - the real
// FULLTEXT MATCH/AGAINST implementation is a separate, later step.
$query = trim((string) ($_GET['q'] ?? ''));

echo json_encode(['results' => []]);
