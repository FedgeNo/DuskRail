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
 * URL::isValid() uses this to require every crawled URL to have a real TLD -
 * this project's whole approach to SSRF hardening: no IP literal (IPv4 or
 * IPv6) has a real TLD as its last dot-separated label, so requiring one
 * rejects those along with single-label internal hostnames
 * ("http://fileserver/") and made-up internal-only suffixes
 * ("http://app.corp/", "http://db.local/") in one move, without needing a
 * separate, dedicated IP-literal check at all.
 */
class TLDs
{
    private const SOURCE_URL = 'https://data.iana.org/TLD/tlds-alpha-by-domain.txt';
    private const CACHE_FILE = ROOT_DIR . '/data/tlds.txt';

    // IANA updates the real list only occasionally (a handful of times a
    // year, almost always additions) - refetching weekly is comfortably more
    // often than it actually changes, while keeping even a long-running
    // crawler process from ever running on a badly stale copy.
    private const MAX_AGE_SECONDS = 7 * 24 * 60 * 60;

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
     * Fetches a fresh copy right now if the cache is missing/stale. Called
     * directly by bin/install.php so a freshly cloned install has a working
     * list before the first crawl ever runs, rather than only discovering
     * the gap the first time URL::isValid() happens to need it.
     */
    public static function warm(): void
    {
        self::refreshIfStale();
    }

    private static function loaded(): array
    {
        if (self::$tlds === null) {
            self::refreshIfStale();
            self::$tlds = self::readCache();
        }

        return self::$tlds;
    }

    private static function refreshIfStale(): void
    {
        $age = is_file(self::CACHE_FILE) ? time() - filemtime(self::CACHE_FILE) : PHP_INT_MAX;

        if ($age < self::MAX_AGE_SECONDS) {
            return;
        }

        $connection = new HTTPConnection(new URL(self::SOURCE_URL));

        if ($connection -> statusCode === null || $connection -> statusCode < 200 || $connection -> statusCode >= 300) {
            // Fetch failed (network hiccup, IANA temporarily unreachable) -
            // leave whatever cache already exists in place (however stale)
            // rather than clearing it. readCache() falling back to an empty
            // set would fail every single URL closed instead of just running
            // on a slightly-older-than-a-week list a little longer.
            return;
        }

        $body = $connection -> readBody();
        $directory = dirname(self::CACHE_FILE);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(self::CACHE_FILE, $body);
    }

    private static function readCache(): array
    {
        if (!is_file(self::CACHE_FILE)) {
            return [];
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
