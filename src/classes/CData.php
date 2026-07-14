<?php

declare(strict_types=1);

/**
 * A CDATA section - text that rides through literally, unescaped and
 * unparsed as markup, rather than being HTML-entity-escaped like a plain
 * string content item would be. Drop it into any HTMLObject's contents[]
 * array (handled by contentToNode()).
 */
class CData
{
    public function __construct(public readonly string $text)
    {
    }

    public function toNode(): \DOMNode
    {
        $document = HTMLObject::currentDocument();

        // A CDATA section can't contain the literal sequence ']]>' - it would
        // close the section early (and createCDATASection throws on it). When the
        // text contains it, split across adjacent CDATA sections at each
        // occurrence: the ']]' stays with the section before the split, the '>'
        // starts the section after it, so no single section ever holds ']]>' yet
        // the concatenated content reproduces the original text exactly.
        if (!str_contains($this -> text, ']]>')) {
            return $document -> createCDATASection($this -> text);
        }

        $fragment = $document -> createDocumentFragment();
        $segments = explode(']]>', $this -> text);
        $last_index = count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $piece = ($index > 0 ? '>' : '') . $segment . ($index < $last_index ? ']]' : '');
            $fragment -> appendChild($document -> createCDATASection($piece));
        }

        return $fragment;
    }
}
