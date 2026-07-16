<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

// Ignored, not handled - a worker mid-crawl shouldn't stop partway through an
// item just because Ctrl+C (SIGINT) reached the whole process group, or
// bin/crawler-manager.php sent SIGTERM during a graceful shutdown. It runs
// its current item to completion regardless; the manager is what decides
// when to stop spawning new ones. SIGKILL (used for an actually-hung worker)
// can't be ignored by any process, so hang-detection is unaffected.
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, SIG_IGN);
    pcntl_signal(SIGTERM, SIG_IGN);
}

const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];
const RATE_LIMITED_STATUS_CODES = [429, 503];
const MAX_REDIRECTS = 10;
const JS_CHALLENGE_TITLES = ['Just a moment...'];

/**
 * Every actual request made (each redirect hop, and the final fetch) counts
 * toward that host's politeness cooldown, not just the one that happened to
 * return real content. $statusCode is null when the connection itself never
 * completed (DNS failure, connection refused, TLS handshake failure, ...) -
 * treated as an ordinary (non-rate-limited) attempt for cooldown purposes,
 * same as any other single failed request.
 */
function recordHostCrawl(Host $host, ?int $statusCode): void
{
    $host -> recordCrawl($statusCode !== null && in_array($statusCode, RATE_LIMITED_STATUS_CODES, true));
}

/**
 * The Host for $url, with robots.txt already fetched/cached if this is the
 * first time anything from it has been seen.
 */
function hostFor(URL $url): Host
{
    $host = Host::findOrCreateByName($url -> host);
    $host -> fetchRobotsTxtIfMissing($url -> scheme);

    return $host;
}

// An optional worker slot number (bin/crawler-manager.php passes one when
// running several of these concurrently) keeps each worker's "what am I
// working on" file separate - otherwise concurrent workers would clobber a
// single shared file and a hang could be blamed on the wrong item entirely.
$workerSlot = $argv[1] ?? null;
$currentItemFile = $workerSlot !== null ? CURRENT_CRAWL_ITEM_FILE . '-' . $workerSlot : CURRENT_CRAWL_ITEM_FILE;

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
file_put_contents($currentItemFile, (string) $item -> itemId);

$pageURL = new URL($item -> url);
$host = hostFor($pageURL);

if ($host -> isDisallowed($pageURL -> path)) {
    $item -> delete();
    echo "robots.txt disallows this path, deleted this item.\n";
    exit(0);
}

$connection = new HTTPConnection($pageURL);
recordHostCrawl($host, $connection -> statusCode);

if ($connection -> statusCode === null) {
    // The connection itself never completed (DNS failure, connection
    // refused, TLS handshake failure, ...) - not an HTTP-level failure at
    // all, so there's no status code and nothing usable behind it. Same
    // "nothing to keep" reasoning as any other unrecoverable failure.
    $item -> delete();
    echo "Connection failed, deleted this item.\n";
    exit(0);
}

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

    $previousItemId = $item -> itemId;
    $item = $item -> redirectTo($redirectTarget);
    echo 'Redirected to: ' . $item -> url . ' (itemId ' . $item -> itemId . ")\n";

    if ($item -> crawledTime !== null) {
        echo "Redirect target already crawled, nothing more to do.\n";
        exit(0);
    }

    // redirectTo() may have handed back a *different*, pre-existing item (its
    // target URL already existed as its own row) - this worker only ever
    // claimed the item nextToCrawl() gave it, not this one, so another worker
    // could already be crawling it. Re-claim before continuing; losing that
    // race means the other worker owns it and this one bows out rather than
    // fetching the same URL a second time. A same-row redirect (the target
    // URL was new, so the itemId is unchanged) still holds its original claim
    // and must NOT re-claim - claim() would fail against its own live claim.
    if ($item -> itemId !== $previousItemId && !$item -> reclaim()) {
        echo "Redirect target already claimed by another worker, leaving it to them.\n";
        exit(0);
    }

    // Repoint the hang-tracking file at the item actually being worked on now:
    // a redirect onto a pre-existing item changes the itemId, and redirectTo()
    // has already deleted the original row, so a hang here would otherwise be
    // blamed on (and its 3-strikes deletion aimed at) an item that no longer
    // exists instead of the redirect target that's actually stuck.
    file_put_contents($currentItemFile, (string) $item -> itemId);

    $pageURL = new URL($item -> url);
    $host = hostFor($pageURL);

    if ($host -> isDisallowed($pageURL -> path)) {
        $item -> delete();
        echo "robots.txt disallows the redirect target, deleted this item.\n";
        exit(0);
    }

    $connection = new HTTPConnection($pageURL);
    recordHostCrawl($host, $connection -> statusCode);

    if ($connection -> statusCode === null) {
        $item -> delete();
        echo "Connection failed, deleted this item.\n";
        exit(0);
    }
}

if (in_array($connection -> statusCode, RATE_LIMITED_STATUS_CODES, true)) {
    // The server explicitly asked to be left alone, not "this doesn't
    // exist" - recordHostCrawl() above already backed this host off 5
    // minutes. The item itself is left alone (crawledTime still NULL) so
    // nextToCrawl() simply retries it once that cooldown passes, rather
    // than deleting it like a genuine permanent failure.
    $connection -> readBody();
    echo 'Status ' . $connection -> statusCode . ", backing off this host, item left for retry.\n";
    exit(0);
}

