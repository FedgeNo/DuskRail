<?php

declare(strict_types=1);

/**
 * A cached snapshot of the crawl catalogue. Counting the Items index is
 * useful operationally but expensive enough at scale that neither the public
 * index summary nor a watching administrator should do it per request.
 */
class CrawlStatistics
{
    private const CACHE_SETTING = 'crawlStatisticsCache';
    public int $found = 0;
    public int $indexed = 0;
    public int $searchable = 0;
    public int $queued = 0;
    public int $pages = 0;
    public int $images = 0;
    public int $hosts = 0;
    public int $dead = 0;

    public function __construct()
    {
        $cached = json_decode((string) Setting::value(self::CACHE_SETTING), true);

        if (is_array($cached)) {
            $this -> hydrate($cached);

            return;
        }

        $result = mysqli_query(Database::connection(), '
SELECT COUNT(*) AS `found`,
        COUNT(`crawledTime`) AS `indexed`,
        SUM(`crawledTime` IS NOT NULL AND `noindex` = 0) AS `searchable`,
        SUM(`crawledTime` IS NULL) AS `queued`,
        SUM(`crawledTime` IS NOT NULL AND `type` NOT LIKE \'image/%\') AS `pages`,
        SUM(`crawledTime` IS NOT NULL AND `type` LIKE \'image/%\') AS `images`,
        (SELECT COUNT(*) FROM `Hosts`) AS `hosts`,
        (SELECT COUNT(*) FROM `DeadURLs`) AS `dead`
    FROM `Items` FORCE INDEX (`crawledTime_itemId_type_noindex`)
');
        $row = mysqli_fetch_assoc($result);
        $row['computedTime'] = time();
        $this -> hydrate($row);

        Setting::store(self::CACHE_SETTING, json_encode($row));
    }

    private function hydrate(array $row): void
    {
        $this -> found = (int) $row['found'];
        $this -> indexed = (int) $row['indexed'];
        $this -> searchable = (int) $row['searchable'];
        $this -> queued = (int) $row['queued'];
        $this -> pages = (int) $row['pages'];
        $this -> images = (int) $row['images'];
        $this -> hosts = (int) $row['hosts'];
        $this -> dead = (int) $row['dead'];
    }

    public function toJSON(): array
    {
        return [
            'found' => $this -> found,
            'indexed' => $this -> indexed,
            'searchable' => $this -> searchable,
            'queued' => $this -> queued,
            'pages' => $this -> pages,
            'images' => $this -> images,
            'hosts' => $this -> hosts,
            'dead' => $this -> dead,
        ];
    }
}
