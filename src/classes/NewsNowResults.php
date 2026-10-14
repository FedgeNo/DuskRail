<?php

declare(strict_types=1);

final class NewsNowResults {
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
        foreach ($xpath -> query('//article[contains(concat(" ", normalize-space(@class), " "), " article-card ")]') as $card) {
            if ($xpath -> query('ancestor-or-self::*[@hidden or @aria-hidden="true"]', $card) -> length > 0) continue;
            $section = $xpath -> query('ancestor::section[1]', $card) -> item(0);
            if ($section !== null) {
                $heading = $xpath -> query('.//h2/a', $section) -> item(0);
                if ($heading instanceof DOMElement && !str_contains($heading -> getAttribute('href'), 'searchheadlines=1')) continue;
            }
            $anchor = $xpath -> query('.//a[contains(concat(" ", normalize-space(@class), " "), " article-card__headline ")]', $card) -> item(0);
            if (!$anchor instanceof DOMElement) continue;
            $url = $anchor -> getAttribute('href');
            if (!preg_match('~^https://c\.newsnow\.com/A/[0-9]+(?:[?][^\s]*)?$~D', $url) || isset($seen[$url])) continue;
            $title = self::text($anchor);
            if ($title === '') continue;
            $seen[$url] = true;
            $publisher = self::text($xpath -> query('.//*[contains(concat(" ", normalize-space(@class), " "), " article-publisher__name ")]', $card) -> item(0));
            $time = $xpath -> query('.//*[@data-timestamp]', $card) -> item(0);
            $timestamp = $time instanceof DOMElement ? $time -> getAttribute('data-timestamp') : '';
            $results[] = ['title' => $title, 'url' => $url, 'content' => trim($publisher . ' ' . self::text($time)),
                'position' => count($results) + 1, 'engine' => 'newsnow', 'publisher' => $publisher,
                'publishedDate' => ctype_digit($timestamp) ? gmdate('c', (int) $timestamp) : ''];
            if (count($results) >= $limit) break;
        }
        if ($results !== []) return ['status' => 'ok', 'results' => $results, 'error' => null];
        $title = self::text($xpath -> query('//title') -> item(0));
        if (preg_match('/just a moment|access denied|captcha|too many requests/i', $title)) return ['status' => 'blocked', 'results' => [], 'error' => 'NewsNow returned a blocking page: ' . $title];
        if (str_starts_with($title, 'NewsNow:') && str_contains($title, ' headlines |')) {
            foreach ($xpath -> query('//*[starts-with(normalize-space(.), "No articles were found matching ")]') as $notice) {
                if (preg_match('/^No articles were found matching .+ in the headline\. Please try searching again\.$/u', self::text($notice))) {
                    return ['status' => 'no_results', 'results' => [], 'error' => null];
                }
            }
        }
        return ['status' => 'parse_error', 'results' => [], 'error' => 'NewsNow returned no recognizable matching headlines; the page may be empty or its markup may have changed.'];
    }

    private static function text(?DOMNode $node): string {
        return trim(preg_replace('/\s+/u', ' ', $node ?-> textContent ?? ''));
    }
}
