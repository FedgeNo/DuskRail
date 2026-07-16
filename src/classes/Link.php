<?php

declare(strict_types=1);

class Link
{
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

        $insert = mysqli_prepare($connection, '
INSERT IGNORE INTO `Links` (`parentId`, `childId`, `description`)
    VALUES (?, ?, ?)
');
        mysqli_stmt_bind_param($insert, 'iis', $parentId, $childId, $description);
        mysqli_stmt_execute($insert);
    }
}
