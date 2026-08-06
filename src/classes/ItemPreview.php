<?php

declare(strict_types=1);

/**
 * Everything the image preview panel shows for one item: its own columns,
 * plus the URL of a page it was found on.
 *
 * Deliberately not an Item. Item is the whole row, fullText and fullHTML
 * included, which for a crawled page is routinely megabytes - none of it
 * rendered here, and all of it read out of the database and thrown away on
 * every click if this used Item::findById().
 */
class ItemPreview
{
    public ?int $itemId = null;
    public ?string $url = null;
    public ?string $type = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $parentURL = null;

    /**
     * The preview for a crawled item, or null if there's no such item or it
     * hasn't been crawled - an uncrawled row is a queue entry, not something
     * with content to show.
     */
    public static function findById(int $itemId): ?self
    {
        // noindex items are excluded here for the same reason search excludes
        // them: the page opted out of being served, and "only reachable by
        // guessing itemIds" isn't excluded.
        $select = mysqli_prepare(Database::connection(), '
SELECT `itemId`, `url`, `type`, `title`, `description`
    FROM `Items`
    WHERE `itemId` = ?
        AND `crawledTime` IS NOT NULL
        AND `noindex` = 0
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 'i', $itemId);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

        if ($row === null) {
            return null;
        }

        $preview = new self();

        $preview -> itemId = (int) $row['itemId'];
        $preview -> url = $row['url'];
        $preview -> type = $row['type'];
        $preview -> title = $row['title'];
        $preview -> description = $row['description'];
        $preview -> parentURL = Link::findParentURL($preview -> itemId);

        return $preview;
    }

    public function toJSON(): array
    {
        return [
            'itemId' => $this -> itemId,
            'url' => $this -> url,
            'type' => $this -> type,
            'title' => $this -> title,
            'description' => $this -> description,
            'parentURL' => $this -> parentURL,
            'thumbnailURL' => ImageLoader::thumbnailURL($this -> itemId, $this -> type),
        ];
    }
}
