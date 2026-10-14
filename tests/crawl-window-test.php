<?php

declare(strict_types=1);

$now = 1_000_000;

assert_true('Unwindowed crawling is always allowed', CrawlWindow::allows(null, false, null, $now));
assert_true('Unwindowed crawling ignores a closed window', CrawlWindow::allows('0', false, '0', $now));
assert_true('Windowed crawling runs inside an open window', CrawlWindow::allows('0', true, (string) ($now + 30), $now));
assert_true('Windowed crawling stops at the window close', !CrawlWindow::allows('0', true, (string) $now, $now));
assert_true('Windowed crawling stops when no window was ever opened', !CrawlWindow::allows(null, true, null, $now));
assert_true('Interactive-only overrides an open window', !CrawlWindow::allows('1', true, (string) ($now + 30), $now));
assert_true('Interactive-only overrides unwindowed crawling', !CrawlWindow::allows('1', false, null, $now));
