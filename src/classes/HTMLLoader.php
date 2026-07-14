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
                return $pageURL -> resolve(new URL($href));
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
     */
    public static function extractImageLinks(\DOMDocument $document, URL $baseURL): array
    {
        $images = [];

        foreach ($document -> getElementsByTagName('img') as $img) {
            $src = trim($img -> getAttribute('src'));

            if ($src === '') {
                continue;
            }

            $parent = $img -> parentNode;
            $description = $parent !== null ? trim($parent -> textContent) : '';

            $images[] = [
                'url' => $baseURL -> resolve(new URL($src)),
                'description' => $description,
            ];
        }

        return $images;
    }
}
