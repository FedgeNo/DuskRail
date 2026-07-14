<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$item = Item::nextToCrawl();

if ($item === null) {
    echo "Nothing to crawl.\n";
    exit(0);
}

echo 'Next up: ' . $item -> url . ' (itemId ' . $item -> itemId . ")\n";

$pageURL = new URL($item -> url);
$connection = new HTTPConnection($pageURL);
$contentType = $connection -> contentType();

if ($contentType === null || !$contentType -> isHTML()) {
    echo "Not HTML, nothing more to do yet.\n";
    exit(0);
}

$html = $connection -> readBody();
$document = HTMLLoader::load($html, $contentType -> charset);
$baseURL = HTMLLoader::baseURL($document, $pageURL);
HTMLLoader::inlineImageAltText($document);

$images = HTMLLoader::extractImageLinks($document, $baseURL);

foreach ($images as $image) {
    $imageItem = Item::findOrCreateByURL($image['url'], 'image', null, $image['description'] ?: null);
    Link::create($item -> itemId, $imageItem -> itemId, $image['description'] ?: null);
}

echo 'Saved ' . count($images) . " images.\n";

$anchorLinks = HTMLLoader::extractAnchorLinks($document, $baseURL);

foreach ($anchorLinks as $link) {
    // "unknown" rather than a guess like images get "image" - a href can
    // point at absolutely anything (another page, a PDF, an image), and
    // there's no equivalent to "found via <img>" telling us which.
    $linkedItem = Item::findOrCreateByURL($link['url'], 'unknown', null, $link['description'] ?: null);
    Link::create($item -> itemId, $linkedItem -> itemId, $link['description'] ?: null);
}

echo 'Saved ' . count($anchorLinks) . " anchor links.\n";
