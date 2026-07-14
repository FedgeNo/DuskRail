<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$topic = trim((string) ($_POST['topic'] ?? ''));

// Always write, never unlink() - deleting a file needs write permission on
// its *directory*, which Apache doesn't have here (only on the file itself,
// granted specifically for this one path). An empty/whitespace-only topic
// already means "go back to plain crawledTime order" as far as
// bin/crawler.php is concerned, so writing "" accomplishes the same thing
// without needing that extra permission.
file_put_contents(CRAWL_TOPIC_FILE, $topic);

echo json_encode(['topic' => $topic]);
