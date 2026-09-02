<?php

declare(strict_types=1);

assert_same('thumbnail URL uses three base-100 shards', '/thumbnails/71/21/00/2171.jpg', ImageLoader::thumbnailURL(2171, 'image/jpeg'));
assert_same('thumbnail URL retains the full id after six shard digits', '/thumbnails/92/31/80/1803192.jpg', ImageLoader::thumbnailURL(1803192, 'image/png'));
assert_same('non-images have no thumbnail URL', null, ImageLoader::thumbnailURL(2171, 'text/html'));

$source = imagecreatetruecolor(800, 400);
ob_start();
imagepng($source);
$source_bytes = (string) ob_get_clean();
imagedestroy($source);
$thumbnail_bytes = ImageLoader::thumbnailBytes($source_bytes);
$thumbnail_size = $thumbnail_bytes !== null ? getimagesizefromstring($thumbnail_bytes) : false;
assert_same('large images become bounded JPEG thumbnails', [300, 150], $thumbnail_size !== false ? [$thumbnail_size[0], $thumbnail_size[1]] : null);
assert_same('invalid image bytes are refused', null, ImageLoader::thumbnailBytes('not an image'));
