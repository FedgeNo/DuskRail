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
    public ?int $inc = null;

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
        $item -> inc = (int) $row['inc'];

        return $item;
    }

    /**
     * The next item for the crawler to fetch. Ordering by crawledTime alone
     * (rather than filtering WHERE crawledTime IS NULL) means never-crawled
     * rows - NULL sorts first in MariaDB - come before anything already
     * crawled, and once everything's been crawled at least once this starts
     * naturally cycling back through the oldest recrawls first.
     *
     * $topic turns this into a focused crawl: among not-yet-crawled items,
     * the one whose *discovery context* - the Links.description text of
     * whichever page(s) linked to it - best matches $topic via FULLTEXT
     * MATCH/AGAINST goes first, rather than whatever happened to be
     * discovered earliest. An item can be linked from several parents with
     * different link text, so this takes the best-scoring one (MAX), not an
     * average - one strongly on-topic mention should outweigh several
     * unrelated ones. Falls back to the default order for recrawls
     * (everything already crawled once) or when nothing is left unqueued.
     */
    public static function nextToCrawl(?string $topic = null): ?self
    {
        $connection = Database::connection();

        if ($topic !== null && trim($topic) !== '') {
            $focused = mysqli_prepare($connection, '
SELECT `Items`.*
    FROM `Items`
    LEFT JOIN `Links` ON `Links`.`childId` = `Items`.`itemId`
    WHERE `Items`.`crawledTime` IS NULL
    GROUP BY `Items`.`itemId`
    ORDER BY MAX(MATCH(`Links`.`description`) AGAINST (?)) DESC, `Items`.`itemId` ASC
    LIMIT 1
');
            mysqli_stmt_bind_param($focused, 's', $topic);
            mysqli_stmt_execute($focused);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($focused));

            if ($row !== null) {
                return self::fromRow($row);
            }
        }

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
     * creates one if this is the first time this URL has been seen - as a
     * single INSERT ... ON DUPLICATE KEY UPDATE against the url unique key,
     * rather than a SELECT to check followed by a separate INSERT. The
     * UPDATE bumps inc (a reference count - how many times this URL has been
     * seen/linked) rather than crawledTime: crawledTime means "actually
     * fetched", and being merely rediscovered as a link isn't that - touching
     * it here would make a frequently-linked-but-never-fetched item look
     * freshly crawled and starve it out of nextToCrawl()'s queue. inc always
     * changes (it's a genuine +1 every time, never a coincidental repeat), so
     * it also keeps this a real write rather than a no-op - which is what
     * makes mysqli_insert_id() return the existing row's itemId at all.
     */
    public static function findOrCreateByURL(URL $url, string $type, ?string $title = null, ?string $description = null): self
    {
        $connection = Database::connection();
        $urlString = $url -> toString();

        $insert = mysqli_prepare($connection, '
INSERT INTO `Items` (`url`, `type`, `title`, `description`)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE `inc` = `inc` + 1
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

    /**
     * Records that this item has actually been fetched and processed - the
     * real Content-Type (replacing whatever placeholder guess got it
     * created, e.g. "image" or "unknown"), the extracted title/description/
     * keywords/fullText/fullHTML, and crawledTime stamped to now. This is
     * always the last step of crawling something: crawledTime is what
     * nextToCrawl() orders by, so this is what actually moves the queue
     * forward past this item.
     */
    public function markCrawled(string $type, ?string $title, ?string $description, ?string $keywords, ?string $fullText, ?string $fullHTML): void
    {
        $connection = Database::connection();
        $now = time();

        $update = mysqli_prepare($connection, '
UPDATE `Items`
    SET `type` = ?, `title` = ?, `description` = ?, `keywords` = ?, `fullText` = ?, `fullHTML` = ?, `crawledTime` = ?
    WHERE `itemId` = ?
');
        mysqli_stmt_bind_param($update, 'ssssssii', $type, $title, $description, $keywords, $fullText, $fullHTML, $now, $this -> itemId);
        mysqli_stmt_execute($update);

        $this -> type = $type;
        $this -> title = $title;
        $this -> description = $description;
        $this -> keywords = $keywords;
        $this -> fullText = $fullText;
        $this -> fullHTML = $fullHTML;
        $this -> crawledTime = $now;
    }

    /**
     * Removes this item outright - e.g. a redirect the crawler gave up on
     * (too many hops, a broken Location) rather than leaving a permanently
     * un-crawlable row sitting at crawledTime NULL, which nextToCrawl() would
     * otherwise keep handing back forever on every single run.
     */
    public function delete(): void
    {
        $delete = mysqli_prepare(Database::connection(), '
DELETE FROM `Items`
    WHERE `itemId` = ?
');
        mysqli_stmt_bind_param($delete, 'i', $this -> itemId);
        mysqli_stmt_execute($delete);
    }

    /**
     * Repoints this item at a redirect target - the URL this item was
     * fetched under was wrong (a 301/302/etc pointed somewhere else), and
     * $newURL is what it should actually be. url is UNIQUE, so if some other
     * item already owns that URL (found separately, e.g. via a link), the
     * UPDATE fails with a duplicate-key error rather than succeeding: this
     * row never had any content of its own (it's just the old address), so
     * it's deleted and the caller gets back the item that already exists at
     * $newURL instead - the two would otherwise represent the same resource
     * under two different itemIds forever.
     */
    public function redirectTo(URL $newURL): self
    {
        $connection = Database::connection();
        $newURLString = $newURL -> toString();

        try {
            $update = mysqli_prepare($connection, '
UPDATE `Items`
    SET `url` = ?
    WHERE `itemId` = ?
');
            mysqli_stmt_bind_param($update, 'si', $newURLString, $this -> itemId);
            mysqli_stmt_execute($update);

            $this -> url = $newURLString;

            return $this;
        } catch (\mysqli_sql_exception $exception) {
            $this -> delete();

            $select = mysqli_prepare($connection, '
SELECT *
    FROM `Items`
    WHERE `url` = ?
    LIMIT 1
');
            mysqli_stmt_bind_param($select, 's', $newURLString);
            mysqli_stmt_execute($select);

            return self::fromRow(mysqli_fetch_assoc(mysqli_stmt_get_result($select)));
        }
    }
}
