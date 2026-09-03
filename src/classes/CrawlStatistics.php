<?php

declare(strict_types=1);

/**
 * The exact crawl catalogue counters maintained transactionally beside the
 * rows whose state they describe.
 */
class CrawlStatistics
{
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
        $result = mysqli_query(Database::connection(), '
SELECT `found`, `indexed`, `searchable`, `queued`, `pages`, `images`, `hosts`, `dead`
    FROM `CrawlCounters`
    WHERE `counterId` = 1 AND `initializedAt` IS NOT NULL
');
        $row = mysqli_fetch_assoc($result);

        if ($row !== null) {
            $this -> hydrate($row);
        }
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
