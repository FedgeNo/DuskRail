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

$topic = trim((string) ($_POST['topic'] ?? ''));

// An empty/whitespace-only topic already means "go back to plain crawledTime
// order" as far as bin/crawler.php is concerned, so clearing the topic is
// just storing "" - there's no separate delete path to get wrong.
Setting::store(CRAWL_TOPIC_SETTING, $topic);

echo json_encode(['topic' => $topic]);
