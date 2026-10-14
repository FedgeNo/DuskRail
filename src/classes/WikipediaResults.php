<?php

declare(strict_types=1);

final class WikipediaResults {
    public static function parse(mixed $data, int $limit = 10): array {
        if (!is_array($data) || !is_array($data['pages'] ?? null) || !array_is_list($data['pages'])) throw new RuntimeException('Wikipedia API returned an invalid page list.');
        $results = [];
        $seen = [];
        foreach ($data['pages'] as $page) {
            if (!is_array($page) || !is_int($page['id'] ?? null) || $page['id'] < 1
                || !is_string($page['key'] ?? null) || $page['key'] === ''
                || !is_string($page['title'] ?? null) || $page['title'] === ''
                || !is_string($page['excerpt'] ?? null)) throw new RuntimeException('Wikipedia API returned a malformed search result.');
            if (isset($seen[$page['id']])) continue;
            $seen[$page['id']] = true;
            $results[] = ['engine' => 'wikipedia', 'title' => $page['title'],
                'url' => 'https://en.wikipedia.org/wiki/' . rawurlencode($page['key']),
                'content' => trim(html_entity_decode(strip_tags($page['excerpt']), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'position' => count($results) + 1,
                'metadata' => json_encode(['pageId' => $page['id'], 'pageKey' => $page['key'], 'language' => 'en',
                    'description' => is_string($page['description'] ?? null) ? $page['description'] : null,
                    'matchedTitle' => is_string($page['matched_title'] ?? null) ? $page['matched_title'] : null], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
            if (count($results) >= $limit) break;
        }
        return ['status' => $results === [] ? 'no_results' : 'ok', 'results' => $results, 'error' => null];
    }
}
