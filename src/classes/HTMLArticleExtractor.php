<?php

declare(strict_types=1);

class HTMLArticleExtractor extends HTMLLoader {
    private const NON_ARTICLE_TAGS = ['nav', 'aside', 'footer', 'canvas', 'object', 'embed', 'audio', 'video'];
    private const NON_ARTICLE_ROLES = ['navigation', 'complementary', 'contentinfo', 'banner', 'search', 'menu', 'menubar', 'toolbar'];

    public static function extract(string $html, ?string $charset = null): string {
        $document = parent::load($html, $charset);
        parent::removeNonContentElements($document);

        foreach (self::NON_ARTICLE_TAGS as $tag_name) {
            foreach (iterator_to_array($document -> getElementsByTagName($tag_name)) as $element) {
                $element -> parentNode ?-> removeChild($element);
            }
        }

        $xpath = new \DOMXPath($document);

        // Article headers can contain the article's title and introduction.
        foreach (iterator_to_array($xpath -> query('//header[not(ancestor::article or ancestor::main)]')) as $element) {
            $element -> parentNode ?-> removeChild($element);
        }

        foreach (iterator_to_array($xpath -> query('//*[@role][not(self::html or self::body)]')) as $element) {
            $roles = preg_split('/\s+/', strtolower(trim($element -> getAttribute('role'))), -1, PREG_SPLIT_NO_EMPTY);

            if (array_intersect(self::NON_ARTICLE_ROLES, $roles) !== []) {
                $element -> parentNode ?-> removeChild($element);
            }
        }

        parent::separateBlockElements($document);

        return parent::extractBodyText($document);
    }
}
