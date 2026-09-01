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

// Well under bin/crawler-manager.php's hang-kill timeout, so a PDF that won't
// extract costs this run a few seconds rather than the whole worker.
const PDF_EXTRACT_TIMEOUT_SECONDS = 20;
const PDF_EXTRACT_STALE_SECONDS = 3600;

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
 * The Host for $url, with robots.txt read (or re-read) if what's on file is
 * missing or stale - and, on the visits where a real robots.txt just
 * arrived, its declared sitemaps ingested into the queue (see Sitemap).
 * Piggybacked here because this is the one moment the Sitemap: lines are
 * known to be fresh, and it puts sitemap ingestion on exactly the same
 * weekly-per-host cadence as the rules themselves.
 *
 * Null when the hostname resolves somewhere this crawler won't send a
 * request (Host::isPubliclyRoutable()). Checked here rather than alongside
 * the robots.txt verdict in crawlPermission(), because reading robots.txt is
 * itself a request to the host - by the time there's a verdict to check, the
 * request the check exists to prevent has already gone out.
 */
function hostFor(URL $url, string $chromeEndpoint): ?Host
{
    $host = Host::findOrCreateByName($url -> host);

    if (!$host -> isPubliclyRoutable()) {
        return null;
    }

    if ($host -> fetchRobotsTxtIfStale($url -> scheme, $chromeEndpoint) && $host -> robotsTxt !== null && $host -> robotsTxt !== '') {
        $queued = Sitemap::ingestFor($host, $host -> robotsTxt);

        if ($queued > 0) {
            echo 'Queued ' . $queued . ' URL(s) from ' . $url -> host . '\'s sitemap.
';
        }
    }

    return $host;
}

/**
 * The X-Robots-Tag header's directives, same shape as
 * HTMLLoader::robotsDirectives() - the header form applies to any content
 * type, which is exactly why it exists (a PDF or image has no <meta> to
 * carry noindex in).
 */
function header_robots_directives(?string $header): array
{
    $tokens = array_map('trim', explode(',', strtolower((string) $header)));
    $none = in_array('none', $tokens, true);

    return [
        'noindex' => $none || in_array('noindex', $tokens, true),
        'nofollow' => $none || in_array('nofollow', $tokens, true),
    ];
}

function pdftotext_available(): bool
{
    static $available = null;

    return $available ??= trim((string) shell_exec('command -v pdftotext 2>/dev/null')) !== '';
}

/**
 * The text layer of a PDF, extracted with poppler's pdftotext and whitespace-
 * normalized. Empty string when there's nothing extractable - a scanned
 * document, an encrypted file, something pdftotext can't parse.
 */
function pdf_to_text(string $pdf): string
{
    $path = VAR_DIR . '/pdf-extract-' . getmypid() . '.pdf';
    file_put_contents($path, $pdf);

    // Run under a timeout: the PDF is a file the site being crawled wrote,
    // and a crafted one can keep pdftotext busy indefinitely. Without a limit
    // of its own here, the only thing that ever ends it is
    // bin/crawler-manager.php SIGKILLing the whole worker - which charges the
    // item a hang strike and, three of those in, deletes it, for what is
    // really just a file this crawler can't read.
    $text = (string) shell_exec(
        'timeout ' . PDF_EXTRACT_TIMEOUT_SECONDS . ' pdftotext -q -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null'
    );
    unlink($path);
    sweep_stale_pdf_extracts();

    return HTMLLoader::normalizeWhitespace($text);
}

/**
 * Removes PDF scratch files left behind by workers that were killed between
 * writing one and unlinking it. Each is named for the pid that wrote it, so
 * they're only ever reclaimed by chance otherwise - and a site serving PDFs
 * that reliably hang the extractor gets to leave one behind per attempt.
 */
function sweep_stale_pdf_extracts(): void
{
    foreach (glob(VAR_DIR . '/pdf-extract-*.pdf') ?: [] as $path) {
        if (is_file($path) && time() - (int) filemtime($path) > PDF_EXTRACT_STALE_SECONDS) {
            @unlink($path);
        }
    }
}

/**
 * Whether $url may be fetched right now, printing why not when it may not.
 * Three separate answers, and the difference matters to the caller: a host
 * whose rules can't be read isn't the same as one whose rules say no, and
 * neither is the same as a host that's simply still on cooldown.
 */
function crawlPermission(Host $host, URL $url): string
{
    if (!$host -> isRobotsTxtKnown()) {
        return 'robots-unknown';
    }

    if ($host -> isDisallowed($url -> pathAndQuery())) {
        return 'disallowed';
    }

    return 'allowed';
}

