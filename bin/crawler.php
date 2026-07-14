<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$topic = is_file(CRAWL_TOPIC_FILE) ? trim(file_get_contents(CRAWL_TOPIC_FILE)) : null;
$item = Item::nextToCrawl($topic !== '' ? $topic : null);

if ($item === null) {
    echo "Nothing to crawl.\n";
    exit(0);
}

echo 'Next up: ' . $item -> url . ' (itemId ' . $item -> itemId . ")\n";

// Written before any network I/O - if this process hangs and gets killed by
// bin/crawler-manager.php, this file is the only way that script can find
// out which item was being worked on when it died.
file_put_contents(CURRENT_CRAWL_ITEM_FILE, (string) $item -> itemId);

const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];
const MAX_REDIRECTS = 10;

$pageURL = new URL($item -> url);
$connection = new HTTPConnection($pageURL);

for ($hop = 0; in_array($connection -> statusCode, REDIRECT_STATUS_CODES, true); $hop++) {
    if ($hop >= MAX_REDIRECTS) {
        $connection -> readBody();
        $item -> delete();
        echo "Too many redirects, deleted this item.\n";
        exit(0);
    }

    $location = $connection -> headers['location'] ?? null;
    $connection -> readBody(); // drain + close before opening the next hop's connection

    if ($location === null) {
        $item -> delete();
        echo "Redirect status with no Location header, deleted this item.\n";
        exit(0);
    }

    $redirectTarget = $pageURL -> resolve(new URL($location));

    if (!$redirectTarget -> isValid()) {
        $item -> delete();
        echo "Redirect target isn't a real URL, deleted this item.\n";
        exit(0);
    }

    $item = $item -> redirectTo($redirectTarget);
    echo 'Redirected to: ' . $item -> url . ' (itemId ' . $item -> itemId . ")\n";

    if ($item -> crawledTime !== null) {
        echo "Redirect target already crawled, nothing more to do.\n";
        exit(0);
    }

    $pageURL = new URL($item -> url);
    $connection = new HTTPConnection($pageURL);
}

$contentType = $connection -> contentType();

if ($contentType !== null && $contentType -> isImage()) {
    $imageData = $connection -> readBody();
    $image = ImageLoader::load($imageData, $item -> itemId);

    if ($image === null) {
        // Content-Type claimed image/*, but imagecreatefromstring() couldn't
        // actually decode it (an SVG, a corrupt file, a format GD doesn't
        // support) - it isn't a usable image, so there's nothing to keep,
        // same reasoning as deleting an unrecoverable redirect.
        $item -> delete();
        echo "Couldn't decode image, deleted this item.\n";
        exit(0);
    }

    // Keep whatever title/description/keywords this item already had (e.g.
    // the parent-node text captured when it was first discovered as a link)
    // rather than wiping them out - an image has no metadata of its own to
    // extract that would replace them, real or otherwise.
    $item -> markCrawled($contentType -> type, $item -> title, $item -> description, $item -> keywords, null, null);

    echo "Saved thumbnail, marked crawled.\n";
    exit(0);
}

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

$metadata = HTMLLoader::extractMetadata($document);
$bodyText = HTMLLoader::extractBodyText($document);

$item -> markCrawled($contentType -> type, $metadata['title'], $metadata['description'], $metadata['keywords'], $bodyText, $html);

echo "Marked crawled.\n";
