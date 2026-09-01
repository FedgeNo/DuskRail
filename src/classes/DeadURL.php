<?php

declare(strict_types=1);

/**
 * A URL the crawler has already resolved as unusable - a 404, a body that
 * isn't HTML or a decodable image, a redirect that led nowhere. Its Items row
 * is deleted (crawledTime means "real, presentable content", so there's
 * nothing to keep), and this is what's left behind to remember the verdict.
 *
 * Without it the crawl loops: the item is deleted, the next recrawl of any
 * page linking to it creates it again, a worker fetches it again, gets the
 * same 404, deletes it again - one wasted request per link per recrawl,
 * forever. Item::findOrCreateByURL() checks here first, so a known-dead URL
 * is never re-added at discovery.
 *
 * Deliberately not permanent: MAX_AGE_SECONDS lets a URL back into the crawl
 * eventually, since a 404 today can be a real page next year and this is the
 * only thing stopping it ever being looked at again.
 */
class DeadURL
{
    // Matches Items.url - the same URL string is stored in both, so they
    // truncate at the same point or a long URL would be recorded dead under a
    // string that never matches the one being looked up.
    private const MAX_URL_LENGTH = 767;

    private const MAX_REASON_LENGTH = 50;

    private const MAX_AGE_SECONDS = 90 * 24 * 60 * 60;

    // Placeholders per batched statement. Bounded so one page's discoveries
    // can't build a statement with thousands of parameters.
    private const LOOKUP_CHUNK_SIZE = 200;

    /**
     * Records $url as dead. INSERT ... ON DUPLICATE KEY UPDATE rather than
     * INSERT IGNORE: a URL that dies again after being let back in should
     * have its clock restarted, not keep the timestamp from the first time.
     */
    public static function record(string $url, string $reason): void
    {
        $connection = Database::connection();
        $url = mb_substr($url, 0, self::MAX_URL_LENGTH);
        $reason = mb_substr($reason, 0, self::MAX_REASON_LENGTH);
        $deadTime = time();

        $insert = mysqli_prepare($connection, '
INSERT INTO `DeadURLs` (`url`, `reason`, `deadTime`)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`), `deadTime` = VALUES(`deadTime`)
');
        mysqli_stmt_bind_param($insert, 'ssi', $url, $reason, $deadTime);
        mysqli_stmt_execute($insert);
    }

    /**
     * Whether $url is currently known-dead. An entry past MAX_AGE_SECONDS
     * doesn't count and is deleted on the way past, so the table stays the
     * size of the crawl's live dead-URL set rather than growing forever.
     */
    public static function isDead(string $url): bool
    {
        $connection = Database::connection();
        $url = mb_substr($url, 0, self::MAX_URL_LENGTH);

        $select = mysqli_prepare($connection, '
SELECT `deadURLId`, `deadTime`
    FROM `DeadURLs`
    WHERE `url` = ?
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 's', $url);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

        if ($row === null) {
            return false;
        }

        if (time() - (int) $row['deadTime'] < self::MAX_AGE_SECONDS) {
            return true;
        }

        $delete = mysqli_prepare($connection, '
DELETE FROM `DeadURLs`
    WHERE `deadURLId` = ?
');
        mysqli_stmt_bind_param($delete, 'i', $row['deadURLId']);
        mysqli_stmt_execute($delete);

        return false;
    }

    /**
     * Which of $urls are currently known-dead, as a url => true map. One
     * query for a whole page's discoveries instead of one per link: a page
     * links hundreds of URLs, and asking about each separately was the single
     * largest non-network cost of crawling one page.
     *
     * Entries past MAX_AGE_SECONDS don't count and are deleted here, the same
     * as isDead() does for a single lookup - otherwise batching would mean
     * nothing ever prunes the table.
     */
    public static function deadAmong(array $urls): array
    {
        if ($urls === []) {
            return [];
        }

        $connection = Database::connection();
        $dead = [];
        $expired = [];
        $cutoff = time() - self::MAX_AGE_SECONDS;

        // DeadURLs.url is a utf8mb4_unicode_ci column, so IN () matches a URL
        // recorded as "/Foo" when asked about "/foo" - but hands back the
        // spelling it holds. Keyed by that spelling, the caller's own isset()
        // misses and re-creates a URL already judged dead, which is the exact
        // refetch loop this class exists to stop. Each answer is keyed by the
        // string the caller asked with.
        $requested = [];

        foreach ($urls as $url) {
            $requested[mb_strtolower($url)] = $url;
        }

        foreach (array_chunk(array_unique($urls), self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $select = mysqli_prepare($connection, '
SELECT `deadURLId`, `url`, `deadTime`
    FROM `DeadURLs`
    WHERE `url` IN (' . $placeholders . ')
');
            mysqli_stmt_bind_param($select, str_repeat('s', count($chunk)), ...$chunk);
            mysqli_stmt_execute($select);
            $result = mysqli_stmt_get_result($select);

            while ($row = mysqli_fetch_assoc($result)) {
                if ((int) $row['deadTime'] >= $cutoff) {
                    $dead[$requested[mb_strtolower($row['url'])] ?? $row['url']] = true;
                } else {
                    $expired[] = (int) $row['deadURLId'];
                }
            }
        }

        foreach (array_chunk($expired, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $delete = mysqli_prepare($connection, '
DELETE FROM `DeadURLs`
    WHERE `deadURLId` IN (' . $placeholders . ')
');
            mysqli_stmt_bind_param($delete, str_repeat('i', count($chunk)), ...$chunk);
            mysqli_stmt_execute($delete);
        }

        return $dead;
    }

    /**
     * Forgets $url, so it's crawlable again. Called when an item at this URL
     * is created deliberately rather than discovered - a redirect landing on
     * it is real evidence the site still serves it, which outranks a verdict
     * reached some time ago.
     */
    public static function forget(string $url): void
    {
        $url = mb_substr($url, 0, self::MAX_URL_LENGTH);

        $delete = mysqli_prepare(Database::connection(), '
DELETE FROM `DeadURLs`
    WHERE `url` = ?
');
        mysqli_stmt_bind_param($delete, 's', $url);
        mysqli_stmt_execute($delete);
    }
}