if ($connection -> statusCode < 200 || $connection -> statusCode >= 300) {
    // A 404, 500, 403, ... never has real content behind it worth keeping -
    // whatever body an error page returns isn't presentable, and there's
    // nothing to retry here (the URL is what it is), so this is exactly the
    // same call as an unrecoverable redirect: delete, don't markCrawled().
    $connection -> readBody();
    $item -> delete();
    echo 'Status ' . $connection -> statusCode . ", deleted this item.\n";
    exit(0);
}

$contentType = $connection -> contentType();

if ($contentType !== null && $contentType -> isImage()) {
    $imageData = $connection -> readBody();
    $image = ImageLoader::load($imageData, $item -> itemId);

    if ($image === null) {
        // Content-Type claimed image/*, but ImageLoader::load() couldn't turn
        // it into a usable thumbnail - either it wasn't decodable at all (an
        // SVG, a corrupt file, a format GD doesn't support) or its dimensions
        // ruled it out (a decompression bomb, or too small to be more than a
        // tracking pixel/spacer/decorative icon). Either way there's nothing
        // to keep, same reasoning as deleting an unrecoverable redirect.
        $item -> delete();
        echo "Couldn't use image (undecodable or unusable dimensions), deleted this item.\n";
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
    // Not something this crawler knows how to turn into presentable content
    // yet (a PDF, plain text, a connection that returned no Content-Type at
    // all, ...) - deleting rather than leaving crawledTime NULL is what
    // keeps this from being handed back by nextToCrawl() and retried forever
    // with the same non-result every single run.
    $connection -> readBody();
    $item -> delete();
    echo 'Not HTML (' . ($contentType ?-> type ?? 'no response') . "), deleted this item.\n";
    exit(0);
}

$html = $connection -> readBody();
$document = HTMLLoader::load($html, $contentType -> charset);
$baseURL = HTMLLoader::baseURL($document, $pageURL);
HTMLLoader::inlineImageAltText($document);

$images = HTMLLoader::extractImageLinks($document, $baseURL);

foreach ($images as $image) {
    // Only checked for same-host links - robots.txt is this site's own
    // rules for its own paths, not a statement about paths on other sites
    // it happens to link to, and checking every external host would mean
    // fetching robots.txt for every domain a page links to just to discover
    // its links, not just the ones actually crawled.
    if ($image['url'] -> host === $pageURL -> host && $host -> isDisallowed($image['url'] -> path)) {
        continue;
    }

    $imageItem = Item::findOrCreateByURL($image['url'], 'image', null, $image['description'] ?: null);
    Link::create($item -> itemId, $imageItem -> itemId, $image['description'] ?: null);
}

echo 'Saved ' . count($images) . " images.\n";

$anchorLinks = HTMLLoader::extractAnchorLinks($document, $baseURL);

foreach ($anchorLinks as $link) {
    // A "sign in with..." link, not real content - and often a crawl trap,
    // since some providers mint a fresh single-use "state" param per
    // request, making the same login link look like a brand new URL every
    // time it's encountered. Never even worth creating an item for.
    if ($link['url'] -> isLikelyOAuthURL()) {
        continue;
    }

    // Same reasoning as the image loop above - only this site's own
    // robots.txt, only for this site's own paths.
    if ($link['url'] -> host === $pageURL -> host && $host -> isDisallowed($link['url'] -> path)) {
        continue;
    }

    // "unknown" rather than a guess like images get "image" - a href can
    // point at absolutely anything (another page, a PDF, an image), and
    // there's no equivalent to "found via <img>" telling us which.
    $linkedItem = Item::findOrCreateByURL($link['url'], 'unknown', null, $link['description'] ?: null);
    Link::create($item -> itemId, $linkedItem -> itemId, $link['description'] ?: null);
}

echo 'Saved ' . count($anchorLinks) . " anchor links.\n";

$metadata = HTMLLoader::extractMetadata($document);

// A JS bot-challenge interstitial ("Just a moment..." - Cloudflare's, the
// one actually seen in this crawl), not the real page behind it - see
// TODO.md for the planned headless-browser fix. For now: mark crawled
// anyway (so nextToCrawl() stops retrying it) but keep whatever title/
// description this item already had from being discovered as a link,
// rather than overwriting them with the challenge page's own placeholder
// metadata. fullHTML is still saved - it's exactly what a future
// headless-browser pass would need to inject and re-evaluate, so there's no
// reason to throw it away. The images/links already extracted from this
// page's markup above are kept regardless of any of this.
if (in_array($metadata['title'], JS_CHALLENGE_TITLES, true)) {
    $item -> markCrawled($contentType -> type, $item -> title, $item -> description, $item -> keywords, null, $html);
    echo "JS challenge page, marked crawled (kept existing title/description).\n";
    exit(0);
}

HTMLLoader::removeStyleAndScriptTags($document);
HTMLLoader::removeBoilerplateElements($document);
$bodyText = HTMLLoader::extractBodyText($document);

// Plenty of pages (CERN's own included) emit no description/OG/Twitter/
// JSON-LD description at all - the first 500 chars of the page's own text
// is a reasonable stand-in over leaving it null, both for display and for
// FULLTEXT search relevance.
$description = $metadata['description'] ?? mb_substr($bodyText, 0, 500);

$item -> markCrawled($contentType -> type, $metadata['title'], $description, $metadata['keywords'], $bodyText, $html);

echo "Marked crawled.\n";
