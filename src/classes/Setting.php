<?php

declare(strict_types=1);

/**
 * A named value the web side sets and the crawler reads, or the other way
 * round - currently just the focused-crawl topic.
 *
 * A database row rather than a file in the project directory. A web request
 * and a CLI process that starts up later do share a channel - the database,
 * which both already hold a connection to. A file would only be written by
 * one Unix user and read by another, which is what forces the web server to
 * have write access somewhere inside the project, and that in turn is what
 * makes an install depend on ACLs and SELinux labels lining up on exactly the
 * right paths. A row costs none of that.
 */
class Setting
{
    private const MAX_NAME_LENGTH = 64;

    public static function value(string $name): ?string
    {
        $select = mysqli_prepare(Database::connection(), '
SELECT `value`
    FROM `Settings`
    WHERE `name` = ?
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 's', $name);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

        return $row['value'] ?? null;
    }

    public static function store(string $name, string $value): void
    {
        $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);

        $insert = mysqli_prepare(Database::connection(), '
INSERT INTO `Settings` (`name`, `value`)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)
');
        mysqli_stmt_bind_param($insert, 'ss', $name, $value);
        mysqli_stmt_execute($insert);
    }
}
