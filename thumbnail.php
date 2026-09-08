<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

// Public endpoint - a full image grid requests one thumbnail per result, so a
// normal page view is dozens of these at once. The budgets are far wider than
// the search endpoints' (see RateLimit::enforceThumbnailAPI()) and a reader
// scrolling an uncached grid stays well inside them; they only ever see a
// request on a cache miss anyway, since a stored thumbnail is served by
// Apache without reaching PHP at all.
RateLimit::enforceThumbnailAPI();

$item_id = (int) ($_GET['item'] ?? 0);
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$payload = $item_id > 0 && $path === ImageLoader::thumbnailURL($item_id, 'image/jpeg')
    ? ThumbnailCache::thumbnail($item_id)
    : null;

while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($payload !== null) {
    $length = $payload -> length();

    header('Content-Type: image/jpeg');

    if ($length !== null) {
        header('Content-Length: ' . (string) $length);
    }

    header('Cache-Control: max-age=31536000, immutable');
    $payload -> send();
    exit;
}

header('Content-Type: image/gif');
header('Cache-Control: max-age=300');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
