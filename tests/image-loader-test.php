<?php

declare(strict_types=1);

assert_same('thumbnail URL uses three base-100 shards', '/thumbnails/71/21/00/2171.jpg', ImageLoader::thumbnailURL(2171, 'image/jpeg'));
assert_same('thumbnail URL retains the full id after six shard digits', '/thumbnails/92/31/80/1803192.jpg', ImageLoader::thumbnailURL(1803192, 'image/png'));
assert_same('non-images have no thumbnail URL', null, ImageLoader::thumbnailURL(2171, 'text/html'));
