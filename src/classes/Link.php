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
     */
    public static function create(int $parentId, int $childId, ?string $description): void
    {
        $connection = Database::connection();

        $insert = mysqli_prepare($connection, '
INSERT IGNORE INTO `Links` (`parentId`, `childId`, `description`)
    VALUES (?, ?, ?)
');
        mysqli_stmt_bind_param($insert, 'iis', $parentId, $childId, $description);
        mysqli_stmt_execute($insert);
    }
}