// An optional worker slot number (bin/crawler-manager.php passes one when
// running several of these concurrently) keeps each worker's "what am I
// working on" file separate - otherwise concurrent workers would clobber a
// single shared file and a hang could be blamed on the wrong item entirely.
$workerSlot = $argv[1] ?? null;
$currentItemFile = $workerSlot !== null ? CURRENT_CRAWL_ITEM_FILE . '-' . $workerSlot : CURRENT_CRAWL_ITEM_FILE;

// Cleared before anything else this run does - the file still holds whatever
// item the slot's previous run was on, and a run that dies before picking
// its own item (killed mid-startup, mid-queue-query, anywhere pre-pick)
// would otherwise have that stale id read back as "the item this run hung
// on". That exact misattribution has charged hang strikes to - and at three
// strikes deleted - items whose only involvement was being worked on by an
// earlier, unrelated run of the same slot.
file_put_contents($currentItemFile, '');

// bin/crawler-manager.php owns the one shared, persistent Chrome instance
// every real fetch goes through (ChromeConnection, HeadlessBrowser) and
// keeps this file pointed at its current "host:port" - checked before ever
// calling Item::nextToCrawl() so a Chrome outage doesn't needlessly churn
// through claiming items it has no way to actually fetch.
$chromeEndpoint = is_file(CHROME_DEVTOOLS_ENDPOINT_FILE) ? trim(file_get_contents(CHROME_DEVTOOLS_ENDPOINT_FILE)) : '';

if ($chromeEndpoint === '') {
    echo 'No Chrome instance available (bin/crawler-manager.php not running or still starting up), nothing to do this run.
';
    exit(0);
}

$topic = trim((string) Setting::value(CRAWL_TOPIC_SETTING));
$item = Item::nextToCrawl($topic !== '' ? $topic : null);

if ($item === null) {
    // Clear this slot's "what am I working on" file - this run touched no
    // item, so it must not leave a previous run's itemId sitting there for
    // bin/crawler-manager.php to misread as "the item this slot just
    // finished" and wrongly reset that item's hang counter (which, for an
    // item that reliably hangs while it's the only thing left in the queue,
    // would stop it ever reaching the 3-strikes deletion).
    file_put_contents($currentItemFile, '');
    echo 'Nothing to crawl.
';
    exit(0);
}

echo 'Next up: ' . $item -> url . ' (itemId ' . $item -> itemId . ')
';

// Written before any network I/O - if this process hangs and gets killed by
// bin/crawler-manager.php, this file is the only way that script can find
// out which item was being worked on when it died.
file_put_contents($currentItemFile, (string) $item -> itemId);

$pageURL = new URL($item -> url);
$host = hostFor($pageURL, $chromeEndpoint);

if ($host === null) {
    $item -> delete('private-address');
    echo 'Host resolves to a private or reserved address, deleted this item.
';
    exit(0);
}

$permission = crawlPermission($host, $pageURL);

if ($permission === 'robots-unknown') {
    // The host's rules couldn't be read at all, so there's no way to know
    // whether this is allowed. Nothing is wrong with the item - it's left
    // exactly as it was for a later run, once robots.txt is readable again
    // (Host::fetchRobotsTxtIfStale() retries on its own schedule).
    echo 'Couldn\'t read robots.txt for this host, leaving item for retry.
';
    exit(0);
}

if ($permission === 'disallowed') {
    $item -> delete('robots-disallowed');
    echo 'robots.txt disallows this path, deleted this item.
';
    exit(0);
}

// The real page (if any) that led here, per the Links table - passed to
// Chrome as the navigation's actual referrer (see ChromeConnection) rather
// than guessed. Computed once, from the item as originally claimed, and
// reused unchanged through every redirect hop below - a real browser
// following a redirect keeps the referrer that started the navigation, it
// doesn't update it hop to hop.
$referrerURL = Link::findParentURL($item -> itemId);
$referrer = $referrerURL !== null ? new URL($referrerURL) : null;

try {
    $connection = new ChromeConnection($chromeEndpoint, $pageURL, $referrer);
} catch (\Throwable $exception) {
    // The shared Chrome instance itself is unreachable/crashed - an
    // infrastructure problem, not anything wrong with this item, so it's
    // left alone (claim expires, nextToCrawl() retries it later) rather
    // than deleted.
    echo 'Chrome instance unreachable (' . $exception -> getMessage() . '), leaving item for retry.
';
    exit(0);
}

