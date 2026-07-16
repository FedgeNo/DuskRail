<?php

declare(strict_types=1);

class Item
{
    // Matches schema.sql's column definitions - kept here so every write
    // path truncates to the same limits instead of finding out about them
    // one "Data too long" mysqli_sql_exception at a time. fullText/fullHTML
    // are LONGTEXT (4GB) - no realistic page gets remotely close, so they're
    // not guarded here.
    private const MAX_URL_LENGTH = 767;
    private const MAX_TYPE_LENGTH = 50;
    private const MAX_TITLE_LENGTH = 255;
    private const MAX_KEYWORDS_LENGTH = 255;
    // description is TEXT (65,535 bytes) - utf8mb4's worst case is 4
    // bytes/char, so this is a safe character-count ceiling with headroom.
    private const MAX_DESCRIPTION_LENGTH = 16000;

    // How long a claim on an item lasts before it's considered abandoned and
    // becomes pickable again - comfortably longer than
    // bin/crawler-manager.php's hang-kill timeout, so a worker that gets
    // SIGKILLed mid-crawl frees its item up again on its own rather than
    // leaving it stuck until some other explicit cleanup.
    private const CLAIM_WINDOW_SECONDS = 120;

    // How many times nextToCrawl() will retry after losing a claim race to
    // another concurrent worker before giving up and returning null - under
    // normal contention (a handful of workers) losing more than a couple of
    // races in a row would be exceptional, not routine.
    private const MAX_CLAIM_ATTEMPTS = 10;

    public ?int $itemId = null;
    public ?string $url = null;
    public ?int $hostId = null;
    public ?string $type = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $keywords = null;
    public ?string $fullText = null;
    public ?string $fullHTML = null;
    public ?int $crawledTime = null;
    public ?int $claimedUntil = null;
    public ?int $inc = null;

    public static function fromRow(array $row): self
    {
        $item = new self();

        $item -> itemId = (int) $row['itemId'];
        $item -> url = $row['url'];
        $item -> hostId = (int) $row['hostId'];
        $item -> type = $row['type'];
        $item -> title = $row['title'];
        $item -> description = $row['description'];
        $item -> keywords = $row['keywords'];
        $item -> fullText = $row['fullText'];
        $item -> fullHTML = $row['fullHTML'];
        $item -> crawledTime = $row['crawledTime'] !== null ? (int) $row['crawledTime'] : null;
        $item -> claimedUntil = $row['claimedUntil'] !== null ? (int) $row['claimedUntil'] : null;
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
     *
     * Both queries also only consider items whose host is actually ready to
     * be hit again (Hosts.nextCrawlTime NULL - never crawled - or already in
     * the past) - politeness applies before anything else, focused crawl or
     * not - and whose claim (see claim()) has either never been taken or has
     * expired, so multiple concurrent crawler processes never hand the same
     * item to two workers at once.
     *
     * Both queries also sort a never-attempted item ahead of one whose claim
     * merely expired without ever completing (crawledTime still NULL despite
     * claimedUntil being set) - a worker that got SIGKILLed mid-crawl
     * shouldn't cut in front of the entire backlog of untried items the
     * moment its claim lapses. It's retried only once every fresh item (and,
     * in the default query, every normal recrawl too) is exhausted - which in
     * a continuously-running crawl may never actually happen, short of a
     * narrow $topic pool running dry.
     *
     * Picking a row and claiming it are two separate statements (a plain
     * SELECT has no way to also lock/reserve what it reads), so there's a
     * window where a concurrent caller could win the claim first - claim()
     * reports that via its return value, and the loop here just retries
     * against a fresh SELECT (which will no longer include the
     * now-claimed-elsewhere row) rather than assuming its own pick still
     * stands.
     */
    public static function nextToCrawl(?string $topic = null): ?self
    {
        for ($attempt = 0; $attempt < self::MAX_CLAIM_ATTEMPTS; $attempt++) {
            $row = self::selectCandidateRow($topic);

            if ($row === null) {
                return null;
            }

            if (self::claim((int) $row['itemId'])) {
                return self::fromRow($row);
            }
        }

        return null;
    }

