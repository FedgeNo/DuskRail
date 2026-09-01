<?php

declare(strict_types=1);

/**
 * Text handling shared by everything that puts crawled prose in front of a
 * reader.
 */
class Text
{
    /**
     * How many times one word may appear in a page's indexed text.
     *
     * MariaDB's natural-language relevance is exactly linear in term
     * frequency: a word repeated 500 times scores 500x what one occurrence
     * scores, with no saturation and no document-length normalization
     * anywhere in it. Unbounded, that makes the highest-ranking page for any
     * query whichever one repeats it most, and the cheapest way to own a
     * term a block of it in markup nobody reads.
     *
     * Ordering can't fix it: relevance is a sort key, and every monotonic
     * transform of a sort key (dividing it down, saturating it) leaves the
     * order identical. Bounding the count is the only thing that bounds the
     * payoff.
     *
     * Set where an honest page can still reach it - a thorough article about
     * widgets will say "widgets" a few dozen times - so the cap costs real
     * pages nothing while it stops repetition from buying anything a
     * well-written page couldn't also have.
     */
    private const MAX_TERM_OCCURRENCES = 50;

    // Below MariaDB's own minimum indexed token length (innodb_ft_min_token
    // _size, 3 by default), a word never reaches the index at all, so
    // capping it would only mangle the text for no ranking effect.
    private const MIN_INDEXED_TERM_LENGTH = 3;

    /**
     * InnoDB's default FULLTEXT stopword list, verbatim
     * (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD). These are never
     * indexed, so capping them buys no ranking protection at all - and would
     * cost real damage: "the" passes fifty occurrences in any few thousand
     * words of ordinary English, so capping it would quietly strip the word
     * out of the back half of every long article this crawler stores, and
     * out of the snippets cut from them.
     */
    private const STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en',
        'for', 'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or',
        'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www',
    ];

    /**
     * $text with any word past its MAX_TERM_OCCURRENCES-th appearance
     * removed. Earlier occurrences are the ones kept, so a page's real prose
     * (which comes before the stuffed block often enough) survives intact,
     * along with the first match a snippet gets cut around.
     *
     * Applied to what gets stored, not to a separate index-only copy: keeping
     * both would double the storage of the largest columns in the database to
     * preserve text whose only distinguishing feature is that it repeats. The
     * original markup is still in fullHTML either way, so
     * bin/reextract-text.php can always rebuild this.
     */
    public static function capRepeatedTerms(string $text): string
    {
        $counts = [];
        $lines = explode(chr(10), $text);

        foreach ($lines as $lineNumber => $line) {
            $kept = [];

            foreach (explode(' ', $line) as $word) {
                if (self::isWithinCap($word, $counts)) {
                    $kept[] = $word;
                }
            }

            $lines[$lineNumber] = implode(' ', $kept);
        }

        return implode(chr(10), $lines);
    }

    /**
     * Counts every indexable term inside one whitespace-separated word, and
     * says whether the word may be kept.
     *
     * A "word" here is not a term. MariaDB splits tokens on every
     * non-alphanumeric character, so "widgets-widgets-widgets…" is one word
     * to a whitespace split and four hundred separate indexed occurrences of
     * "widgets" to the index. Only counting the way the index tokenizes
     * bounds anything; splitting on whitespace alone leaves punctuation as a
     * way straight past the cap.
     */
    private static function isWithinCap(string $word, array &$counts): bool
    {
        $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($word), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $withinCap = true;

        foreach ($terms as $term) {
            if (mb_strlen($term) < self::MIN_INDEXED_TERM_LENGTH || in_array($term, self::STOPWORDS, true)) {
                continue;
            }

            $counts[$term] = ($counts[$term] ?? 0) + 1;

            // Every term is counted before returning, never short-circuited -
            // a word carrying one over-cap term still tells us the truth
            // about the others it contains.
            if ($counts[$term] > self::MAX_TERM_OCCURRENCES) {
                $withinCap = false;
            }
        }

        return $withinCap;
    }

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
