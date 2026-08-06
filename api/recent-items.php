<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

Auth::requireAPI();

echo json_encode((new CrawlFeed((int) ($_GET['since'] ?? 0))) -> toJSON());
