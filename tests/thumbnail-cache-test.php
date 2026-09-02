<?php

declare(strict_types=1);

assert_true(
    'thumbnail cache writes with its full reserve available',
    ThumbnailCache::storageAllowsWrite(1_500_000_000_000, 4_000_000_000_000, 1_500_000_000_000)
);
assert_false(
    'thumbnail cache preserves the fixed free-space reserve',
    ThumbnailCache::storageAllowsWrite(1_499_999_999_999, 4_000_000_000_000, 1_500_000_000_000)
);
assert_false(
    'thumbnail cache stops at ninety-five percent usage',
    ThumbnailCache::storageAllowsWrite(50, 1_000, 0)
);
assert_false('thumbnail cache refuses invalid capacity readings', ThumbnailCache::storageAllowsWrite(-1, 1_000, 0));

assert_same('byte size accepts raw bytes', 1_500_000_000_000, ByteSize::bytes('1500000000000'));
assert_same('byte size accepts fractional terabytes', 1_500_000_000_000, ByteSize::bytes('1.5T'));
assert_same('byte size accepts gigabytes', 1_500_000_000_000, ByteSize::bytes('1500G'));
assert_same('byte size suffix is case insensitive', 1_500_000_000_000, ByteSize::bytes('1.5tb'));

$invalid_size_thrown = false;

try {
    ByteSize::bytes('1.5P');
} catch (\InvalidArgumentException) {
    $invalid_size_thrown = true;
}

assert_true('byte size rejects unknown suffixes', $invalid_size_thrown);
