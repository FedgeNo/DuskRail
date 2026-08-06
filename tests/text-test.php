<?php

declare(strict_types=1);

assert_same('short text passes through', 'short', Text::truncate('short', 100));
assert_same('null passes through', null, Text::truncate(null, 100));

$long = Text::truncate(str_repeat('word ', 50), 60);
assert_true('long text gets the ellipsis character', str_ends_with((string) $long, '…'));
assert_true('cut lands on a word boundary', !str_contains((string) $long, 'wor…'));

$unbroken = Text::truncate(str_repeat('x', 200), 60);
assert_same('unbroken runs cut at the limit', 61, mb_strlen((string) $unbroken));

$exact = str_repeat('a', 60);
assert_same('exactly-at-limit text untouched', $exact, Text::truncate($exact, 60));
