<?php

declare(strict_types=1);

/**
 * Request budgets for the public endpoints - the ones anyone can call without
 * signing in, each of which runs a real query against an index that only gets
 * bigger.
 *
 * Two independent budgets, and a request has to be inside both:
 *
 *  - by IP, which is the actual limit. It's the one thing a caller can't
 *    simply discard, so it's what stops sustained abuse - but it's shared by
 *    everyone behind one NAT, so it has to be loose enough for a whole office
 *    to search from.
 *  - by client cookie, which is much tighter because it represents one
 *    browser. Anyone deliberately abusing this clears the cookie and falls
 *    back to the IP budget, and that's fine: this half isn't aimed at them.
 *    It's aimed at one client that has gone wrong - a retry loop, a stuck
 *    page reloading itself - which the IP budget alone would either miss
 *    entirely or catch only by throttling every other user sharing that IP.
 *
 * Fixed windows, keyed by which window a request lands in, so the count is a
 * single atomic upsert with no read-modify-write to race. The known cost of
 * fixed windows is that a caller timing requests around a boundary can push
 * up to twice the limit through in one window's worth of time; the limits
 * here are set with that in mind rather than pretending it away.
 */
class RateLimit
{
    private const WINDOW_SECONDS = 60;

    // One browser. Far above what a person clicking search results can
    // produce, low enough that a client stuck in a loop is caught quickly.
    private const CLIENT_LIMIT = 60;

    // One address, which may be a whole building behind one NAT - so this is
    // deliberately several times the per-browser figure rather than equal to
    // it.
    private const ADDRESS_LIMIT = 300;

    // Password attempts, per address. Nothing legitimate tries more than a
    // handful of passwords in a minute, and everything past this is refused
    // before password_verify() ever runs - so a brute-force run gets five
    // guesses a minute, not as many as bcrypt can be made to check.
    private const LOGIN_LIMIT = 5;

    private const CLIENT_COOKIE = 'duskrailClient';
    private const CLIENT_TOKEN_BYTES = 16;
    private const CLIENT_TOKEN_LENGTH = self::CLIENT_TOKEN_BYTES * 2;

    // Rows are only of interest for the window they belong to. Cleared out on
    // roughly this fraction of requests rather than by a scheduled job -
    // there's no cron to depend on, and this keeps the table from quietly
    // becoming a permanent record of who searched from where.
    private const CLEANUP_PROBABILITY = 100;
    private const CLEANUP_AFTER_WINDOWS = 3;

    /**
     * Applies both budgets to this request, answering 429 and stopping if
     * either is exceeded. Called at the top of every public endpoint, before
     * any query the caller was hoping to make.
     */
    public static function enforcePublicAPI(): void
    {
        $retryAfter = self::exceededBy();

        if ($retryAfter === null) {
            return;
        }

        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        echo json_encode(['error' => 'rate limited', 'retryAfter' => $retryAfter]);
        exit;
    }

    /**
     * Counts one password attempt against this address and reports how many
     * seconds to wait if it's over budget, or null if the attempt may
     * proceed. The caller renders the answer itself rather than this class
     * emitting JSON - login.php is a form page, not an API, and a 429 JSON
     * body would just be text in a browser tab.
     */
    public static function loginRetryAfter(): ?int
    {
        $windowStart = intdiv(time(), self::WINDOW_SECONDS) * self::WINDOW_SECONDS;

        if (self::countRequest('login', self::address(), $windowStart) <= self::LOGIN_LIMIT) {
            return null;
        }

        return max(1, $windowStart + self::WINDOW_SECONDS - time());
    }

    /**
     * Makes sure this browser is holding a client token, without counting
     * anything against it. Called by the search page so the token is already
     * in place by the time that page starts calling the JSON endpoints -
     * otherwise every load before the cookie lands looks like a new client.
     */
    public static function issueClientToken(): void
    {
        self::clientToken();
    }

    /**
     * How many seconds until the current window ends, or null if this request
     * is within both budgets. Both counters are always incremented, even once
     * one has already tripped - a caller that keeps hammering a closed door
     * shouldn't have its other budget quietly recovering while it does.
     */
    private static function exceededBy(): ?int
    {
        $windowStart = intdiv(time(), self::WINDOW_SECONDS) * self::WINDOW_SECONDS;

        $addressCount = self::countRequest('address', self::address(), $windowStart);
        $clientCount = self::countRequest('client', self::clientToken(), $windowStart);

        self::cleanUpOccasionally($windowStart);

        if ($addressCount <= self::ADDRESS_LIMIT && $clientCount <= self::CLIENT_LIMIT) {
            return null;
        }

        return max(1, $windowStart + self::WINDOW_SECONDS - time());
    }

    /**
     * Records one request against a budget and returns how many that budget
     * has now seen this window.
     *
     * LAST_INSERT_ID(expr) sets the value mysqli_insert_id() reports back, so
     * the running count comes out of the same statement that increments it -
     * one round trip, and no window where a concurrent request could read a
     * count between another's increment and its own.
     */
    private static function countRequest(string $bucket, string $identifier, int $windowStart): int
    {
        $connection = Database::connection();

        $insert = mysqli_prepare($connection, '
INSERT INTO `RateLimits` (`bucket`, `identifier`, `windowStart`, `requests`)
    VALUES (?, ?, ?, LAST_INSERT_ID(1))
    ON DUPLICATE KEY UPDATE `requests` = LAST_INSERT_ID(`requests` + 1)
');
        mysqli_stmt_bind_param($insert, 'ssi', $bucket, $identifier, $windowStart);
        mysqli_stmt_execute($insert);

        return (int) mysqli_insert_id($connection);
    }

    /**
     * REMOTE_ADDR, never X-Forwarded-For. That header is set by whoever sent
     * the request unless a proxy this site actually controls overwrote it,
     * so trusting it here would let a caller both slip its own budget and
     * spend somebody else's by naming them. A deployment behind a real proxy
     * needs the proxy to set REMOTE_ADDR (mod_remoteip and equivalents do
     * exactly this) rather than this code to start believing a header.
     */
    private static function address(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    /**
     * A random per-browser token, minted on first contact and sent back as a
     * cookie. Not a session - a session per anonymous searcher would mean
     * server-side state for every visitor, to hold one value that means
     * nothing on its own and grants nothing.
     */
    private static function clientToken(): string
    {
        $existing = (string) ($_COOKIE[self::CLIENT_COOKIE] ?? '');

        // Only accept the shape this issues - 32 hex characters. A cookie is
        // caller-controlled input, and it's about to be a database key.
        if (strlen($existing) === self::CLIENT_TOKEN_LENGTH && ctype_xdigit($existing)) {
            return $existing;
        }

        $token = bin2hex(random_bytes(self::CLIENT_TOKEN_BYTES));

        if (!headers_sent()) {
            setcookie(self::CLIENT_COOKIE, $token, [
                'expires' => time() + 365 * 24 * 60 * 60,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
            ]);
        }

        return $token;
    }

    private static function cleanUpOccasionally(int $windowStart): void
    {
        if (random_int(1, self::CLEANUP_PROBABILITY) !== 1) {
            return;
        }

        $expiredBefore = $windowStart - self::CLEANUP_AFTER_WINDOWS * self::WINDOW_SECONDS;

        $delete = mysqli_prepare(Database::connection(), '
DELETE FROM `RateLimits`
    WHERE `windowStart` < ?
');
        mysqli_stmt_bind_param($delete, 'i', $expiredBefore);
        mysqli_stmt_execute($delete);
    }
}
