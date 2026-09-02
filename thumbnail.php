<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

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
