<?php

declare(strict_types=1);

$html = new ContentType('text/html; charset=UTF-8');
assert_same('bare type parsed', 'text/html', $html -> type);
assert_same('charset parsed', 'UTF-8', $html -> charset);
assert_true('text/html is HTML', $html -> isHTML());

assert_true('xhtml is HTML', (new ContentType('application/xhtml+xml')) -> isHTML());
assert_false('json is not HTML', (new ContentType('application/json')) -> isHTML());

assert_true('jpeg is an image', (new ContentType('image/jpeg')) -> isImage());
assert_true('svg is an image', (new ContentType('image/svg+xml')) -> isImage());
assert_true('svg is SVG', (new ContentType('image/svg+xml')) -> isSVG());
assert_false('png is not SVG', (new ContentType('image/png')) -> isSVG());

assert_true('pdf detected', (new ContentType('application/pdf')) -> isPDF());
assert_true('plain text detected', (new ContentType('text/plain; charset=utf-8')) -> isPlainText());

assert_same('quoted charset unwrapped', 'utf-8', (new ContentType('text/html; charset="utf-8"')) -> charset);
assert_same('case-folded type', 'text/html', (new ContentType('TEXT/HTML')) -> type);
