<?php

declare(strict_types=1);

/**
 * The Public Suffix List - Mozilla's catalogue of every suffix under which
 * the public can register a name. It's what makes "the same owner" a
 * question with an answer: "bbc.co.uk" and "theguardian.co.uk" are two
 * organisations, while "a.evil.com" and "b.evil.com" are one, and nothing
 * about the shape of those four strings says so.
 *
 * Search ranking needs that distinction. It counts how many distinct
 * *domains* link to a result, and counting hostnames instead let one
 * registered domain with a wildcard DNS record mint unlimited "distinct"
 * linkers - a.evil.com, b.evil.com, ... - and push anything it liked up the
 * results for free. Registering a domain is the one step in that plan that
 * costs money, so the registrable domain is the unit worth counting.
 *
 * Only the ICANN section is read; the list's private section (github.io,
 * blogspot.com, the cloud providers' per-customer suffixes) is deliberately
 * skipped. Under the private rules every user of a hosting platform is a
 * separate domain, which is exactly the cheap multiplicity being defended
 * against here - collapsing a platform's users into one linker costs a real
 * signal occasionally, and the alternative hands the attack straight back.
 */
class PublicSuffixList
{
    private const SOURCE_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';
    private const CACHE_FILE = ROOT_DIR . '/data/public-suffix-list.dat';

    // Where the part of the list this project uses ends. Everything after it
    // is the privately-registered section - see the class docblock.
    private const PRIVATE_SECTION_MARKER = '// ===BEGIN PRIVATE DOMAINS===';

    /** @var array<string, string>|null suffix => 'normal'|'wildcard'|'exception' */
    private static ?array $rules = null;

    /**
     * The registrable domain of $host - its public suffix plus the one label
     * in front of it ("www.bbc.co.uk" -> "bbc.co.uk"). Per the list's own
     * algorithm: among the rules that match, an exception rule wins, then the
     * one with the most labels; a host that matches nothing is treated as
     * having a single-label suffix.
     *
     * Falls back to $host itself when there's no label in front of the suffix
     * (a bare "co.uk", which nobody can register and nothing should be
     * crawled at) - the answer is still the narrowest true statement
     * available about who owns it, and never a suffix that would lump
     * unrelated sites together.
     */
    public static function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host, '.'));

        if ($host === '') {
            return '';
        }

        $labels = explode('.', $host);
        $suffixLabelCount = self::suffixLabelCount($labels);

        if (count($labels) <= $suffixLabelCount) {
            return $host;
        }

        return implode('.', array_slice($labels, count($labels) - $suffixLabelCount - 1));
    }

    /**
     * How many of $labels, counted from the right, form the public suffix.
     *
     * @param list<string> $labels
     */
    private static function suffixLabelCount(array $labels): int
    {
        $rules = self::loaded();
        $longestMatch = 0;

        // Walked right to left, one label at a time, so each step tests the
        // suffix one label longer than the last: "uk", then "co.uk", and so
        // on. There are only ever as many candidates as the host has labels,
        // however large the list itself is.
        for ($start = count($labels) - 1; $start >= 0; $start--) {
            $candidate = implode('.', array_slice($labels, $start));
            $type = $rules[$candidate] ?? null;

            // An exception rule ("!city.kawasaki.jp") wins outright over
            // every other match, and names a suffix one label shorter than
            // itself - it exists precisely to carve its own name back out of
            // a wildcard.
            if ($type === 'exception') {
                return count($labels) - $start - 1;
            }

            if ($type === 'normal') {
                $longestMatch = count($labels) - $start;
                continue;
            }

            // A wildcard rule ("*.ck") is stored under its parent, and
            // matches when there's a label available to stand in for the "*".
            if ($rules['*.' . $candidate] ?? null) {
                $longestMatch = max($longestMatch, count($labels) - $start + 1);
            }
        }

        // "If no rules match, the prevailing rule is *" - one label, which is
        // also what an unknown new TLD should be treated as.
        return max(1, min($longestMatch, count($labels)));
    }

    private static function loaded(): array
    {
        return self::$rules ??= self::readCache();
    }

    /**
     * Fetches the current list and replaces the cache. Called by
     * bin/refresh-lists.php (and bin/install.php, so a fresh checkout has one
     * before its first crawl) - never on demand from a crawl or a request,
     * which is what keeps a scheduled maintenance job the only thing that
     * ever waits on publicsuffix.org.
     */
    public static function refresh(): bool
    {
        $connection = new HTTPConnection(new URL(self::SOURCE_URL));

        if ($connection -> statusCode !== 200) {
            return false;
        }

        $body = $connection -> readBody();

        // A truncated or error-page response would parse into a handful of
        // junk rules and quietly change what every domain resolves to, which
        // is worse than keeping last week's copy. The real list is over
        // 200 KiB and has always had these markers.
        if (!str_contains($body, self::PRIVATE_SECTION_MARKER)) {
            return false;
        }

        $directory = dirname(self::CACHE_FILE);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Written beside the real file and moved into place, so a reader in
        // another process never sees a half-written list.
        $temporary = self::CACHE_FILE . '.' . getmypid();

        if (file_put_contents($temporary, $body) === false) {
            return false;
        }

        rename($temporary, self::CACHE_FILE);
        self::$rules = null;

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

    /**
     * @return array<string, string>
     */
    private static function readCache(): array
    {
        if (!is_file(self::CACHE_FILE)) {
            // Loud rather than empty: with no list, every host would answer
            // as its own registrable domain and the link signal would go back
            // to being one an attacker can mint at will - a silent, invisible
            // downgrade of the exact thing this class exists to do.
            throw new \RuntimeException(
                'No Public Suffix List cached at ' . self::CACHE_FILE . ' - run bin/refresh-lists.php'
            );
        }

        $rules = [];

        foreach (file(self::CACHE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === self::PRIVATE_SECTION_MARKER) {
                break;
            }

            // "//" opens a comment; the list has no inline ones, so a line
            // either is a comment or is a rule.
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }

            if (str_starts_with($line, '!')) {
                $rules[substr($line, 1)] = 'exception';
                continue;
            }

            $rules[$line] = str_starts_with($line, '*.') ? 'wildcard' : 'normal';
        }

        return $rules;
    }
}
