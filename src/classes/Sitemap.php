<?php

declare(strict_types=1);

/**
 * Feeds a host's sitemaps into the crawl queue. robots.txt's Sitemap: lines
 * are where sites publish the URLs they actually want indexed - far better
 * discovery than hoping every page is reachable by link-walking, and the
 * only way pages nothing links to yet ever get found.
 *
 * Runs when the host's robots.txt is freshly (re)read - weekly, per Host's
 * own schedule - so a site's sitemap is revisited on the same cadence its
 * crawl rules are. Each sitemap fetch is a real request against the host and
 * counts against its politeness cooldown accordingly.
 *
 * Bounded on purpose: a handful of fetches, one level of sitemap-index
 * nesting, and a cap on URLs taken. Sitemap files legally hold 50,000 URLs
 * each and index files nest them - and Host::MAX_PENDING_ITEMS means this
 * host can't queue more than a fraction of that anyway, so reading past the
 * cap would be pure waste.
 */
class Sitemap
{
    private const MAX_FETCHES = 3;
    private const MAX_URLS = 500;

    /**
     * Reads the sitemaps $robotsTxt declares and queues what they list.
     * Returns how many URLs were newly queued.
     */
    public static function ingestFor(Host $host, string $robotsTxt): int
    {
        $queued = 0;
        $fetches = 0;
        $pending = self::declaredSitemapURLs($robotsTxt, $host);

        while ($pending !== [] && $fetches < self::MAX_FETCHES && $queued < self::MAX_URLS) {
            $sitemapURL = array_shift($pending);
            $fetches++;

            $connection = new HTTPConnection($sitemapURL);
            $body = $connection -> readBody();
            $host -> recordCrawl($connection -> statusCode !== null && in_array($connection -> statusCode, [429, 503], true));

            if ($connection -> statusCode === null || $connection -> statusCode < 200 || $connection -> statusCode >= 300) {
                continue;
            }

            // Sitemaps are routinely published gzipped (sitemap.xml.gz) -
            // that's compression of the file itself, not transport encoding,
            // so curl hands the compressed bytes through untouched.
            if (str_starts_with($body, chr(0x1F) . chr(0x8B))) {
                $decoded = @gzdecode($body);
                $body = $decoded !== false ? $decoded : '';
            }

            // An index file's entries are more sitemaps, not pages - they
            // join the fetch queue instead of the crawl queue.
            $isIndex = self::isSitemapIndex($body);

            foreach (self::locations($body) as $location) {
                $url = new URL($location);

                // Only URLs on this same host. The spec requires it, and
                // without the check a sitemap would be a way to inject
                // arbitrary third-party URLs into the queue wholesale.
                if (!$url -> isValid() || $url -> host !== $host -> host) {
                    continue;
                }

                if ($isIndex) {
                    $pending[] = $url;
                    continue;
                }

                if ($queued >= self::MAX_URLS) {
                    break;
                }

                if (Item::findOrCreateByURL($url, 'unknown') !== null) {
                    $queued++;
                }
            }
        }

        return $queued;
    }

    /**
     * The Sitemap: lines in $robotsTxt. Per the spec these are global -
     * they belong to no User-agent group - so this is a plain line scan.
     * Only sitemaps on the host itself (or trivially resolvable to it) are
     * kept, for the same injection reason locations are host-checked.
     *
     * @return list<URL>
     */
    private static function declaredSitemapURLs(string $robotsTxt, Host $host): array
    {
        $urls = [];

        foreach (preg_split('/\r\n|\r|\n/', $robotsTxt) as $line) {
            $line = trim($line);

            if (stripos($line, 'sitemap') !== 0 || !str_contains($line, ':')) {
                continue;
            }

            $url = new URL(trim(explode(':', $line, 2)[1]));

            if ($url -> isValid() && $url -> host === $host -> host) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function isSitemapIndex(string $body): bool
    {
        return stripos($body, '<sitemapindex') !== false;
    }

    /**
     * Every <loc> value in a sitemap document. A cursor scan rather than an
     * XML parse: this is untrusted XML off the open web, and all that's
     * wanted from it is the text between two fixed tags - no reason to hand
     * it to a parser with entity expansion and the rest of the attack
     * surface that comes along.
     *
     * @return list<string>
     */
    private static function locations(string $body): array
    {
        $locations = [];
        $cursor = 0;

        while (($start = stripos($body, '<loc>', $cursor)) !== false) {
            $start += strlen('<loc>');
            $end = stripos($body, '</loc>', $start);

            if ($end === false) {
                break;
            }

            $locations[] = html_entity_decode(trim(substr($body, $start, $end - $start)), ENT_QUOTES | ENT_XML1);
            $cursor = $end + strlen('</loc>');

            if (count($locations) >= self::MAX_URLS * 2) {
                break;
            }
        }

        return $locations;
    }
}
