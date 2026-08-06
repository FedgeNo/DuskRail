<?php

declare(strict_types=1);

/**
 * One row of the live crawl feed (CrawlFeed) - what a crawler worker just
 * finished, as watch.php draws it.
 */
class CrawlFeedItem
{
    // Longer than a search result's, since this is one row at a time rather
    // than a page of fifty competing for attention.
    private const DESCRIPTION_LENGTH = 300;

    public ?int $itemId = null;
    public ?string $url = null;
    public ?string $type = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?int $crawledTime = null;

    public static function fromRow(array $row): self
    {
        $item = new self();

        $item -> itemId = (int) $row['itemId'];
        $item -> url = $row['url'];
        $item -> type = $row['type'];
        $item -> title = $row['title'];
        $item -> description = $row['description'];
        $item -> crawledTime = (int) $row['crawledTime'];

        return $item;
    }

    public function toJSON(): array
    {
        return [
            'itemId' => $this -> itemId,
            'url' => $this -> url,
            'type' => $this -> type,
            'title' => $this -> title,
            'description' => Text::truncate($this -> description, self::DESCRIPTION_LENGTH),
            'crawledTime' => $this -> crawledTime,
            'thumbnailURL' => ImageLoader::thumbnailURL($this -> itemId, $this -> type),
        ];
    }
}
