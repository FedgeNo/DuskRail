<?php

declare(strict_types=1);

/**
 * Parses an HTML document's bytes into a DOMDocument, given the charset the
 * server declared (Content-Type's charset param). This is the bare version -
 * no <meta charset> sniffing when the header didn't declare one, no BOM
 * detection, no base-tag handling. Those all matter for a real crawl and are
 * coming later; for now this just needs to not mangle the common case.
 */
class HTMLLoader
{
    // Substring-matched (not exact) against class/id, so this also catches
    // the BEM/compound-hyphenated variants real sites actually use -
    // "site-header", "main-nav", "cookie-banner", "footer-widgets" - not
    // just a bare "nav"/"header"/"footer". Each entry already covers its own
    // compounds too ("nav" alone matches "navigation"/"navbar"/"subnav").
    private const BOILERPLATE_CLASS_SUBSTRINGS = [
        'nav', 'header', 'footer', 'sidebar', 'menu', 'breadcrumb',
        'cookie', 'banner', 'advert', 'popup', 'modal', 'social-share',
        // "ad"/"ads" alone would also match "header", "already", "load",
        // "read", "gradient", "loads", "roads", ... on nearly every page -
        // hyphen-bounded instead, since real ad-slot classes are almost
        // always kebab-case ("ad-banner", "google-ads", "ads-container").
        // "advertisement" is already covered by "advert" above.
        'ad-', '-ad', 'ads-', '-ads',
    ];

    // The one case the hyphen-bounded patterns above can't catch: a class
    // that's *only* "ad" or "ads" with no hyphen at all. Matched as a whole
    // class token here instead of a substring, for the same reason.
    private const BOILERPLATE_EXACT_CLASS_TOKENS = ['ad', 'ads'];
    public static function load(string $html, ?string $charset): \DOMDocument
    {
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
        // The prepended XML prolog forces libxml to trust the UTF-8 the string
        // was just normalized to, instead of re-guessing the encoding from the
        // raw bytes (which defaults to ISO-8859-1 for anything without its own
        // <meta charset> or XML declaration, mangling multi-byte content).
        $document -> loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

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

        foreach ($document -> getElementsByTagName('img') as $img) {
            $src = trim($img -> getAttribute('src'));

            if ($src === '') {
                continue;
            }

            $url = $baseURL -> resolve(new URL($src));

            if (!$url -> isValid()) {
                continue;
            }

            $parent = $img -> parentNode;
            $description = $parent !== null ? trim($parent -> textContent) : '';

            $images[] = [
                'url' => $url,
                'description' => $description,
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

        foreach ($document -> getElementsByTagName('a') as $anchor) {
            $href = trim($anchor -> getAttribute('href'));

            if ($href === '') {
                continue;
            }

            $url = $baseURL -> resolve(new URL($href));

            if (!$url -> isValid()) {
                continue;
            }

            $parent = $anchor -> parentNode;
            $description = $parent !== null ? trim($parent -> textContent) : '';

            $links[] = [
                'url' => $url,
                'description' => $description,
            ];
        }

        return $links;
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
        $title = $document -> getElementsByTagName('title') -> item(0)?->textContent;
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

        foreach (iterator_to_array($xpath -> query('//*[@class or @id]')) as $element) {
            if (self::isBoilerplateElement($element)) {
                $element -> parentNode?->removeChild($element);
            }
        }
    }

    private static function isBoilerplateElement(\DOMElement $element): bool
    {
        $class = strtolower($element -> getAttribute('class'));
        $id = strtolower($element -> getAttribute('id'));

        foreach (self::BOILERPLATE_CLASS_SUBSTRINGS as $needle) {
            if (str_contains($class, $needle) || str_contains($id, $needle)) {
                return true;
            }
        }

        $classTokens = preg_split('/\s+/', $class, -1, PREG_SPLIT_NO_EMPTY);

        foreach (self::BOILERPLATE_EXACT_CLASS_TOKENS as $token) {
            if (in_array($token, $classTokens, true) || $id === $token) {
                return true;
            }
        }

        return false;
    }

    /**
     * $elements is a live DOMNodeList - removing a node while iterating it
     * directly would shift indices and skip elements, so it's snapshotted
     * into a plain array first.
     */
    private static function removeElements(\DOMNodeList $elements): void
    {
        foreach (iterator_to_array($elements) as $element) {
            $element -> parentNode?->removeChild($element);
        }
    }

    /**
     * All the plain visible text on the page, whitespace collapsed - real
     * markup indents text nodes with newlines the browser doesn't render, so
     * a raw textContent read is full of runs of whitespace that would only
     * bloat what actually gets stored/searched. Runs after
     * inlineImageAltText(), so img alt text rides along as part of this too.
     */
    public static function extractBodyText(\DOMDocument $document): string
    {
        $body = $document -> getElementsByTagName('body') -> item(0);

        return trim(preg_replace('/\s+/', ' ', $body?->textContent ?? ''));
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
