<?php

declare(strict_types=1);

/**
 * Parses an HTML document's bytes into a DOMDocument. The charset comes from
 * the strongest signal available, in the order the WHATWG algorithm ranks
 * them: a byte-order mark outranks everything (it's the encoder itself
 * speaking), then the Content-Type header's charset parameter, then a <meta
 * charset> sniffed from the first bytes of the document, then UTF-8. Legacy
 * pages routinely declare their encoding only in the markup, and reading
 * those as UTF-8 indexed their entire text as mojibake.
 */
class HTMLLoader
{
    /**
     * Matched like real CSS class selectors: a whole, exact token in the
     * space-separated class list (or the whole id), never a substring of a
     * longer compound word - a raw substring check on "header" would also
     * match Bootstrap's "page-header" (a plain content-heading style class,
     * whose removal takes the page's actual <h1> with it) and "nav" would
     * match Drupal's "section-navigation" (a real content/layout section, not
     * a menu). Real chrome elements almost
     * always carry one of these as a clean, standalone token, so this list
     * can afford to include bare, generic words like "nav"/"header" - it's
     * the exact-token matching that makes that safe, not the word choice.
     */
    private const BOILERPLATE_CLASS_TOKENS = [
        'nav', 'navbar', 'navigation', 'main-nav', 'site-nav', 'sub-nav', 'subnav', 'nav-menu', 'primary-nav', 'top-nav', 'mobile-nav',
        'header', 'site-header', 'main-header', 'masthead', 'header-wrapper', 'region-header',
        'footer', 'site-footer', 'main-footer', 'footer-widgets', 'footer-wrapper',
        'sidebar', 'side-bar', 'widget-area',
        'menu', 'main-menu', 'dropdown-menu', 'mobile-menu',
        'breadcrumb', 'breadcrumbs',
        'cookie', 'cookies', 'cookie-banner', 'cookie-consent', 'cookie-notice',
        'banner', 'advert', 'advertisement',
        'popup', 'modal', 'overlay',
        'social-share', 'share-buttons',
        'toolbar', 'skip-link',
    ];

    /**
     * "ad"/"ads" specifically, matched with \b...\b against the *raw*
     * class/id string rather than exact-tokenized like the list above. A
     * regex word boundary treats a hyphen the same as a space - "ad-banner",
     * "google-ad", "ads-container" all match - which is exactly why
     * "header"/"nav" above can't use this (it'd match "page-header" the same
     * way a bare substring did). But there's no known real content-class
     * convention that reuses "ad"/"ads" the way Bootstrap's "page-header"
     * reuses "header", so the looser match is safe here while still
     * correctly rejecting "already", "load", "read", "gradient", ... (in all
     * of those, "ad" sits between two letters, never at a boundary).
     */
    private const AD_WORD_BOUNDARY_PATTERN = '/\b(?:ad|ads)\b/i';

    /**
     * How many <a>/<img> tags are taken off a single page. Real pages that
     * genuinely list thousands of links exist (index and category pages), so
     * this sits far above them - it's here to stop one page's markup deciding
     * how long this crawler spends on it, not to second-guess honest pages.
     */
    private const MAX_LINKS_PER_PAGE = 10000;

    /**
     * How much of a node's text is read when describing a link found inside
     * it. A description is stored at a few hundred characters, so nothing is
     * lost by stopping here - and without a stop, describing each of a page's
     * links means walking that link's whole parent subtree, once per link.
     * On the shape that actually provokes it (thousands of anchors sharing
     * one parent, or nested one inside the next) that is quadratic in the
     * size of the page, and a page is free to choose its own shape.
     */
    private const MAX_DESCRIPTION_SOURCE_LENGTH = 20000;