    private static function selectCandidateRow(?string $topic): ?array
    {
        $connection = Database::connection();

        if ($topic !== null && trim($topic) !== '') {
            $focused = mysqli_prepare($connection, '
SELECT `Items`.*
    FROM `Items`
    INNER JOIN `Hosts` ON `Hosts`.`hostId` = `Items`.`hostId`
    LEFT JOIN `Links` ON `Links`.`childId` = `Items`.`itemId`
    WHERE `Items`.`crawledTime` IS NULL
        AND (`Hosts`.`nextCrawlTime` IS NULL OR `Hosts`.`nextCrawlTime` <= UNIX_TIMESTAMP())
        AND (`Items`.`claimedUntil` IS NULL OR `Items`.`claimedUntil` <= UNIX_TIMESTAMP())
    GROUP BY `Items`.`itemId`
    ORDER BY (`Items`.`claimedUntil` IS NOT NULL) ASC, MAX(MATCH(`Links`.`description`) AGAINST (?)) DESC, `Items`.`itemId` ASC
    LIMIT 1
');
            mysqli_stmt_bind_param($focused, 's', $topic);
            mysqli_stmt_execute($focused);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($focused));

            if ($row !== null) {
                return $row;
            }
        }

        $result = mysqli_query($connection, '
SELECT `Items`.*
    FROM `Items`
    INNER JOIN `Hosts` ON `Hosts`.`hostId` = `Items`.`hostId`
    WHERE (`Hosts`.`nextCrawlTime` IS NULL OR `Hosts`.`nextCrawlTime` <= UNIX_TIMESTAMP())
        AND (`Items`.`claimedUntil` IS NULL OR `Items`.`claimedUntil` <= UNIX_TIMESTAMP())
    ORDER BY (`Items`.`crawledTime` IS NULL AND `Items`.`claimedUntil` IS NOT NULL) ASC, `Items`.`crawledTime` ASC
    LIMIT 1
');

        return $result !== false ? mysqli_fetch_assoc($result) : null;
    }

    /**
     * Claims this item the same way nextToCrawl() does, for the case where a
     * worker ends up holding an item it never went through nextToCrawl() to
     * get - specifically a redirect that lands on a pre-existing item (see
     * redirectTo() and bin/crawler.php's redirect loop). Returns false if
     * another worker already holds a live claim on it, so the caller can bow
     * out rather than double-crawl the same URL.
     */
    public function reclaim(): bool
    {
        return self::claim($this -> itemId);
    }

    /**
     * Atomically reserves $itemId for CLAIM_WINDOW_SECONDS - the WHERE here
     * re-checks the same claimedUntil condition selectCandidateRow() already
     * filtered on, so this UPDATE only actually claims the row (affected
     * rows > 0) if no other concurrent caller claimed it first in the gap
     * between that SELECT and this UPDATE.
     */
    private static function claim(int $itemId): bool
    {
        $connection = Database::connection();
        $claimedUntil = time() + self::CLAIM_WINDOW_SECONDS;

        $update = mysqli_prepare($connection, '
UPDATE `Items`
    SET `claimedUntil` = ?
    WHERE `itemId` = ?
        AND (`claimedUntil` IS NULL OR `claimedUntil` <= UNIX_TIMESTAMP())
');
        mysqli_stmt_bind_param($update, 'ii', $claimedUntil, $itemId);
        mysqli_stmt_execute($update);

        return mysqli_stmt_affected_rows($update) > 0;
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
        $urlString = self::truncate($url -> toString(), self::MAX_URL_LENGTH);
        $type = self::truncate($type, self::MAX_TYPE_LENGTH);
        $title = self::truncate($title, self::MAX_TITLE_LENGTH);
        $description = self::truncate($description, self::MAX_DESCRIPTION_LENGTH);
        $hostId = Host::findOrCreateByName($url -> host) -> hostId;

        $insert = mysqli_prepare($connection, '
INSERT INTO `Items` (`url`, `hostId`, `type`, `title`, `description`)
    VALUES (?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE `inc` = `inc` + 1
');
        mysqli_stmt_bind_param($insert, 'sisss', $urlString, $hostId, $type, $title, $description);
        mysqli_stmt_execute($insert);

        $item = new self();
        $item -> itemId = (int) mysqli_insert_id($connection);
        $item -> url = $urlString;
        $item -> hostId = $hostId;
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

        $type = self::truncate($type, self::MAX_TYPE_LENGTH);
        $title = self::truncate($title, self::MAX_TITLE_LENGTH);
        $description = self::truncate($description, self::MAX_DESCRIPTION_LENGTH);
        $keywords = self::truncate($keywords, self::MAX_KEYWORDS_LENGTH);

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
     * under two different itemIds forever. Also repoints hostId - a redirect
     * can just as easily land on a different host entirely (a domain-wide
     * 301 to a new site, a CDN move, ...), not just add/drop a trailing
     * slash on the same one.
     */
    public function redirectTo(URL $newURL): self
    {
        $connection = Database::connection();
        $newURLString = self::truncate($newURL -> toString(), self::MAX_URL_LENGTH);
        $newHostId = Host::findOrCreateByName($newURL -> host) -> hostId;

        try {
            $update = mysqli_prepare($connection, '
UPDATE `Items`
    SET `url` = ?, `hostId` = ?
    WHERE `itemId` = ?
');
            mysqli_stmt_bind_param($update, 'sii', $newURLString, $newHostId, $this -> itemId);
            mysqli_stmt_execute($update);

            $this -> url = $newURLString;
            $this -> hostId = $newHostId;

            return $this;
        } catch (\mysqli_sql_exception) {
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

    /**
     * mb_substr, not substr - these columns are utf8mb4, and cutting a
     * multi-byte character in half would either corrupt it or (depending on
     * exactly where the cut lands) still overflow the column. Truncating url
     * specifically is a last resort, not a fix - a cut-off URL points at a
     * different resource or nothing at all - but it's still better than this
     * throwing a mysqli_sql_exception and killing the whole crawl process
     * over one pathological page.
     */
    private static function truncate(?string $value, int $maxLength): ?string
    {
        return $value !== null ? mb_substr($value, 0, $maxLength) : null;
    }
}
