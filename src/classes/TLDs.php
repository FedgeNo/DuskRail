<?php

declare(strict_types=1);

/**
 * The current set of valid top-level domains, kept fresh from IANA's own
 * published root-zone TLD list - data.iana.org/TLD/tlds-alpha-by-domain.txt
 * is IANA's plain-text mirror specifically meant for automated fetching (as
 * opposed to scraping their human-facing HTML page), refreshed here on a
 * schedule rather than bundled as a static file that would silently go
 * stale as ICANN delegates new TLDs over time.
 *
 * URL::isValid() uses this to require every crawled URL to have a real TLD.
 * That rejects IP literals (no IPv4 or IPv6 literal has a real TLD as its
 * last dot-separated label) along with single-label internal hostnames
 * ("http://fileserver/") and made-up internal-only suffixes
 * ("http://app.corp/", "http://db.local/") in one move - though it is only
 * the first half of this project's SSRF defence, since a perfectly ordinary
 * TLD can still front a name pointed at a private address (see IPAddress).
 *
 * Refreshed by bin/refresh-lists.php on a weekly timer, never on demand from
 * a crawl - see that script for why the schedule lives outside the crawler.
 */
class TLDs
{
    private const SOURCE_URL = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';
    private const CACHE_FILE = ROOT_DIR . '/data/tlds.txt';

    private static ?array $tlds = null;

    /**
     * Whether $tld (already lowercased by the caller - URL's own host is
     * lowercased at construction) is a real, currently delegated TLD.
     */
    public static function isValid(string $tld): bool
    {
        return isset(self::loaded()[$tld]);
    }

    /**
     * Fetches the current list and replaces the cache. Called by
     * bin/refresh-lists.php on its weekly timer, and by bin/install.php so a
     * freshly cloned install has a working list before the first crawl.
     */
    public static function refresh(): bool
    {
        $connection = new HTTPConnection(new URL(self::SOURCE_URL));

        if ($connection -> statusCode !== 200) {
            return false;
        }

        $body = $connection -> readBody();

        // A truncated response or an error page would parse into a short list
        // of nonsense that quietly makes most of the web uncrawlable. IANA's
        // real file opens with a version comment and carries well over a
        // thousand TLDs.
        if (!str_starts_with($body, '#') || substr_count($body, chr(10)) < 100) {
            return false;
        }

        $directory = dirname(self::CACHE_FILE);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Written beside the real file and moved into place, so a reader in
        // another process never sees a half-written list - rename() is atomic
        // within a filesystem, file_put_contents() is not.
        $temporary = self::CACHE_FILE . '.' . getmypid();

        if (file_put_contents($temporary, $body) === false) {
            return false;
        }

        rename($temporary, self::CACHE_FILE);
        self::$tlds = null;

        return true;
    }

    public static function isCached(): bool
    {
        return is_file(self::CACHE_FILE);
    }

    public static function cacheAgeSeconds(): ?int
    {
        return self::isCached() ? time() - (int) filemtime(self::CACHE_FILE) : null;
    }

    private static function loaded(): array
    {
        return self::$tlds ??= self::readCache();
    }

    private static function readCache(): array
    {
        if (!is_file(self::CACHE_FILE)) {
            // Loud rather than empty: an empty set answers false for every
            // TLD there is, so every URL fails validation and the crawler
            // simply stops finding anything - a silent, near-unreadable
            // failure for what is really just a missing file.
            throw new \RuntimeException(
                'No TLD list cached at ' . self::CACHE_FILE . ' - run bin/refresh-lists.php'
            );
        }

        $tlds = [];

        foreach (file(self::CACHE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            // IANA's list opens with a "# Version ..., Last Updated ..."
            // comment line - everything else is one bare TLD per line.
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $tlds[strtolower($line)] = true;
        }

        return $tlds;
    }
}
