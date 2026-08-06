<?php

declare(strict_types=1);

/**
 * Whether the crawler is running, and how much it's getting done - what the
 * watch page shows above the feed, since a silent feed is otherwise
 * indistinguishable from a stopped crawler.
 *
 * "Running" means bin/crawler-manager.php's heartbeat Setting is fresh. The
 * threshold is a multiple of how often the manager refreshes it, so one
 * delayed write doesn't read as a crash.
 */
class CrawlerStatus
{
    private const STALE_AFTER_SECONDS = 15;

    public bool $running = false;
    public ?int $lastHeartbeatTime = null;
    public int $crawledLastHour = 0;

    public function __construct()
    {
        $heartbeat = Setting::value(CRAWLER_HEARTBEAT_SETTING);
        $this -> lastHeartbeatTime = $heartbeat !== null ? (int) $heartbeat : null;
        $this -> running = $this -> lastHeartbeatTime !== null && time() - $this -> lastHeartbeatTime <= self::STALE_AFTER_SECONDS;

        $select = mysqli_prepare(Database::connection(), '
SELECT COUNT(*) AS `crawledLastHour`
    FROM `Items`
    WHERE `crawledTime` >= ?
');
        $since = time() - 3600;
        mysqli_stmt_bind_param($select, 'i', $since);
        mysqli_stmt_execute($select);

        $this -> crawledLastHour = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($select))['crawledLastHour'];
    }

    public function toJSON(): array
    {
        return [
            'running' => $this -> running,
            'lastHeartbeatTime' => $this -> lastHeartbeatTime,
            'crawledLastHour' => $this -> crawledLastHour,
        ];
    }
}
