<?php

declare(strict_types=1);

/**
 * The one-line "what's in here" shown under the search box when there's no
 * query yet - a search page with an empty middle says nothing about whether
 * the engine holds ten pages or ten million, and that's the first thing a
 * visitor wonders.
 *
 * The counts are cached in Settings and recomputed at most once per
 * TTL_SECONDS: counting means reading an index entry for every crawled row,
 * which is nothing at thousands and a real scan at millions - and this sits
 * on the public home page, where "per visitor" multiplies whatever it costs.
 * Sixty seconds of staleness on a decorative count is invisible; the cache
 * refresh costs one visitor per minute the price everyone used to pay.
 */
class IndexStats extends HTMLObject
{
    private const CACHE_SETTING = 'indexStatsCache';
    private const TTL_SECONDS = 60;

    public ?string $class = 'IndexStats mt-3';

    public int $pages = 0;
    public int $images = 0;

    public function __construct()
    {
        parent::__construct();

        $cached = json_decode((string) Setting::value(self::CACHE_SETTING), true);

        if (is_array($cached) && time() - (int) ($cached['computedTime'] ?? 0) < self::TTL_SECONDS) {
            $this -> pages = (int) $cached['pages'];
            $this -> images = (int) $cached['images'];

            return;
        }

        $result = mysqli_query(Database::connection(), '
SELECT SUM(`type` NOT LIKE \'image/%\') AS `pages`, SUM(`type` LIKE \'image/%\') AS `images`
    FROM `Items`
    WHERE `crawledTime` IS NOT NULL
');
        $row = mysqli_fetch_assoc($result);

        $this -> pages = (int) $row['pages'];
        $this -> images = (int) $row['images'];

        Setting::store(self::CACHE_SETTING, json_encode([
            'pages' => $this -> pages,
            'images' => $this -> images,
            'computedTime' => time(),
        ]));
    }

    public function toDOM(): \DOMElement
    {
        $this -> addContent('Searching ' . number_format($this -> pages) . ' pages and ' . number_format($this -> images) . ' images.');

        return parent::toDOM();
    }
}
