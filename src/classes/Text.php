<?php

declare(strict_types=1);

/**
 * Text handling shared by everything that puts crawled prose in front of a
 * reader.
 */
class Text
{
    /**
     * Cuts $text to $maxLength characters, marking the cut with an ellipsis
     * so a truncated description doesn't read as a sentence the page actually
     * ended that way. The break is moved back to the last whitespace when
     * there's one reasonably close, since stopping mid-word looks like a bug
     * rather than an abbreviation.
     *
     * mb_substr, not substr - crawled text is utf8mb4 and cutting a
     * multi-byte character in half produces a broken sequence.
     */
    public static function truncate(?string $text, int $maxLength): ?string
    {
        if ($text === null || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = rtrim(mb_substr($text, 0, $maxLength));
        $lastSpace = mb_strrpos($cut, ' ');

        // Only honoured when the last word boundary is near the end - a long
        // unbroken run (a URL, a hash) would otherwise throw away most of
        // what was going to be shown just to avoid splitting it.
        if ($lastSpace !== false && $lastSpace > (int) ($maxLength * 0.8)) {
            $cut = rtrim(mb_substr($cut, 0, $lastSpace));
        }

        return $cut . '…';
    }
}
