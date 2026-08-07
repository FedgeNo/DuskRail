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

    // A robots.txt Crawl-delay above this is honored as this. An hour
    // between requests still lets a host be crawled meaningfully; the sites
    // that declare a day are asking not to be crawled at all, and if that's
    // the intent, Disallow is the way they get to say it.
    private const MAX_CRAWL_DELAY_SECONDS = 3600;

    // Escalating backoff after consecutive connection-level failures (no
    // response at all - DNS, refused, TLS). Starts at 5 minutes and doubles
    // per failure up to a week: a host that's briefly unreachable is retried
    // soon, one that's been gone for weeks costs one connection attempt a
    // week, and nothing is deleted over what may be an outage.
    private const FAILURE_BASE_DELAY_SECONDS = 300;
    private const FAILURE_MAX_DELAY_SECONDS = 7 * 24 * 60 * 60;

    // How long a successfully-read robots.txt is trusted before it's read
    // again. Sites do revise theirs, and this crawler is meant to run for
    // months at a stretch - a copy fetched the first time a host was ever
    // seen shouldn't still be the one being enforced a season later.
    private const ROBOTS_TXT_MAX_AGE_SECONDS = 7 * 24 * 60 * 60;

    // How long before a *failed* robots.txt read is retried. Far shorter than
    // a successful one's lifetime: a failure means this host is currently
    // uncrawlable (see isRobotsTxtKnown()), so sitting on that verdict for a
    // week over what may have been a minute's outage would quietly drop the
    // whole host from the crawl.
    private const ROBOTS_TXT_RETRY_SECONDS = 60 * 60;

    // Statuses that are a real, final answer of "there is no robots.txt here"
    // rather than a failure to find out. Anything else - no response at all,
    // a 403, a 429, a 5xx - leaves the question open.
    private const ROBOTS_TXT_ABSENT_STATUS_CODES = [404, 410];

    // How much of a robots.txt is read at all. 500 KiB is the limit Google
    // documents and enforces, so a file bigger than this is already being
    // parsed only this far by the crawlers site owners actually write for -
    // and a real one confirmed the need for a limit here at all: linkedin.com
    // serves a robots.txt past the 64 KiB the column used to hold, which
    // killed the worker outright rather than storing anything. Cut on a line
    // boundary, since half a rule is worse than no rule.
    private const MAX_ROBOTS_TXT_BYTES = 500 * 1024;

    // Redirect hops followed while fetching robots.txt, matching what
    // Google's own parser documents.
    private const MAX_ROBOTS_TXT_REDIRECTS = 5;

    // How many not-yet-crawled items one host may have waiting at once. A
    // single large site can link thousands of URLs from one page; at a
    // 60-second politeness delay that's weeks of crawling for that host
    // alone, discovered faster than it can ever be consumed, while every
    // other host waits its turn behind it. Past this, new URLs on that host
    // are simply not recorded - it already has more queued than it can get
    // through, and they'll be rediscovered on the next recrawl of whatever
    // linked them.
    private const MAX_PENDING_ITEMS = 500;

    // Rows per batched statement, so one page's discoveries can't build a
    // statement with thousands of parameters.
    private const BATCH_CHUNK_SIZE = 200;

    // Pending counts are read once per host per process and then tracked
    // locally. Re-counting per discovered link would mean a COUNT(*) per
    // link on a page that can carry hundreds, to enforce a limit that only
    // has to be approximately right.
    private static array $pendingItemCounts = [];

    public ?int $hostId = null;
    public ?string $host = null;
    public ?string $robotsTxt = null;
    public ?int $robotsTxtFetched = null;
    public ?int $robotsTxtFetchedTime = null;
    public ?int $crawlDelaySeconds = null;
    public ?int $consecutiveFailures = null;
    public ?int $crawledTime = null;
    public ?int $nextCrawlTime = null;
    public ?int $inc = null;

    public static function fromRow(array $row): self
    {
        $host = new self();

        $host -> hostId = (int) $row['hostId'];
        $host -> host = $row['host'];
        $host -> robotsTxt = $row['robotsTxt'];
        $host -> robotsTxtFetched = (int) $row['robotsTxtFetched'];
        $host -> robotsTxtFetchedTime = $row['robotsTxtFetchedTime'] !== null ? (int) $row['robotsTxtFetchedTime'] : null;
        $host -> crawlDelaySeconds = $row['crawlDelaySeconds'] !== null ? (int) $row['crawlDelaySeconds'] : null;
        $host -> consecutiveFailures = (int) $row['consecutiveFailures'];
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
     * Finds or creates every named host at once, as name => Host. $counts
     * maps hostname to how many times a page referred to it, so `inc` ends
     * up exactly where per-link calls would have left it - one statement
     * bumping by N rather than N statements bumping by one.
     *
     * @param array<string, int> $counts
     * @return array<string, self>
     */
    public static function findOrCreateManyByName(array $counts): array
    {
        if ($counts === []) {
            return [];
        }

        $connection = Database::connection();
        $names = array_keys($counts);

        foreach (array_chunk($names, self::BATCH_CHUNK_SIZE) as $chunk) {
            // VALUES() feeds each row's own increment into the duplicate
            // branch, so one statement can bump different hosts by different
            // amounts.
            $rows = implode(', ', array_fill(0, count($chunk), '(?, ?)'));
            $arguments = [];
            $types = '';

            foreach ($chunk as $name) {
                $arguments[] = $name;
                $arguments[] = $counts[$name];
                $types .= 'si';
            }

            $insert = mysqli_prepare($connection, '
INSERT INTO `Hosts` (`host`, `inc`)
    VALUES ' . $rows . '
    ON DUPLICATE KEY UPDATE `inc` = `inc` + VALUES(`inc`)
');
            mysqli_stmt_bind_param($insert, $types, ...$arguments);
            mysqli_stmt_execute($insert);
        }

        $hosts = [];

        foreach (array_chunk($names, self::BATCH_CHUNK_SIZE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            $select = mysqli_prepare($connection, '
SELECT *
    FROM `Hosts`
    WHERE `host` IN (' . $placeholders . ')
');
            mysqli_stmt_bind_param($select, str_repeat('s', count($chunk)), ...$chunk);
            mysqli_stmt_execute($select);
            $result = mysqli_stmt_get_result($select);

            while ($row = mysqli_fetch_assoc($result)) {
                $hosts[$row['host']] = self::fromRow($row);
            }
        }

        return $hosts;
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
        $nextCrawlTime = $now + max($wasRateLimited ? self::RATE_LIMITED_DELAY_SECONDS : self::DEFAULT_DELAY_SECONDS, $this -> honoredCrawlDelay());

        // A response of any kind - even a 429 - means the host is reachable,
        // which is what consecutiveFailures counts the absence of.
        $update = mysqli_prepare($connection, '
UPDATE `Hosts`
    SET `crawledTime` = ?, `nextCrawlTime` = ?, `consecutiveFailures` = 0
    WHERE `hostId` = ?
');
        mysqli_stmt_bind_param($update, 'iii', $now, $nextCrawlTime, $this -> hostId);
        mysqli_stmt_execute($update);

        $this -> crawledTime = $now;
        $this -> nextCrawlTime = $nextCrawlTime;
        $this -> consecutiveFailures = 0;
    }

    /**
     * Records a connection-level failure - no response at all - and backs
     * this host off on an escalating schedule (see FAILURE_BASE/MAX above).
     * The failure is about the host, not whichever URL happened to be first
     * in line, so the item itself is left alone for a retry once the backoff
     * passes rather than deleted over what may be a passing outage.
     */
    public function recordFailure(): void
    {
        $connection = Database::connection();
        $now = time();

        $this -> consecutiveFailures = ($this -> consecutiveFailures ?? 0) + 1;
        $backoff = min(self::FAILURE_BASE_DELAY_SECONDS * (2 ** min($this -> consecutiveFailures - 1, 20)), self::FAILURE_MAX_DELAY_SECONDS);
        $nextCrawlTime = $now + $backoff;

        $update = mysqli_prepare($connection, '
UPDATE `Hosts`
    SET `crawledTime` = ?, `nextCrawlTime` = ?, `consecutiveFailures` = ?
    WHERE `hostId` = ?
');
        mysqli_stmt_bind_param($update, 'iiii', $now, $nextCrawlTime, $this -> consecutiveFailures, $this -> hostId);
        mysqli_stmt_execute($update);

        $this -> crawledTime = $now;
        $this -> nextCrawlTime = $nextCrawlTime;
    }

    /**
     * The politeness delay this host actually gets: its robots.txt
     * Crawl-delay when one was declared (capped - see
     * MAX_CRAWL_DELAY_SECONDS), the default otherwise.
     */
    private function honoredCrawlDelay(): int
    {
        if ($this -> crawlDelaySeconds === null) {
            return self::DEFAULT_DELAY_SECONDS;
        }

        return max(self::DEFAULT_DELAY_SECONDS, min($this -> crawlDelaySeconds, self::MAX_CRAWL_DELAY_SECONDS));
    }

    /**
     * Reserves this host for the caller, atomically: pushes nextCrawlTime out
     * by the normal politeness delay, but only if the host was actually due.
     * Returns false when it wasn't - meaning another worker got there first
     * and this one must leave the host alone.
     *
     * recordCrawl() alone can't do this job. It runs *after* a response comes
     * back, so between Item::nextToCrawl() filtering on nextCrawlTime and the
     * request actually completing there's a window where a second worker sees
     * the same host still due and fires at it too - concurrent workers picking
     * different items on one host would sail straight past the cooldown that
     * exists precisely to stop that.
     */
    public function reserve(): bool
    {
        if (!self::reserveById($this -> hostId)) {
            return false;
        }

        $this -> nextCrawlTime = time() + $this -> honoredCrawlDelay();

        return true;
    }

    /**
     * reserve() for a caller that has a hostId but no Host object yet -
     * Item::nextToCrawl(), which is choosing between candidate rows and
     * shouldn't load a whole Host just to find out it lost the race for it.
     * The delay comes out of the row inside the UPDATE itself (including the
     * same Crawl-delay cap honoredCrawlDelay() applies), so the reservation
     * stays one atomic statement.
     */
    public static function reserveById(int $hostId): bool
    {
        $connection = Database::connection();
        $defaultDelay = self::DEFAULT_DELAY_SECONDS;
        $maxDelay = self::MAX_CRAWL_DELAY_SECONDS;

        $update = mysqli_prepare($connection, '
UPDATE `Hosts`
    SET `nextCrawlTime` = UNIX_TIMESTAMP() + GREATEST(?, LEAST(COALESCE(`crawlDelaySeconds`, ?), ?))
    WHERE `hostId` = ?
        AND (`nextCrawlTime` IS NULL OR `nextCrawlTime` <= UNIX_TIMESTAMP())
');
        mysqli_stmt_bind_param($update, 'iiii', $defaultDelay, $defaultDelay, $maxDelay, $hostId);
        mysqli_stmt_execute($update);

        return mysqli_stmt_affected_rows($update) > 0;
    }

    /**
     * Fetches this host's robots.txt if there's no usable answer on file -
     * never read at all, read too long ago (ROBOTS_TXT_MAX_AGE_SECONDS), or
     * last read unsuccessfully and now due another try
     * (ROBOTS_TXT_RETRY_SECONDS). Returns true only when a fetch actually
     * happened and came back readable - the moment sitemap ingestion (see
     * Sitemap) is worth running, since the Sitemap: lines just arrived.
     */
    public function fetchRobotsTxtIfStale(string $scheme, string $chromeEndpoint): bool
    {
        if (!$this -> isRobotsTxtStale()) {
            return false;
        }

        $url = new URL($scheme . '://' . $this -> host . '/robots.txt');
        $statusCode = null;
        $body = '';

        // Fetched through the same browser every page goes through, not a
        // plain HTTP client. Two reasons, both real: it's the same host the
        // page fetch is about to hit, so the connection and handshake are
        // already paid for, and - more importantly - the hosts that refuse
        // plain clients outright are exactly the ones whose rules matter
        // most. Those were answering nothing at all, which records as "rules
        // unknown" and stops the host being crawled at all.
        //
        // ChromeConnection never follows a redirect (bin/crawler.php's own
        // hop loop stays in control for pages), so the hops are walked here.
        // Plenty of sites 301 /robots.txt to a canonical host, and treating
        // that redirect as the answer would record "unreadable" for a host
        // publishing perfectly good rules one hop away.
        for ($hop = 0; $hop <= self::MAX_ROBOTS_TXT_REDIRECTS; $hop++) {
            try {
                $connection = new ChromeConnection($chromeEndpoint, $url, null);
            } catch (\Throwable) {
                // The shared browser is unreachable - an infrastructure
                // problem, not an answer about this host. Nothing is
                // recorded, so the rules stay as stale as they were and the
                // next run asks again rather than banking a false verdict.
                return false;
            }

            $statusCode = $connection -> statusCode;

            // Every hop is a real request against this host, same as fetching
            // a page - without recording it, first contact with a
            // never-before-seen host would fire these and the page request
            // back to back, before any politeness cooldown applied.
            $this -> recordCrawl($statusCode !== null && in_array($statusCode, [429, 503], true));

            if ($statusCode === null || $statusCode < 300 || $statusCode >= 400) {
                $body = $connection -> readBody();
                break;
            }

            $location = $connection -> headers['location'] ?? null;

            if ($location === null) {
                break;
            }

            $url = $url -> resolve(new URL($location));

            if (!$url -> isValid()) {
                break;
            }
        }

        if ($statusCode !== null && $statusCode >= 200 && $statusCode < 300) {
            $this -> robotsTxt = self::capRobotsTxt($body);
            $this -> robotsTxtFetched = 1;
        } elseif ($statusCode !== null && in_array($statusCode, self::ROBOTS_TXT_ABSENT_STATUS_CODES, true)) {
            // A definitive "there is nothing here" - the overwhelmingly common
            // case, and a real answer: no rules declared means none apply.
            $this -> robotsTxt = '';
            $this -> robotsTxtFetched = 1;
        } else {
            // No response at all, a 403, a 5xx: the host's rules are unknown,
            // not absent. Recorded as such rather than as an empty rule set,
            // because an empty rule set reads as "crawl anything" - which is
            // the one conclusion a failed read must never be allowed to reach.
            // Hosts that block non-browser clients outright land here, and
            // they're exactly the ones most likely to have rules worth obeying.
            $this -> robotsTxt = null;
            $this -> robotsTxtFetched = 0;
        }

        $this -> robotsTxtFetchedTime = time();

        // Crawl-delay is parsed once here, when the file changes hands, and
        // stored as its own column - reserveById() needs it inside a single
        // atomic UPDATE, where re-parsing a text blob isn't an option.
        $this -> crawlDelaySeconds = $this -> robotsTxt !== null ? self::crawlDelayFor($this -> robotsTxt) : null;

        $update = mysqli_prepare(Database::connection(), '
UPDATE `Hosts`
    SET `robotsTxt` = ?, `robotsTxtFetched` = ?, `robotsTxtFetchedTime` = ?, `crawlDelaySeconds` = ?
    WHERE `hostId` = ?
');
        mysqli_stmt_bind_param($update, 'siiii', $this -> robotsTxt, $this -> robotsTxtFetched, $this -> robotsTxtFetchedTime, $this -> crawlDelaySeconds, $this -> hostId);
        mysqli_stmt_execute($update);

        return $this -> robotsTxtFetched === 1 && $this -> robotsTxt !== '';
    }

    /**
     * The Crawl-delay declared in the "User-agent: *" group, or null when
     * there isn't one. Scoped to that group the same way the Allow/Disallow
     * rules are - a delay aimed at a named crawler isn't a request to this
     * one. The raw declared value is stored; honoredCrawlDelay() applies the
     * cap at use time, so a site raising or lowering its wish is always read
     * back from what it actually said.
     */
    private static function crawlDelayFor(string $robotsTxt): ?int
    {
        $currentUserAgents = [];
        $seenDirectiveSinceLastUserAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^User-agent\s*:\s*(.*)$/i', $line, $match)) {
                if ($seenDirectiveSinceLastUserAgent) {
                    $currentUserAgents = [];
                    $seenDirectiveSinceLastUserAgent = false;
                }

                $currentUserAgents[] = trim($match[1]);
                continue;
            }

            $seenDirectiveSinceLastUserAgent = true;

            if (!in_array('*', $currentUserAgents, true)) {
                continue;
            }

            if (str_starts_with(strtolower($line), 'crawl-delay') && str_contains($line, ':')) {
                $value = trim(explode(':', $line, 2)[1]);

                if (is_numeric($value) && (float) $value > 0) {
                    return (int) ceil((float) $value);
                }
            }
        }

        return null;
    }

    /**
     * Whether this host has room for another not-yet-crawled item (see
     * MAX_PENDING_ITEMS). Callers that go on to actually record one should
     * say so via countNewPendingItem(), so the limit still holds within a
     * single page's worth of discovered links.
     */
    public function hasPendingCapacity(): bool
    {
        self::$pendingItemCounts[$this -> hostId] ??= $this -> pendingItemCount();

        return self::$pendingItemCounts[$this -> hostId] < self::MAX_PENDING_ITEMS;
    }

    public function countNewPendingItem(): void
    {
        self::$pendingItemCounts[$this -> hostId] ??= $this -> pendingItemCount();
        self::$pendingItemCounts[$this -> hostId]++;
    }

    private function pendingItemCount(): int
    {
        $select = mysqli_prepare(Database::connection(), '
SELECT COUNT(*) AS `pendingItems`
    FROM `Items`
    WHERE `hostId` = ?
        AND `crawledTime` IS NULL
');
        mysqli_stmt_bind_param($select, 'i', $this -> hostId);
        mysqli_stmt_execute($select);

        return (int) mysqli_fetch_assoc(mysqli_stmt_get_result($select))['pendingItems'];
    }

    /**
     * Whether this host's rules are actually in hand. False means the last
     * read failed and nothing can be said about what's allowed here - callers
     * must treat that as "don't crawl this host right now", not as "no rules,
     * go ahead", and must leave the item alone for a later retry rather than
     * deleting it (nothing is wrong with the item; we just can't ask).
     */
    public function isRobotsTxtKnown(): bool
    {
        return $this -> robotsTxtFetched === 1;
    }

    /**
     * Cuts an oversized robots.txt back to MAX_ROBOTS_TXT_BYTES, at the last
     * complete line inside that budget - strlen/substr rather than the mb_
     * equivalents deliberately, since the limit is a byte budget (the
     * column's and the spec's alike), not a character count.
     */
    private static function capRobotsTxt(string $robotsTxt): string
    {
        if (strlen($robotsTxt) <= self::MAX_ROBOTS_TXT_BYTES) {
            return $robotsTxt;
        }

        $capped = substr($robotsTxt, 0, self::MAX_ROBOTS_TXT_BYTES);
        $lastNewline = strrpos($capped, chr(10));

        return $lastNewline !== false ? substr($capped, 0, $lastNewline) : '';
    }

    private function isRobotsTxtStale(): bool
    {
        if ($this -> robotsTxtFetchedTime === null) {
            return true;
        }

        $age = time() - $this -> robotsTxtFetchedTime;

        return $age >= ($this -> isRobotsTxtKnown() ? self::ROBOTS_TXT_MAX_AGE_SECONDS : self::ROBOTS_TXT_RETRY_SECONDS);
    }

    /**
     * Whether robots.txt says not to crawl $path, per the "User-agent: *"
     * group (the one that applies to any crawler not specifically named
     * elsewhere in the file, which this one never is). Follows Google's
     * documented algorithm rather than a plain prefix match: every Allow/
     * Disallow line in that group is a pattern (a literal prefix, optionally
     * containing "*" - matches any run of characters - and optionally ending
     * in "$" - anchors the match to the end of the path instead of allowing
     * anything after), and among every pattern that matches $path, the
     * longest one (by character count of the pattern itself) wins; a tie is
     * decided in favor of Allow, matching real crawlers' behavior on the
     * rare robots.txt that states both at equal specificity. No path
     * matching any rule at all is implicitly allowed.
     *
     * $path is matched with its query string attached, per the spec - a rule
     * like "Disallow: /*?sort=" only means anything at all against the query.
     *
     * A host whose robots.txt couldn't be read is disallowed outright here.
     * Callers are expected to check isRobotsTxtKnown() first and back off
     * rather than reach this, but the fallback direction matters: guessing
     * "allowed" on a host that may well have said otherwise is the one
     * mistake that can't be taken back once the request has gone out.
     */
    public function isDisallowed(string $path): bool
    {
        if (!$this -> isRobotsTxtKnown()) {
            return true;
        }

        if ($this -> robotsTxt === null || $this -> robotsTxt === '') {
            return false;
        }

        $winningType = 'allow';
        $winningLength = -1;

        foreach (self::rulesForWildcardUserAgent($this -> robotsTxt) as $rule) {
            if (!preg_match($rule['regex'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($length > $winningLength || ($length === $winningLength && $rule['type'] === 'allow')) {
                $winningLength = $length;
                $winningType = $rule['type'];
            }
        }

        return $winningType === 'disallow';
    }

    /**
     * Every Allow/Disallow line scoped to "User-agent: *", each turned into
     * a matchable regex up front rather than re-parsed per isDisallowed()
     * call. Consecutive "User-agent:" lines share the rules that follow them
     * (per spec); a "User-agent:" line seen *after* a Disallow/Allow starts a
     * fresh group instead of extending the previous one - the same "*" group
     * can legitimately appear more than once in one file (e.g. a
     * CMS-generated block appended after a hand-written one), and both
     * contribute their rules.
     *
     * User-agent grouping specifically does matter, unlike the rest of the
     * simplifications here (no User-agent-name matching beyond "*", no
     * Sitemap/Crawl-delay handling) - confirmed breaking real crawling
     * otherwise: a real site's only "Disallow: /" was scoped to
     * "User-agent: ClaudeBot" (increasingly common - many sites block
     * AI-training bots by name while leaving general search crawling alone),
     * and ignoring which group it belonged to made every single path on that
     * site look disallowed to this crawler too.
     *
     * @return list<array{type: 'allow'|'disallow', pattern: string, regex: string}>
     */
    private static function rulesForWildcardUserAgent(string $robotsTxt): array
    {
        $rules = [];
        $currentUserAgents = [];
        // True once *any* real directive (Allow, Disallow, Sitemap,
        // Content-Signal, Crawl-delay, ...) has been seen since the last
        // User-agent line - not just Allow/Disallow specifically. A group
        // that's only ever had an "Allow: /" (no Disallow at all, e.g. a
        // Content-Signal block) still needs the next "User-agent:" line to
        // start a fresh group rather than silently extending this one -
        // confirmed happening for real: a file's "User-agent: *" block had
        // only "Content-Signal:"/"Allow: /" lines, so without this a
        // following "User-agent: ClaudeBot" merged into the same group as
        // "*", making its "Disallow: /" apply to this crawler too.
        $seenDirectiveSinceLastUserAgent = false;

        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue; // blank/comment lines are invisible to grouping
            }

            if (preg_match('/^User-agent\s*:\s*(.*)$/i', $line, $match)) {
                if ($seenDirectiveSinceLastUserAgent) {
                    $currentUserAgents = [];
                    $seenDirectiveSinceLastUserAgent = false;
                }

                $currentUserAgents[] = trim($match[1]);
                continue;
            }

            $seenDirectiveSinceLastUserAgent = true;

            if (!preg_match('/^(Allow|Disallow)\s*:\s*(.*?)\s*$/i', $line, $match)) {
                continue;
            }

            if (!in_array('*', $currentUserAgents, true)) {
                continue;
            }

            // An empty value means "no restriction" for Disallow (the spec's
            // own "allow everything" escape hatch) and is meaningless for
            // Allow either way - neither is a pattern worth matching against.
            if ($match[2] === '') {
                continue;
            }

            $rules[] = [
                'type' => strtolower($match[1]) === 'allow' ? 'allow' : 'disallow',
                'pattern' => $match[2],
                'regex' => self::patternToRegex($match[2]),
            ];
        }

        return $rules;
    }

    /**
     * Turns a robots.txt Allow/Disallow value into a regex matching it as a
     * path prefix, per the spec's actual wildcard rules rather than a plain
     * string prefix: "*" matches any run of characters, and a trailing "$"
     * anchors the match to the exact end of the path instead of allowing
     * anything (however not matched by a "*") after it.
     */
    private static function patternToRegex(string $pattern): string
    {
        $anchored = str_ends_with($pattern, '$');
        $body = $anchored ? substr($pattern, 0, -1) : $pattern;
        $escaped = str_replace('\*', '.*', preg_quote($body, '#'));

        return '#^' . $escaped . ($anchored ? '$' : '') . '#';
    }
}
