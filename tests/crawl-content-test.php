<?php

declare(strict_types=1);

$content = new CrawlContent(str_repeat('widgets ', 60), '<p>Original HTML</p>');
assert_same('prepared crawl text retains the repetition cap', Text::capRepeatedTerms(str_repeat('widgets ', 60)), $content -> fullText);
assert_same('prepared crawl hash describes the capped text', sha1($content -> fullText), $content -> contentHash);
assert_same('prepared crawl HTML is lossless', '<p>Original HTML</p>', gzdecode($content -> fullHTML));
$content = new CrawlContent(null, null);
assert_same('images retain null text', null, $content -> fullText);
assert_same('images retain null HTML', null, $content -> fullHTML);
assert_same('images retain the empty-text content hash', sha1(''), $content -> contentHash);
