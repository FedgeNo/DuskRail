<?php

declare(strict_types=1);

final class MarginaliaResults {
    public static function parse(string $html, int $limit = 10): array {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document -> loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $results = [];
        $seen = [];
        foreach ($xpath -> query('//main//h2/a[@href]') as $anchor) {
            if ($xpath -> query('ancestor::*[@hidden or @aria-hidden="true"]', $anchor) -> length > 0) continue;
            $url = self::resultURL($anchor -> getAttribute('href'));
            $title = self::text($anchor);
            if ($url === null || $title === '' || isset($seen[$url])) continue;
            $seen[$url] = true;
            $card = $xpath -> query('ancestor::div[.//p][1]', $anchor) -> item(0);
            $snippet = null;
            if ($card !== null && $xpath -> query('.//h2/a', $card) -> length === 1) {
                $snippet = $xpath -> query('.//p', $card) -> item(0);
            }
            $results[] = ['title' => $title, 'url' => $url, 'content' => self::text($snippet), 'position' => count($results) + 1, 'engine' => 'marginalia'];
            if (count($results) >= $limit) break;
        }
        if ($results !== []) return ['status' => 'ok', 'results' => $results, 'error' => null];
        $title = self::text($xpath -> query('//title') -> item(0));
        if (preg_match('/captcha|access denied|too many requests|rate limit|forbidden/i', $title)) {
            return ['status' => 'blocked', 'results' => [], 'error' => 'Marginalia returned a blocking page: ' . $title];
        }
        return ['status' => 'parse_error', 'results' => [], 'error' => 'Marginalia returned no recognizable organic results. The page may be empty, blocked or have changed markup.'];
    }

    public static function resultURL(string $href): ?string {
        if (str_starts_with($href, '//')) $href = 'https:' . $href;
        $parts = parse_url($href);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return null;
        $host = strtolower($parts['host'] ?? '');
        if ($host === 'marginalia-search.com' || str_ends_with($host, '.marginalia-search.com')) return null;
        $url = new URL($href);
        return $url -> isValid() ? $url -> toString() : null;
    }

    private static function text(?DOMNode $node): string {
        return trim(preg_replace('/\s+/u', ' ', $node ?-> textContent ?? ''));
    }
}
