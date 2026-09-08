<?php

declare(strict_types=1);

/** Prepared before taking database locks and reusable after a deadlock retry. */
final class CrawlContent
{
    public readonly ?string $fullText;
    public readonly ?string $fullHTML;
    public readonly string $contentHash;

    public function __construct(?string $full_text, ?string $full_html)
    {
        $this -> fullText = $full_text !== null ? Text::capRepeatedTerms($full_text) : null;
        $this -> fullHTML = $full_html !== null ? gzencode($full_html, 6) : null;
        $this -> contentHash = sha1((string) $this -> fullText);
    }
}
