<?php

declare(strict_types=1);

final class InteractiveRetrieval {
    public const TIMEOUT_SECONDS = 60;
    public ?int $requestItemId = null;
    public ?int $itemId = null;
    public ?int $deadline = null;
    public ?string $failure = null;

    public static function start(Item $item): self {
        $deadline = time() + self::TIMEOUT_SECONDS;
        $stmt = Database::connection() -> prepare('
INSERT INTO `InteractiveRetrievals` (`requestItemId`, `itemId`, `deadline`, `failure`)
    VALUES (?, ?, ?, NULL)
    ON DUPLICATE KEY UPDATE `itemId` = VALUES(`itemId`), `deadline` = VALUES(`deadline`), `failure` = NULL
');
        $stmt -> bind_param('iii', $item -> itemId, $item -> itemId, $deadline);
        $stmt -> execute();
        return self::find($item -> itemId);
    }

    public static function find(int $id): ?self {
        $stmt = Database::connection() -> prepare('SELECT * FROM `InteractiveRetrievals` WHERE `requestItemId` = ?');
        $stmt -> bind_param('i', $id);
        $stmt -> execute();
        return $stmt -> get_result() -> fetch_object(self::class) ?: null;
    }

    public static function activeForItem(int $id): ?self {
        $stmt = Database::connection() -> prepare('SELECT * FROM `InteractiveRetrievals` WHERE `itemId` = ? AND `failure` IS NULL ORDER BY `deadline` LIMIT 1');
        $stmt -> bind_param('i', $id);
        $stmt -> execute();
        return $stmt -> get_result() -> fetch_object(self::class) ?: null;
    }

    public static function redirect(int $source_id, int $target_id): void {
        $stmt = Database::connection() -> prepare('UPDATE `InteractiveRetrievals` SET `itemId` = ? WHERE `itemId` = ?');
        $stmt -> bind_param('ii', $target_id, $source_id);
        $stmt -> execute();
    }

    public static function expire(): void {
        $rows = Database::connection() -> query('
SELECT STRAIGHT_JOIN `InteractiveRetrievals`.*
    FROM `Items` FORCE INDEX (`crawlPriority_itemId`)
    INNER JOIN `InteractiveRetrievals` USING (`itemId`)
    WHERE `Items`.`crawlPriority` > 0 AND `InteractiveRetrievals`.`failure` IS NULL
        AND `InteractiveRetrievals`.`deadline` <= UNIX_TIMESTAMP()
    LIMIT 100
');
        while ($request = $rows -> fetch_object(self::class)) {
            if ($request -> state(Item::findById($request -> itemId), time()) === 'failed') {
                $request -> fail('Interactive retrieval exceeded its 60-second deadline.');
            }
        }
    }

    public function state(?Item $item, int $now): string {
        if ($this -> failure !== null) return 'failed';
        if ($item !== null && $item -> crawledTime !== null && $item -> crawledTime <= $this -> deadline) return 'available';
        if ($now >= $this -> deadline || $item === null) return 'failed';
        return $item -> claimedUntil !== null && $item -> claimedUntil > $now ? 'reading' : 'queued';
    }

    public function fail(string $reason): void {
        $stmt = Database::connection() -> prepare('UPDATE `InteractiveRetrievals` SET `failure` = ? WHERE `requestItemId` = ? AND `deadline` = ? AND `failure` IS NULL');
        $stmt -> bind_param('sii', $reason, $this -> requestItemId, $this -> deadline);
        $stmt -> execute();
        $this -> failure = $reason;
        // Keep the URL and any captured content; only end its interactive priority.
        $stmt = Database::connection() -> prepare('
UPDATE `Items` SET `crawlPriority` = 0 WHERE `itemId` = ?
    AND NOT EXISTS (SELECT 1 FROM `InteractiveRetrievals` WHERE `itemId` = ? AND `failure` IS NULL AND `deadline` > UNIX_TIMESTAMP())
');
        $stmt -> bind_param('ii', $this -> itemId, $this -> itemId);
        $stmt -> execute();
    }

    public static function workerFinished(int $request_id, int $deadline, string $reason): void {
        $request = self::find($request_id);
        if ($request === null || $request -> deadline !== $deadline) return;
        if ($request -> state(Item::findById($request -> itemId), time()) !== 'available') $request -> fail($reason);
    }
}