    public static function load(string $html, ?string $charset): \DOMDocument
    {
        $bomCharset = self::bomCharset($html);

        if ($bomCharset !== null) {
            $charset = $bomCharset;
            $html = substr($html, $bomCharset === 'UTF-8' ? 3 : 2);
        } elseif ($charset === null) {
            $charset = self::sniffMetaCharset($html);
        }

        $charset = strtoupper($charset ?? 'UTF-8');

        if (!in_array($charset, ['UTF-8', 'UTF8'], true)) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);

            if ($converted !== false) {
                $html = $converted;
            }
        }

        $document = new \DOMDocument();

        libxml_use_internal_errors(true);

        // Real-world HTML is full of things libxml considers errors (unclosed
        // tags, unknown attributes, ...) that browsers render fine regardless -
        // suppressing them here is what lets loadHTML() parse "tag soup" the
        // same way a browser would rather than refusing to.
        //
        // The prepended declarations force libxml to trust the UTF-8 the
        // string was just normalized to, instead of re-guessing the encoding
        // from the raw bytes. The meta declaration must come before any stale
        // declaration in the source: older libxml releases let a later HTML
        // meta override the XML processing instruction and decode converted
        // text a second time.
        $document -> loadHTML('<?xml encoding="UTF-8"><meta charset="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        foreach ($document -> childNodes as $node) {
            if ($node -> nodeType === XML_PI_NODE) {
                $document -> removeChild($node);
                break;
            }
        }

        $document -> encoding = 'UTF-8';

        return $document;
    }

    /**
     * The encoding a byte-order mark declares, or null when there isn't one.
     * A BOM outranks every header and meta tag - it was written by whatever
     * encoded the file, so it can't be out of step with the bytes the way a
     * copy-pasted meta tag or a misconfigured server header can.
     */
    private static function bomCharset(string $html): ?string
    {
        if (str_starts_with($html, chr(0xEF) . chr(0xBB) . chr(0xBF))) {
            return 'UTF-8';
        }

        if (str_starts_with($html, chr(0xFF) . chr(0xFE))) {
            return 'UTF-16LE';
        }

        if (str_starts_with($html, chr(0xFE) . chr(0xFF))) {
            return 'UTF-16BE';
        }

        return null;
    }

    /**
     * The charset declared in the document's own markup - "<meta
     * charset=...>" or the older http-equiv Content-Type form, both of which
     * reduce to finding "charset", an equals sign, and a name. Only the
     * first 2 KiB is searched, per the sniffing convention browsers follow:
     * a real declaration sits at the top of <head>, and anything claiming to
     * be one deep in the body text isn't.
     */
    private static function sniffMetaCharset(string $html): ?string
    {
        $head = substr($html, 0, 2048);
        $position = stripos($head, 'charset');

        while ($position !== false) {
            $cursor = $position + strlen('charset');

            while ($cursor < strlen($head) && ($head[$cursor] === ' ' || $head[$cursor] === chr(9))) {
                $cursor++;
            }

            if ($cursor < strlen($head) && $head[$cursor] === '=') {
                $cursor++;

                while ($cursor < strlen($head) && in_array($head[$cursor], [' ', chr(9), '"', '\''], true)) {
                    $cursor++;
                }

                $name = '';

                while ($cursor < strlen($head) && (ctype_alnum($head[$cursor]) || in_array($head[$cursor], ['-', '_', '.'], true))) {
                    $name .= $head[$cursor];
                    $cursor++;
                }

                if ($name !== '') {
                    return $name;
                }
            }

            $position = stripos($head, 'charset', $position + 1);
        }

        return null;
    }

    /**
     * The URL every other relative link on the page actually resolves
     * against. Normally that's just the page's own URL, but a <base href>
     * overrides it - and that href is itself often relative (e.g. "/en/"),
     * so it has to be resolved against the page URL before it can be used to
     * resolve anything else. Only the first <base> with a real href counts;
     * later ones and hrefless <base target="...">-only tags are ignored.
     */
    public static function baseURL(\DOMDocument $document, URL $pageURL): URL
    {
        foreach ($document -> getElementsByTagName('base') as $base) {
            $href = trim($base -> getAttribute('href'));

            if ($base -> hasAttribute('href') && $href !== '') {
                $resolved = $pageURL -> resolve(new URL($href));

                // A hostile/malformed <base href> (this is untrusted markup)
                // shouldn't take down every other link on the page with it -
                // fall back to the page's own URL rather than resolve
                // everything else against garbage.
                return $resolved -> isValid() ? $resolved : $pageURL;
            }
        }

        return $pageURL;
    }

    /**
     * The page-level robots directives - meta name="robots" - as
     * ['noindex' => bool, 'nofollow' => bool]. "none" is shorthand for both,
     * per the spec. The crawler honors these the way it honors robots.txt:
     * noindex keeps the page out of search results (it's still crawled, and
     * its links still count), nofollow stops link discovery on the page.
     */
    public static function robotsDirectives(\DOMDocument $document): array
    {
        $content = strtolower((string) self::metaContent($document, 'name', 'robots'));
        $tokens = array_map('trim', explode(',', $content));

        $none = in_array('none', $tokens, true);

        return [
            'noindex' => $none || in_array('noindex', $tokens, true),
            'nofollow' => $none || in_array('nofollow', $tokens, true),
        ];
    }

    /**
     * The page's declared canonical URL (<link rel="canonical">), resolved
     * and validated, or null when there isn't a usable one. Only the first
     * counts, same as <base>. A canonical equal to the page's own URL - by
     * far the common case - is still returned; the caller compares.
     */
    public static function canonicalURL(\DOMDocument $document, URL $baseURL): ?URL
    {
        foreach ($document -> getElementsByTagName('link') as $link) {
            $rel = preg_split('/\s+/', strtolower($link -> getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY);

            if (!in_array('canonical', $rel, true)) {
                continue;
            }

            $href = trim($link -> getAttribute('href'));

            if ($href === '') {
                return null;
            }

            $resolved = $baseURL -> resolve(new URL($href));

            return $resolved -> isValid() ? $resolved : null;
        }

        return null;
    }

    /**
     * Tags whose boundaries separate text when a browser renders them -
     * injected as newlines before textContent is ever read. textContent
     * itself concatenates everything with no separators at all, which reads
     * fine on pretty-printed markup (the source's own indentation supplies
     * the whitespace) and glues words together on minified markup:
     * "<h1>Title</h1><p>First" extracts as "TitleFirst" without this.
     */
    private const BLOCK_TAGS = [
        'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt',
        'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tr', 'ul',
    ];

    // Cells sit side by side on one rendered line, so they separate with a
    // space rather than a line break.
    private const CELL_TAGS = ['td', 'th'];

    /**
     * Injects the whitespace a renderer's line-breaking would have implied,
     * so a plain textContent walk afterwards reads like the visible page:
     * a newline on each side of every block-level element (the pair around
     * adjacent blocks collapses to one paragraph break in
     * normalizeWhitespace()), a newline after each <br>, a space after each
     * table cell. Run right after load(), before anything reads text out of
     * the document - the per-anchor/per-image description walks need it as
     * much as the body text does.
     */
    public static function separateBlockElements(\DOMDocument $document): void
    {
        $newline = chr(10);

        foreach (self::BLOCK_TAGS as $tagName) {
            foreach (iterator_to_array($document -> getElementsByTagName($tagName)) as $element) {
                $element -> parentNode ?-> insertBefore($document -> createTextNode($newline), $element);
                $element -> appendChild($document -> createTextNode($newline));
            }
        }

        foreach (iterator_to_array($document -> getElementsByTagName('br')) as $break) {
            $break -> parentNode ?-> insertBefore($document -> createTextNode($newline), $break -> nextSibling);
        }

        foreach (self::CELL_TAGS as $tagName) {
            foreach (iterator_to_array($document -> getElementsByTagName($tagName)) as $cell) {
                $cell -> appendChild($document -> createTextNode(' '));
            }
        }

        // Images get a space on each side: inlineImageAltText() expands alt
        // text inside them, and without the outer spacing that text lands
        // flush against whatever surrounds the tag - and an alt-less image
        // between two words would glue them outright.
        foreach (iterator_to_array($document -> getElementsByTagName('img')) as $img) {
            $img -> parentNode ?-> insertBefore($document -> createTextNode(' '), $img);
            $img -> parentNode ?-> insertBefore($document -> createTextNode(' '), $img -> nextSibling);
        }
    }

    /**
     * Inlines each <img>'s alt text into itself as a text node, padded with a
     * space on each side. Not valid HTML - <img> has no content model, and a
     * browser would never render this - but this DOM is never serialized
     * back out; it only ever gets walked for its text, and alt text (a photo
     * caption, a described chart) is real page content that a plain
     * textContent walk would otherwise skip entirely since it lives in an
     * attribute, not a child node. The padding keeps it from running into
     * whatever text sits right before/after the <img> in the markup.
     */
    public static function inlineImageAltText(\DOMDocument $document): void
    {
        foreach ($document -> getElementsByTagName('img') as $img) {
            $alt = $img -> getAttribute('alt');

            if (trim($alt) === '') {
                continue;
            }

            $img -> appendChild($document -> createTextNode(' ' . $alt . ' '));
        }
    }

    /**
     * Every image linked from the page, as ['url' => URL, 'description' =>
     * string] pairs - the description comes from the *parent* node's text
     * (the img's own inlined alt text, per inlineImageAltText(), plus
     * whatever else sits around it, e.g. a figure's caption), not the img
     * element alone, since an <img> itself never has text content of its
     * own to describe it. One img at a time, even though a page has many.
     *
     * src is untrusted - this is markup pulled off the open web, and a src
     * doesn't have to be a real, fetchable URL at all ("javascript:...",
     * garbage, empty). Anything that resolves to something isValid() reports
     * false for is dropped entirely, along with that img tag.
     */
    public static function extractImageLinks(\DOMDocument $document, URL $baseURL): array
    {
        $images = [];
        $descriptions = [];

        foreach ($document -> getElementsByTagName('img') as $img) {
            if (count($images) >= self::MAX_LINKS_PER_PAGE) {
                break;
            }

            $src = trim($img -> getAttribute('src'));

            if ($src === '') {
                continue;
            }

            $url = $baseURL -> resolve(new URL($src));

            if (!$url -> isValid()) {
                continue;
            }

            $images[] = [
                'url' => $url,
                'description' => self::describingText($img -> parentNode, $descriptions),
            ];
        }

        return $images;
    }

    /**
     * Every link on the page, as ['url' => URL, 'description' => string]
     * pairs - same shape as extractImageLinks(), and for the same reason:
     * the description comes from the *parent* node's text, not the anchor's
     * own textContent, since surrounding context (a list item, a caption)
     * often describes the link as much as the link text itself does. There's
     * no alt-text equivalent to inline first - an anchor's own text is
     * already a real child node, unlike an img's alt attribute.
     *
     * href is just as untrusted as an img's src - "javascript:", "mailto:",
     * "#fragment-only", empty, or malformed hrefs all get dropped along with
     * their anchor tag rather than resolved into something used further.
     */
    public static function extractAnchorLinks(\DOMDocument $document, URL $baseURL): array
    {
        $links = [];
        $descriptions = [];

        foreach ($document -> getElementsByTagName('a') as $anchor) {
            if (count($links) >= self::MAX_LINKS_PER_PAGE) {
                break;
            }

            $href = trim($anchor -> getAttribute('href'));

            if ($href === '') {
                continue;
            }

            // rel=nofollow (and its ugc/sponsored refinements) is the page
            // saying "this link is not my endorsement" - comment spam, paid
            // placements, user uploads. Following them anyway is how a
            // crawler gets steered by exactly the links pages trust least.
            $rel = preg_split('/\s+/', strtolower($anchor -> getAttribute('rel')), -1, PREG_SPLIT_NO_EMPTY);

            if (array_intersect(['nofollow', 'ugc', 'sponsored'], $rel) !== []) {
                continue;
            }

            $url = $baseURL -> resolve(new URL($href));

            if (!$url -> isValid()) {
                continue;
            }

            $links[] = [
                'url' => $url,
                'description' => self::describingText($anchor -> parentNode, $descriptions),
            ];
        }

        return $links;
    }

    /**
     * The text describing a link, from the node it sits in - normalized, cut
     * to MAX_DESCRIPTION_SOURCE_LENGTH, and remembered in $cache for the rest
     * of the page. The cache is what makes the ordinary case cheap: a list of
     * a thousand links shares one parent, and that parent's text is the same
     * answer a thousand times over.
     */
    private static function describingText(?\DOMNode $node, array &$cache): string
    {
        if ($node === null) {
            return '';
        }

        $key = spl_object_id($node);

        if (!isset($cache[$key])) {
            $cache[$key] = self::normalizeWhitespace(self::textUpTo($node, self::MAX_DESCRIPTION_SOURCE_LENGTH));
        }

        return $cache[$key];
    }

    /**
     * $node's text content, given up on once $maxLength characters are in
     * hand. textContent has no such stop - it builds the whole subtree's text
     * however large that is, which is the entire page when the node is the
     * body.
     *
     * Whole text nodes are appended and only the final result is cut, so what
     * comes back is always valid UTF-8 rather than a string ending in half a
     * character.
     */
    private static function textUpTo(\DOMNode $node, int $maxLength): string
    {
        $text = '';
        $stack = [$node];

        while ($stack !== [] && strlen($text) < $maxLength) {
            $current = array_pop($stack);

            if ($current -> nodeType === XML_TEXT_NODE || $current -> nodeType === XML_CDATA_SECTION_NODE) {
                $text .= $current -> nodeValue;
                continue;
            }

            // Pushed last child first, so popping walks the children in
            // document order rather than backwards.
            for ($child = $current -> lastChild; $child !== null; $child = $child -> previousSibling) {
                $stack[] = $child;
            }
        }

        return mb_substr($text, 0, $maxLength);
    }

    /**
     * The page's title/description/keywords, preferring the plain old-school
     * <title>/<meta name="description">/<meta name="keywords"> tags, falling
     * back to Open Graph (og:title, og:description) then Twitter Card
     * (twitter:title, twitter:description) meta tags, then finally to
     * whatever a JSON-LD block declares - each source only fills in what the
     * one before it left empty, rather than overriding it.
     */
    public static function extractMetadata(\DOMDocument $document): array
    {
        $title = $document -> getElementsByTagName('title') -> item(0) ?-> textContent;
        $title = self::firstNonEmpty([$title, self::metaContent($document, 'property', 'og:title'), self::metaContent($document, 'name', 'twitter:title')]);

        $description = self::firstNonEmpty([
            self::metaContent($document, 'name', 'description'),
            self::metaContent($document, 'property', 'og:description'),
            self::metaContent($document, 'name', 'twitter:description'),
        ]);

        $keywords = self::metaContent($document, 'name', 'keywords');

        foreach (self::jsonLDEntries($document) as $entry) {
            $title ??= self::firstNonEmpty([self::stringValue($entry['headline'] ?? null), self::stringValue($entry['name'] ?? null)]);
            $description ??= self::stringValue($entry['description'] ?? null);
            $keywords ??= self::keywordsValue($entry['keywords'] ?? null);
        }

        return ['title' => $title, 'description' => $description, 'keywords' => $keywords];
    }

    /**
     * Strips every <style> and <script> element out of the document - their
     * text content (raw CSS rules, raw JS source) is a real child node as
     * far as the DOM is concerned, so a plain textContent walk would pull
     * all of it in as if it were visible page text. Call this after
     * extractMetadata() (which still needs <script type="application/
     * ld+json"> intact) and right before extractBodyText().
     */
    public static function removeStyleAndScriptTags(\DOMDocument $document): void
    {
        foreach (['style', 'script'] as $tagName) {
            self::removeElements($document -> getElementsByTagName($tagName));
        }
    }

    /**
     * Strips page chrome that's never the actual content: <nav>/<header>/
     * <footer>/<aside> (reliable regardless of class naming, since they're
     * semantic HTML5 landmarks), plus anything classed/id'd like navigation,
     * a header/footer, a sidebar, a cookie banner, an ad slot, a popup/modal,
     * etc. Best run right before extractBodyText() - same as
     * removeStyleAndScriptTags(), this only matters for what fullText ends
     * up containing, not for image/link discovery earlier in the crawl.
     */
    public static function removeBoilerplateElements(\DOMDocument $document): void
    {
        foreach (['nav', 'header', 'footer', 'aside'] as $tagName) {
            self::removeElements($document -> getElementsByTagName($tagName));
        }

        $xpath = new \DOMXPath($document);

        // Excludes <html>/<body> themselves - WordPress (among others) often
        // puts theme/plugin classes directly on <body> (e.g. a real one seen
        // in the wild: "mega-menu-header-nav", matching "nav"/"header"/"menu"
        // all at once), and removing the document's own root/body would wipe
        // out literally everything rather than just chrome around the edges.
        foreach (iterator_to_array($xpath -> query('//*[@class or @id][not(self::html)][not(self::body)]')) as $element) {
            if (self::isBoilerplateElement($element)) {
                $element -> parentNode ?-> removeChild($element);
            }
        }
    }

    private static function isBoilerplateElement(\DOMElement $element): bool
    {
        $class = $element -> getAttribute('class');
        $id = $element -> getAttribute('id');

        // class is a space-separated multi-value list, matched token-exact -
        // that's what protects "page-header"/"section-navigation", both real
        // content, from a bare "header"/"nav".
        $classTokens = preg_split('/\s+/', strtolower($class), -1, PREG_SPLIT_NO_EMPTY);

        foreach (self::BOILERPLATE_CLASS_TOKENS as $token) {
            if (in_array($token, $classTokens, true)) {
                return true;
            }
        }

        if (preg_match(self::AD_WORD_BOUNDARY_PATTERN, $class) === 1) {
            return true;
        }

        // id is a single value, not a list - and unlike class (reused as
        // generic utility-styling hooks, which is exactly what caused the
        // false positives above), a real id is usually one deliberate,
        // site-specific compound ("cern-toolbar", "wp-toolbar"), so a
        // substring check (via the same word-boundary regex, extended to
        // the whole token list) carries much less collision risk here.
        if ($id !== '' && (preg_match(self::AD_WORD_BOUNDARY_PATTERN, $id) === 1 || preg_match(self::idBoundaryPattern(), $id) === 1)) {
            return true;
        }

        return false;
    }

    /**
     * The full BOILERPLATE_CLASS_TOKENS list, as one \b...\b-bounded regex -
     * built once (not per call) and only ever used against id (never
     * class), where the same "utility class reused for real content" risk
     * that ruled out this looser matching for class doesn't apply.
     */
    private static function idBoundaryPattern(): string
    {
        static $pattern = null;

        return $pattern ??= '/\b(?:' . implode('|', array_map(fn (string $token) => preg_quote($token, '/'), self::BOILERPLATE_CLASS_TOKENS)) . ')\b/i';
    }

    /**
     * $elements is a live DOMNodeList - removing a node while iterating it
     * directly would shift indices and skip elements, so it's snapshotted
     * into a plain array first.
     */
    private static function removeElements(\DOMNodeList $elements): void
    {
        foreach (iterator_to_array($elements) as $element) {
            $element -> parentNode ?-> removeChild($element);
        }
    }

    /**
     * All the plain visible text on the page, whitespace normalized - real
     * markup indents text nodes with newlines the browser doesn't render, so
     * a raw textContent read is full of runs of whitespace that would only
     * bloat what actually gets stored/searched. Runs after
     * inlineImageAltText(), so img alt text rides along as part of this too.
     */
    public static function extractBodyText(\DOMDocument $document): string
    {
        $body = $document -> getElementsByTagName('body') -> item(0);

        return self::normalizeWhitespace($body ?-> textContent ?? '');
    }

    /**
     * Collapses any run of whitespace to a single space, except newlines -
     * those are capped at 2 in a row (one blank line, i.e. a paragraph
     * break) rather than collapsed to 1, so real markup indentation gets
     * flattened while an actual paragraph break survives as one. No space
     * is ever left touching a newline (leading/trailing on a paragraph),
     * only the newline itself separates paragraphs.
     *
     * Public because it's the right treatment for any crawled text, not just
     * what came out of a DOM walk - the plain-text and PDF paths in
     * bin/crawler.php produce raw text with the same problem.
     */
    public static function normalizeWhitespace(string $text): string
    {
        $text = str_replace([chr(13) . chr(10), chr(13)], chr(10), $text);
        $text = preg_replace('/[^\S\n]+/', ' ', $text);
        $text = preg_replace('/ *\n */', chr(10), $text);
        $text = preg_replace('/\n{3,}/', chr(10) . chr(10), $text);

        return trim($text);
    }

    private static function metaContent(\DOMDocument $document, string $attribute, string $value): ?string
    {
        foreach ($document -> getElementsByTagName('meta') as $meta) {
            if (strcasecmp($meta -> getAttribute($attribute), $value) === 0) {
                $content = trim($meta -> getAttribute('content'));

                if ($content !== '') {
                    return $content;
                }
            }
        }

        return null;
    }

    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * JSON-LD's "keywords" is sometimes a comma-separated string, sometimes
     * an array of strings - normalized to the same comma-separated string
     * this project's own keywords column expects either way.
     */
    private static function keywordsValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return self::stringValue($value);
        }

        if (is_array($value)) {
            $strings = array_filter($value, 'is_string');

            return $strings !== [] ? implode(', ', $strings) : null;
        }

        return null;
    }

    /**
     * Every JSON-LD block on the page, decoded - a top-level JSON array or an
     * "@graph" wrapper each hold multiple separate entries, flattened here
     * into one flat list either way.
     */
    private static function jsonLDEntries(\DOMDocument $document): array
    {
        $entries = [];

        foreach ($document -> getElementsByTagName('script') as $script) {
            if (strcasecmp($script -> getAttribute('type'), 'application/ld+json') !== 0) {
                continue;
            }

            $decoded = self::decodeJSONLD($script -> textContent);

            if ($decoded === null) {
                continue;
            }

            if (array_is_list($decoded)) {
                array_push($entries, ...array_filter($decoded, 'is_array'));
            } elseif (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                array_push($entries, ...array_filter($decoded['@graph'], 'is_array'));
            } else {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    /**
     * Some sites run JSON-LD through the same HTML-escaping their templates
     * apply to regular page text, which corrupts it - the quotes JSON itself
     * needs end up as literal "&quot;" text instead of real " characters,
     * since <script> content isn't supposed to be HTML-entity-decoded at all
     * (it's raw text per the HTML spec) but got escaped as if it would be.
     * The raw text is tried first so already-valid JSON-LD - the normal case
     * - is never needlessly run through entity decoding at all.
     */
    private static function decodeJSONLD(string $json): ?array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        $decoded = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5), true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }
}