recordHostCrawl($host, $connection -> statusCode);

if ($connection -> statusCode === null) {
    // The connection itself never completed (DNS failure, connection
    // refused, TLS handshake failure, ...). That's a fact about the *host* -
    // the same hostname every other queued URL on it shares - not about this
    // URL, so the item is left for retry and the host backs off on an
    // escalating schedule instead. A host that's really gone decays to one
    // connection attempt a week; one that was momentarily unreachable is
    // back in rotation in minutes, with nothing deleted over the blip.
    $host -> recordFailure();
    echo 'Connection failed (' . $host -> consecutiveFailures . ' in a row on this host), backing the host off, item left for retry.
';
    exit(0);
}

for ($hop = 0; in_array($connection -> statusCode, REDIRECT_STATUS_CODES, true); $hop++) {
    if ($hop >= MAX_REDIRECTS) {
        $connection -> readBody();
        $item -> delete('too-many-redirects');
        echo 'Too many redirects, deleted this item.
';
        exit(0);
    }

    $location = $connection -> headers['location'] ?? null;
    $connection -> readBody(); // drain + close before opening the next hop's connection

    if ($location === null) {
        $item -> delete('redirect-without-location');
        echo 'Redirect status with no Location header, deleted this item.
';
        exit(0);
    }

    $redirectTarget = $pageURL -> resolve(new URL($location));

    if (!$redirectTarget -> isValid()) {
        $item -> delete('redirect-target-invalid');
        echo 'Redirect target isn\'t a real URL, deleted this item.
';
        exit(0);
    }

    $previousItemId = $item -> itemId;
    $item = $item -> redirectTo($redirectTarget);

    if ($item === null) {
        // The pre-existing row this redirect landed on was deleted by another
        // worker while it was being read back. Nothing was lost - this item
        // never had content of its own, only an address that turned out to
        // belong elsewhere.
        echo 'Redirect target disappeared while following it, nothing more to do.
';
        exit(0);
    }

    echo 'Redirected to: ' . $item -> url . ' (itemId ' . $item -> itemId . ')
';

    if ($item -> crawledTime !== null) {
        echo 'Redirect target already crawled, nothing more to do.
';
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
        echo 'Redirect target already claimed by another worker, leaving it to them.
';
        exit(0);
    }

    // Repoint the hang-tracking file at the item actually being worked on now:
    // a redirect onto a pre-existing item changes the itemId, and redirectTo()
    // has already deleted the original row, so a hang here would otherwise be
    // blamed on (and its 3-strikes deletion aimed at) an item that no longer
    // exists instead of the redirect target that's actually stuck.
    file_put_contents($currentItemFile, (string) $item -> itemId);

    $pageURL = new URL($item -> url);
    $host = hostFor($pageURL, $chromeEndpoint);

    // A redirect is the most direct way to aim this crawler at an address it
    // would never have followed a link to: the URL that was checked and the
    // URL that gets fetched are, by definition, not the same one.
    if ($host === null) {
        $item -> delete('private-address');
        echo 'Redirect target\'s host resolves to a private or reserved address, deleted this item.
';
        exit(0);
    }

    $permission = crawlPermission($host, $pageURL);

    if ($permission === 'robots-unknown') {
        echo 'Couldn\'t read robots.txt for the redirect target\'s host, leaving item for retry.
';
        exit(0);
    }

    if ($permission === 'disallowed') {
        $item -> delete('robots-disallowed');
        echo 'robots.txt disallows the redirect target, deleted this item.
';
        exit(0);
    }

    // A redirect crossing onto a different host means a request to a host
    // this worker never reserved, and which may have been hit moments ago by
    // someone else. Same reservation as the original fetch - lose it and the
    // item waits rather than jumping that host's cooldown just because a
    // redirect pointed at it.
    if (!$host -> reserve()) {
        echo 'The redirect target\'s host is already spoken for, leaving item for retry.
';
        exit(0);
    }

    try {
        $connection = new ChromeConnection($chromeEndpoint, $pageURL, $referrer);
    } catch (\Throwable $exception) {
        echo 'Chrome instance unreachable (' . $exception -> getMessage() . '), leaving item for retry.
';
        exit(0);
    }

    recordHostCrawl($host, $connection -> statusCode);

    if ($connection -> statusCode === null) {
        $host -> recordFailure();
        echo 'Connection failed (' . $host -> consecutiveFailures . ' in a row on this host), backing the host off, item left for retry.
';
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
    echo 'Status ' . $connection -> statusCode . ', backing off this host, item left for retry.
';
    exit(0);
}

if ($connection -> statusCode < 200 || $connection -> statusCode >= 300) {
    // A 404, 500, 403, ... never has real content behind it worth keeping -
    // whatever body an error page returns isn't presentable, and there's
    // nothing to retry here (the URL is what it is), so this is exactly the
    // same call as an unrecoverable redirect: delete, don't markCrawled().
    $connection -> readBody();
    $item -> delete('status-' . $connection -> statusCode);
    echo 'Status ' . $connection -> statusCode . ', deleted this item.
';
    exit(0);
}

$contentType = $connection -> contentType();

// X-Robots-Tag applies whatever the content type turns out to be - read once
// here, merged with the meta-tag form later for HTML.
$headerDirectives = header_robots_directives($connection -> headers['x-robots-tag'] ?? null);

if ($contentType !== null && $contentType -> isImage()) {
    $imageData = $connection -> readBody();

    // SVG has no GD decoder at all, so it goes through the shared browser to
    // be rendered into one this crawler can actually thumbnail - it's real
    // page content (diagrams, logos, charts), not the broken-image case the
    // rest of this branch is about.
    $image = $contentType -> isSVG()
        ? ImageLoader::loadSVG($imageData, $item -> itemId, $chromeEndpoint)
        : ImageLoader::load($imageData, $item -> itemId);

    if ($image === null) {
        // Content-Type claimed image/*, but nothing usable came out - either
        // it wasn't decodable at all (a corrupt file, a format GD doesn't
        // support, an SVG the browser wouldn't render) or its dimensions
        // ruled it out (a decompression bomb, or too small to be more than a
        // tracking pixel/spacer/decorative icon). Either way there's nothing
        // to keep, same reasoning as deleting an unrecoverable redirect.
        $item -> delete('image-unusable');
        echo 'Couldn\'t use image (undecodable or unusable dimensions), deleted this item.
';
        exit(0);
    }

    // Keep whatever title/description/keywords this item already had (e.g.
    // the parent-node text captured when it was first discovered as a link)
    // rather than wiping them out - an image has no metadata of its own to
    // extract that would replace them, real or otherwise.
    $item -> markCrawled($contentType -> type, $item -> title, $item -> description, $item -> keywords, null, null, $headerDirectives['noindex'] ? 1 : 0);

    echo 'Saved thumbnail, marked crawled.
';
    exit(0);
}

if ($contentType !== null && $contentType -> isPDF() && pdftotext_available()) {
    $text = pdf_to_text($connection -> readBody());

    if ($text === '') {
        // Undecodable, or a scanned document with no text layer - either
        // way there's nothing searchable in it, same call as an image that
        // wouldn't decode.
        $item -> delete('pdf-unusable');
        echo 'Couldn\'t extract any text from PDF, deleted this item.
';
        exit(0);
    }

    // A PDF has no meta tags to mine; the discovery-time title (the anchor
    // text that linked here) stands, and the text itself stands in for a
    // description the way an HTML page's body text does.
    $item -> markCrawled($contentType -> type, $item -> title, $item -> description ?? mb_substr($text, 0, 500), $item -> keywords, $text, null, $headerDirectives['noindex'] ? 1 : 0);
    echo 'Extracted ' . mb_strlen($text) . ' characters of PDF text, marked crawled.
';
    exit(0);
}

if ($contentType !== null && $contentType -> isPlainText()) {
    $text = $connection -> readBody();

    if ($contentType -> charset !== null && !in_array(strtoupper($contentType -> charset), ['UTF-8', 'UTF8'], true)) {
        $converted = @mb_convert_encoding($text, 'UTF-8', $contentType -> charset);

        if ($converted !== false) {
            $text = $converted;
        }
    }

    $text = HTMLLoader::normalizeWhitespace($text);

    if ($text === '') {
        $item -> delete('empty-text');
        echo 'Empty text file, deleted this item.
';
        exit(0);
    }

    $item -> markCrawled($contentType -> type, $item -> title, $item -> description ?? mb_substr($text, 0, 500), $item -> keywords, $text, null, $headerDirectives['noindex'] ? 1 : 0);
    echo 'Saved ' . mb_strlen($text) . ' characters of plain text, marked crawled.
';
    exit(0);
}

if ($contentType === null || !$contentType -> isHTML()) {
    // Not something this crawler knows how to turn into presentable content
    // (a video, a zip, a PDF on a box without pdftotext, no Content-Type at
    // all, ...) - deleting rather than leaving crawledTime NULL is what
    // keeps this from being handed back by nextToCrawl() and retried forever
    // with the same non-result every single run.
    $connection -> readBody();
    $item -> delete('not-html');
    echo 'Not HTML (' . ($contentType ?-> type ?? 'no response') . '), deleted this item.
';
    exit(0);
}

$html = $connection -> readBody();
$document = HTMLLoader::load($html, $contentType -> charset);
$baseURL = HTMLLoader::baseURL($document, $pageURL);
HTMLLoader::separateBlockElements($document);
HTMLLoader::inlineImageAltText($document);
$metadata = HTMLLoader::extractMetadata($document);

// A JS bot-challenge interstitial ("Just a moment..." - Cloudflare's, the
// one actually seen in this crawl), not the real page behind it - hand the
// already-fetched HTML to a real headless browser and let its JS resolve
// the challenge (see HeadlessBrowser, TODO.md). If that succeeds and the
// result isn't itself another challenge, treat it as what was actually
// fetched from here on - the same $html/$document/$baseURL/$metadata
// variables, so everything below (images, links, body text) runs against
// the real content instead of the challenge shell.
if (in_array($metadata['title'], JS_CHALLENGE_TITLES, true)) {
    $resolvedHTML = HeadlessBrowser::resolveChallenge($chromeEndpoint, $html, $pageURL);

    if ($resolvedHTML !== null) {
        $resolvedDocument = HTMLLoader::load($resolvedHTML, $contentType -> charset);
        $resolvedMetadata = HTMLLoader::extractMetadata($resolvedDocument);

        if (!in_array($resolvedMetadata['title'], JS_CHALLENGE_TITLES, true)) {
            $html = $resolvedHTML;
            $document = $resolvedDocument;
            $baseURL = HTMLLoader::baseURL($document, $pageURL);
            HTMLLoader::separateBlockElements($document);
            HTMLLoader::inlineImageAltText($document);
            $metadata = $resolvedMetadata;
        }
    }

    if (in_array($metadata['title'], JS_CHALLENGE_TITLES, true)) {
        // Still a challenge - no headless browser available, or it timed
        // out, or the challenge script itself didn't resolve inside its
        // budget. Mark crawled anyway (so nextToCrawl() stops retrying it)
        // but keep whatever title/description this item already had from
        // being discovered as a link, rather than overwriting them with the
        // challenge page's own placeholder metadata. fullHTML is still
        // saved - useful for a future retry once headless browsing works
        // for this site, or is available at all.
        $item -> markCrawled($contentType -> type, $item -> title, $item -> description, $item -> keywords, null, $html, $headerDirectives['noindex'] ? 1 : 0);
        echo 'JS challenge page, marked crawled (kept existing title/description).
';
        exit(0);
    }

    echo 'JS challenge resolved via headless browser.
';
}

// A canonical declaration pointing somewhere else is the site saying "this
// content's real address is over there" - the same statement a redirect
// makes, handled the same way: merge this item into the canonical URL's row.
// Print variants, session-id spellings, and mobile mirrors all collapse into
// one result instead of competing with themselves in the rankings. The
// content just fetched is stored under the canonical URL (no second fetch -
// the site itself says they're the same document), except when the canonical
// row was already crawled, in which case its own crawl stands and this
// duplicate simply dissolves into it.
$canonical = HTMLLoader::canonicalURL($document, $baseURL);

if ($canonical !== null && $canonical -> toString() !== $item -> url) {
    $previousItemId = $item -> itemId;
    $item = $item -> redirectTo($canonical);

    if ($item === null) {
        echo 'Canonical target disappeared mid-merge, nothing more to do.
';
        exit(0);
    }

    echo 'Canonical URL: ' . $item -> url . ' (itemId ' . $item -> itemId . ')
';

    if ($item -> crawledTime !== null) {
        echo 'Canonical target already crawled, merged into it.
';
        exit(0);
    }

    // Same claim discipline as a redirect merge: a pre-existing row at the
    // canonical URL was never claimed by this worker, and another one could
    // be crawling it right now.
    if ($item -> itemId !== $previousItemId && !$item -> reclaim()) {
        echo 'Canonical target already claimed by another worker, leaving it to them.
';
        exit(0);
    }

    file_put_contents($currentItemFile, (string) $item -> itemId);
}

// Page-level robots directives, from whichever channel the site used - the
// header form (already read) or the meta tag. Either saying noindex/nofollow
// counts; they're the same statement addressed differently.
$metaDirectives = HTMLLoader::robotsDirectives($document);
$noindex = $headerDirectives['noindex'] || $metaDirectives['noindex'];
$nofollow = $headerDirectives['nofollow'] || $metaDirectives['nofollow'];

if ($nofollow) {
    // The page asked for its links not to be followed - skip discovery
    // entirely, then index (or not, per noindex) the page itself as normal.
    HTMLLoader::removeStyleAndScriptTags($document);
    HTMLLoader::removeBoilerplateElements($document);
    $bodyText = HTMLLoader::extractBodyText($document);

    $item -> markCrawled($contentType -> type, $metadata['title'], $metadata['description'] ?? mb_substr($bodyText, 0, 500), $metadata['keywords'], $bodyText, $html, $noindex ? 1 : 0);
    echo 'Marked crawled (nofollow - no link discovery' . ($noindex ? ', noindex' : '') . ').
';
    exit(0);
}

$images = HTMLLoader::extractImageLinks($document, $baseURL);
$anchorLinks = HTMLLoader::extractAnchorLinks($document, $baseURL);

// Everything the page points at, gathered before anything is written. A page
// links hundreds of URLs and the per-link round trips to record them cost
// more than parsing the page - collecting first means one batch of
// statements instead of five per link (see Item::findOrCreateManyByURL()).
// Keyed by normalized URL. A page referring to the same target twice is one
// discovery, not two: `inc` says how many *pages* point at a URL, and letting
// one page's repetitions add up makes it a number that page sets for itself.
// A single page can otherwise run one URL's `inc` into the hundreds off one
// link edge, past URLs linked from hundreds of pages across dozens of domains.
$discoveries = [];

foreach ($images as $image) {
    // Only checked for same-host links - robots.txt is this site's own
    // rules for its own paths, not a statement about paths on other sites
    // it happens to link to, and checking every external host would mean
    // fetching robots.txt for every domain a page links to just to discover
    // its links, not just the ones actually crawled.
    if ($image['url'] -> host === $pageURL -> host && $host -> isDisallowed($image['url'] -> pathAndQuery())) {
        continue;
    }

    $urlString = $image['url'] -> toString();

    if (isset($discoveries[$urlString])) {
        continue;
    }

    $discoveries[$urlString] = [
        'url' => $image['url'],
        'type' => 'image',
        'description' => $image['description'] ?: null,
        'count' => 1,
    ];
}

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
    if ($link['url'] -> host === $pageURL -> host && $host -> isDisallowed($link['url'] -> pathAndQuery())) {
        continue;
    }

    $urlString = $link['url'] -> toString();

    if (isset($discoveries[$urlString])) {
        continue;
    }

    // "unknown" rather than a guess like images get "image" - a href can
    // point at absolutely anything (another page, a PDF, an image), and
    // there's no equivalent to "found via <img>" telling us which.
    $discoveries[$urlString] = [
        'url' => $link['url'],
        'type' => 'unknown',
        'description' => $link['description'] ?: null,
        'count' => 1,
    ];
}

