<?php

declare(strict_types=1);

/**
 * One row of a SearchResults page - the columns the result list actually
 * renders, plus the ranking signals it was ordered by.
 */
class SearchResult
{
    // How much of a description a result card shows. Long enough to judge a
    // result by, short enough that fifty of them stay scannable.
    private const DESCRIPTION_LENGTH = 500;

    // A snippet cut this far into the document didn't start at the top, so
    // it opens with an ellipsis. Matches SearchResults' cut-before window -
    // any match position beyond it means text was skipped.
    private const SNIPPET_LEAD_IN = 151;

    private const SNIPPET_DISPLAY_LENGTH = 380;

    public ?int $itemId = null;
    public ?string $url = null;
    public ?string $type = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?float $relevance = null;
    public ?int $linkMatches = null;
    public ?int $inc = null;
    public ?int $matchPosition = null;
    public ?string $snippet = null;

    public static function fromRow(array $row): self
    {
        $result = new self();

        $result -> itemId = (int) $row['itemId'];
        $result -> url = $row['url'];
        $result -> type = $row['type'];
        $result -> title = $row['title'];
        $result -> description = $row['description'];
        $result -> relevance = (float) $row['relevance'];
        $result -> linkMatches = (int) $row['linkMatches'];
        $result -> inc = (int) $row['inc'];
        $result -> matchPosition = $row['matchPosition'] !== null ? (int) $row['matchPosition'] : null;
        $result -> snippet = $row['snippet'];

        return $result;
    }

    public function toJSON(): array
    {
        return [
            'itemId' => $this -> itemId,
            'url' => $this -> url,
            'type' => $this -> type,
            'title' => $this -> title,
            'description' => $this -> displayText(),
            'thumbnailURL' => ImageLoader::thumbnailURL($this -> itemId, $this -> type),
        ];
    }

    /**
     * What the result card shows under the title: the snippet cut around
     * where the query actually matched in the body text, when there is one -
     * the page's own description otherwise. A description tells you what the
     * page says about itself; the snippet tells you why it matched, which is
     * the more useful thing once there's a match to show.
     */
    private function displayText(): ?string
    {
        if ($this -> snippet === null) {
            return Text::truncate($this -> description, self::DESCRIPTION_LENGTH);
        }

        $snippet = $this -> snippet;
        $leadingEllipsis = '';

        // Cut mid-document: drop the partial word the cut landed in and mark
        // the elision, so the snippet reads as a quote from the middle rather
        // than a sentence that starts with half a word.
        if ($this -> matchPosition !== null && $this -> matchPosition > self::SNIPPET_LEAD_IN) {
            $firstSpace = mb_strpos($snippet, ' ');

            if ($firstSpace !== false && $firstSpace < 30) {
                $snippet = mb_substr($snippet, $firstSpace + 1);
            }

            $leadingEllipsis = '…';
        }

        return $leadingEllipsis . Text::truncate($snippet, self::SNIPPET_DISPLAY_LENGTH);
    }
}
