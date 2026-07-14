<?php

declare(strict_types=1);

class Item
{
    public ?int $itemId = null;
    public ?string $url = null;
    public ?string $type = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $keywords = null;
    public ?string $fullText = null;
    public ?string $fullHTML = null;
    public ?int $crawledTime = null;

    public static function fromRow(array $row): self
    {
        $item = new self();

        $item -> itemId = (int) $row['itemId'];
        $item -> url = $row['url'];
        $item -> type = $row['type'];
        $item -> title = $row['title'];
        $item -> description = $row['description'];
        $item -> keywords = $row['keywords'];
        $item -> fullText = $row['fullText'];
        $item -> fullHTML = $row['fullHTML'];
        $item -> crawledTime = $row['crawledTime'] !== null ? (int) $row['crawledTime'] : null;

        return $item;
    }

    /**
     * The next item for the crawler to fetch. Ordering by crawledTime alone
     * (rather than filtering WHERE crawledTime IS NULL) means never-crawled
     * rows - NULL sorts first in MariaDB - come before anything already
     * crawled, and once everything's been crawled at least once this starts
     * naturally cycling back through the oldest recrawls first.
     */
    public static function nextToCrawl(): ?self
    {
        $connection = Database::connection();
        $result = mysqli_query($connection, '
SELECT *
    FROM `Items`
    ORDER BY `crawledTime`
    LIMIT 1
');

        $row = $result !== false ? mysqli_fetch_assoc($result) : null;

        return $row !== null ? self::fromRow($row) : null;
    }
}
