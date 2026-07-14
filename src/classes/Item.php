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

    /**
     * Finds the existing Item for a URL (the same image/page is routinely
     * linked from many different pages - it should be one row with many
     * Links rows pointing at it, not a duplicate per page it's found on) or
     * creates one if this is the first time this URL has been seen. $type is
     * only a starting guess (e.g. "image" for something found via <img>,
     * before it's actually been fetched and its real Content-Type known) -
     * crawling it later overwrites it with the real value.
     */
    public static function findOrCreateByURL(URL $url, string $type, ?string $title = null, ?string $description = null): self
    {
        $connection = Database::connection();
        $urlString = $url -> toString();

        $select = mysqli_prepare($connection, 'SELECT * FROM `Items` WHERE `url` = ? LIMIT 1');
        mysqli_stmt_bind_param($select, 's', $urlString);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

        if ($row !== null) {
            return self::fromRow($row);
        }

        $insert = mysqli_prepare($connection, '
INSERT INTO `Items` (`url`, `type`, `title`, `description`)
    VALUES (?, ?, ?, ?)
');
        mysqli_stmt_bind_param($insert, 'ssss', $urlString, $type, $title, $description);
        mysqli_stmt_execute($insert);

        $item = new self();
        $item -> itemId = (int) mysqli_insert_id($connection);
        $item -> url = $urlString;
        $item -> type = $type;
        $item -> title = $title;
        $item -> description = $description;

        return $item;
    }
}
