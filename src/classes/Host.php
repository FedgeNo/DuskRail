<?php

declare(strict_types=1);

class Host
{
    // Normal politeness delay between requests to the same host.
    private const DEFAULT_DELAY_SECONDS = 60;

    // A 429/503 is the server explicitly asking to be left alone - waiting
    // the same single minute as any other request would just hit it again
    // almost immediately, so this backs off substantially further.
    private const RATE_LIMITED_DELAY_SECONDS = 300;

    public ?int $hostId = null;
    public ?string $host = null;
    public ?string $robotsTxt = null;
    public ?int $crawledTime = null;
    public ?int $nextCrawlTime = null;
    public ?int $inc = null;

    public static function fromRow(array $row): self
    {
        $host = new self();

        $host -> hostId = (int) $row['hostId'];
        $host -> host = $row['host'];
        $host -> robotsTxt = $row['robotsTxt'];
        $host -> crawledTime = $row['crawledTime'] !== null ? (int) $row['crawledTime'] : null;
        $host -> nextCrawlTime = $row['nextCrawlTime'] !== null ? (int) $row['nextCrawlTime'] : null;
        $host -> inc = (int) $row['inc'];

        return $host;
    }

    /**
     * Finds the existing Hosts row for a hostname or creates one - a single
     * upsert (bumping inc, same proven trick as Item::findOrCreateByURL())
     * to reliably get the row's id via mysqli_insert_id() either way, then a
     * follow-up SELECT by that id. The extra SELECT (which
     * Item::findOrCreateByURL() doesn't need) matters here specifically:
     * callers need the *real* robotsTxt/crawledTime/nextCrawlTime for an
     * already-existing host, not blank defaults - fetchRobotsTxtIfMissing()
     * would otherwise refetch robots.txt on every single call.
     */
    public static function findOrCreateByName(string $name): self
    {
        $connection = Database::connection();

        $insert = mysqli_prepare($connection, '
INSERT INTO `Hosts` (`host`)
    VALUES (?)
    ON DUPLICATE KEY UPDATE `inc` = `inc` + 1
');
        mysqli_stmt_bind_param($insert, 's', $name);
        mysqli_stmt_execute($insert);

        $hostId = (int) mysqli_insert_id($connection);

        $select = mysqli_prepare($connection, '
SELECT *
    FROM `Hosts`
    WHERE `hostId` = ?
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 'i', $hostId);
        mysqli_stmt_execute($select);

        return self::fromRow(mysqli_fetch_assoc(mysqli_stmt_get_result($select)));
    }

    /**
     * Records that a request was just made to this host, pushing
     * nextCrawlTime out so nothing else from this host gets picked again too
     * soon. Called once per actual request, regardless of what the response
     * turned out to be.
     */
    public function recordCrawl(bool $wasRateLimited): void
    {
        $connection = Database::connection();
        $now = time();
        $nextCrawlTime = $now + ($wasRateLimited ? self::RATE_LIMITED_DELAY_SECONDS : self::DEFAULT_DELAY_SECONDS);

        $update = mysqli_prepare($connection, '
UPDATE `Hosts`
    SET `crawledTime` = ?, `nextCrawlTime` = ?
    WHERE `hostId` = ?
');
        mysqli_stmt_bind_param($update, 'iii', $now, $nextCrawlTime, $this -> hostId);
        mysqli_stmt_execute($update);

        $this -> crawledTime = $now;
        $this -> nextCrawlTime = $nextCrawlTime;
    }

    /**
     * Fetches and caches this host's robots.txt the first time it's seen -
     * never refetched after that (no TTL/refresh policy yet).
     */
    public function fetchRobotsTxtIfMissing(string $scheme): void
    {
        if ($this -> robotsTxt !== null) {
            return;
        }

        $connection = new HTTPConnection(new URL($scheme . '://' . $this -> host . '/robots.txt'));
        $body = $connection -> readBody();

        // A missing/erroring robots.txt (very common - most sites 404 it)
        // means "no restrictions declared", cached as an empty string
        // rather than left null - null is what triggers a re-fetch here,
        // and an empty string means "already checked, nothing there".
        $this -> robotsTxt = ($connection -> statusCode >= 200 && $connection -> statusCode < 300) ? $body : '';

        $update = mysqli_prepare(Database::connection(), '
UPDATE `Hosts`
    SET `robotsTxt` = ?
    WHERE `hostId` = ?
');
        mysqli_stmt_bind_param($update, 'si', $this -> robotsTxt, $this -> hostId);
        mysqli_stmt_execute($update);
    }

    /**
     * Whether robots.txt says not to crawl $path. Deliberately simple - a
     * plain prefix match against every "Disallow:" line in the whole file,
     * not a real robots.txt parser: no User-agent grouping (a Disallow
     * under any user-agent applies, not just ours or "*"), no wildcards. A
     * Disallow value of "/foo" blocks "/foo", "/foo/bar", and "/foobar"
     * alike - that's what a path prefix means here, same as the real spec.
     * An empty Disallow value ("Disallow:" with nothing after it) means
     * "allow everything" per the spec, so it's skipped rather than
     * matching every path the way a naive prefix-of-"" check would.
     */
    public function isDisallowed(string $path): bool
    {
        if ($this -> robotsTxt === null || $this -> robotsTxt === '') {
            return false;
        }

        foreach (preg_split('/\r\n|\r|\n/', $this -> robotsTxt) as $line) {
            if (!preg_match('/^\s*Disallow\s*:\s*(.*?)\s*$/i', $line, $match)) {
                continue;
            }

            $disallowedPrefix = $match[1];

            if ($disallowedPrefix !== '' && str_starts_with($path, $disallowedPrefix)) {
                return true;
            }
        }

        return false;
    }
}