// Whatever comes back entered the queue; the rest was already dead or on a
// host with more waiting than it can work through.
$discovered = Item::findOrCreateManyByURL($discoveries);
$links = [];

foreach ($discovered as $urlString => $discoveredItem) {
    $links[$discoveredItem -> itemId] = $discoveries[$urlString]['description'];
}

// inc counts distinct linking pages, so it's raised off the edges that were
// actually new rather than off every link the page happens to carry this
// time round.
Item::countInboundLinks(Link::createMany($item -> itemId, $links));

echo 'Saved ' . count($discovered) . ' of ' . count($discoveries) . ' discovered URLs (' . count($images) . ' images, ' . count($anchorLinks) . ' links on the page).
';

HTMLLoader::removeStyleAndScriptTags($document);
HTMLLoader::removeBoilerplateElements($document);
$bodyText = HTMLLoader::extractBodyText($document);

// Plenty of pages (CERN's own included) emit no description/OG/Twitter/
// JSON-LD description at all - the first 500 chars of the page's own text
// is a reasonable stand-in over leaving it null, both for display and for
// FULLTEXT search relevance.
$description = $metadata['description'] ?? mb_substr($bodyText, 0, 500);

$item -> markCrawled($contentType -> type, $metadata['title'], $description, $metadata['keywords'], $bodyText, $html, $noindex ? 1 : 0);

echo 'Marked crawled' . ($noindex ? ' (noindex - excluded from search)' : '') . '.
';
