<?php

declare(strict_types=1);

class Link
{
    // Matches schema.sql's Links.description column, for the same reason Item
    // carries its own limits: link text is a parent node's whole textContent
    // and routinely runs past this. INSERT IGNORE below quietly downgrades
    // strict mode's "Data too long" to a warning and truncates anyway, so
    // without this the cut happens invisibly, at a byte count nothing here
    // chose, and mb_substr is what keeps it from landing mid-character.
    private const MAX_DESCRIPTION_LENGTH = 255;

    // Rows per batched statement, so one page's links can't build a
    // statement with thousands of parameters.
    private const BATCH_CHUNK_SIZE = 200;

    /**
     * Records that $parentId links to $childId, described by $description
     * (e.g. the image's parent-node text). INSERT IGNORE because the same
     * parent page can genuinely link the same child more than once (or get
     * recrawled later) - Links' primary key is (parentId, childId), so a
     * repeat is a no-op rather than an error.
     *
     * A self-link (a page linking to itself - a logo-to-home href, a
     * permalink) is dropped: it's the page endorsing itself, which would
     * inflate its own inbound-link signal in search ranking and focused-crawl
     * priority the way a real link from another page shouldn't.
     */
    public static function create(int $parentId, int $childId, ?string $description): void
    {
        if ($parentId === $childId) {
            return;
        }

        $connection = Database::connection();
        $description = $description !== null ? mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH) : null;

        $insert = mysqli_prepare($connection, '
INSERT IGNORE INTO `Links` (`parentId`, `childId`, `description`)
    VALUES (?, ?, ?)
');
        mysqli_stmt_bind_param($insert, 'iis', $parentId, $childId, $description);
        mysqli_stmt_execute($insert);
    }

    /**
     * Records a whole page's links at once - $links is childId => description.
     * Same INSERT IGNORE semantics as create(), including dropping a page's
     * link to itself, in one statement per chunk instead of one per link.
     */
    public static function createMany(int $parentId, array $links): void
    {
        unset($links[$parentId]);

        if ($links === []) {
            return;
        }

        $connection = Database::connection();

        foreach (array_chunk($links, self::BATCH_CHUNK_SIZE, true) as $chunk) {
            $rows = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $arguments = [];
            $types = '';

            foreach ($chunk as $childId => $description) {
                $arguments[] = $parentId;
                $arguments[] = $childId;
                $arguments[] = $description !== null ? mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH) : null;
                $types .= 'iis';
            }

            $insert = mysqli_prepare($connection, '
INSERT IGNORE INTO `Links` (`parentId`, `childId`, `description`)
    VALUES ' . $rows . '
');
            mysqli_stmt_bind_param($insert, $types, ...$arguments);
            mysqli_stmt_execute($insert);
        }
    }

    /**
     * The URL of a page that links to $childId, or null if nothing does (an
     * original seed URL, or an item only ever found some other way) - used
     * as a real navigation referrer (ChromeConnection, HeadlessBrowser)
     * rather than a guessed one. Any one parent is enough for that; when a
     * child has several, the choice among them doesn't matter, so this just
     * takes whichever the `childId_parentId` index hands back first rather
     * than picking by recency/count.
     */
    public static function findParentURL(int $childId): ?string
    {
        $select = mysqli_prepare(Database::connection(), '
SELECT `Items`.`url`
    FROM `Links`
    JOIN `Items` ON `Items`.`itemId` = `Links`.`parentId`
    WHERE `Links`.`childId` = ?
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 'i', $childId);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

        return $row['url'] ?? null;
    }
}
