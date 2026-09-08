<?php

declare(strict_types=1);

final class SearchIndexQueue
{
    private const FULL = 1;
    private const PARTIAL = 2;

    /** @param int[] $item_ids */
    public static function record(array $item_ids, bool $sync_item, bool $sync_links): void
    {
        self::enqueue($item_ids, $sync_item ? self::FULL : 0, $sync_links ? self::FULL : 0);
    }

    /** @param int[] $item_ids */
    public static function recordCounts(array $item_ids): void
    {
        self::enqueue($item_ids, self::PARTIAL, 0);
    }

    /** @param int[] $item_ids */
    public static function recordOutgoingLinks(array $item_ids): void
    {
        self::enqueue($item_ids, 0, self::PARTIAL);
    }

    private static function enqueue(array $item_ids, int $sync_item, int $sync_links): void
    {
        $item_ids = array_values(array_unique(array_filter(array_map('intval', $item_ids), static fn (int $id): bool => $id > 0)));

        if ($item_ids === [] || (!$sync_item && !$sync_links)) {
            return;
        }

        foreach (array_chunk($item_ids, 200) as $chunk) {
            $rows = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $types = '';
            $values = [];

            foreach ($chunk as $item_id) {
                $values[] = $item_id;
                $values[] = $sync_item;
                $values[] = $sync_links;
                $types .= 'iii';
            }

            $insert = mysqli_prepare(Database::connection(), '
INSERT INTO `SearchIndexQueue` (`itemId`, `syncItem`, `syncLinks`)
    VALUES ' . $rows . '
    ON DUPLICATE KEY UPDATE
        `syncItem` = IF(`syncItem` = 1 OR VALUES(`syncItem`) = 1, 1, GREATEST(`syncItem`, VALUES(`syncItem`))),
        `syncLinks` = IF(`syncLinks` = 1 OR VALUES(`syncLinks`) = 1, 1, GREATEST(`syncLinks`, VALUES(`syncLinks`))),
        `generation` = `generation` + 1
');
            mysqli_stmt_bind_param($insert, $types, ...$values);
            mysqli_stmt_execute($insert);
        }
    }

    public static function processPending(int $limit = 20, bool $fail_on_error = false): int
    {
        // Every caller uses the same connection-owned lock, including the
        // timer. A crashed consumer releases it without leaving a stale lease.
        $lock = mysqli_query(Database::connection(), 'SELECT GET_LOCK(CONCAT(\'duskrail-index:\', DATABASE()), 0)');

        if ((int) mysqli_fetch_row($lock)[0] !== 1) {
            return 0;
        }

        try {
            return self::processLocked($limit, $fail_on_error);
        } finally {
            mysqli_query(Database::connection(), 'SELECT RELEASE_LOCK(CONCAT(\'duskrail-index:\', DATABASE()))');
        }
    }

    private static function processLocked(int $limit, bool $fail_on_error): int
    {
        $limit = max(1, min(1000, $limit));
        $result = mysqli_query(Database::connection(), '
SELECT `itemId`, `syncItem`, `syncLinks`, `generation`
    FROM `SearchIndexQueue`
    ORDER BY `itemId`
    LIMIT ' . $limit . '
');
        $rows = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $item_ids = [];
        $link_ids = [];
        $count_ids = [];
        $outgoing_ids = [];

        foreach ($rows as $row) {
            $item_id = (int) $row['itemId'];

            if ((int) $row['syncItem'] === self::FULL) {
                $item_ids[] = $item_id;
            } elseif ((int) $row['syncItem'] === self::PARTIAL) {
                $count_ids[] = $item_id;
            }

            if ((int) $row['syncLinks'] === self::FULL) {
                $link_ids[] = $item_id;
            } elseif ((int) $row['syncLinks'] === self::PARTIAL) {
                $outgoing_ids[] = $item_id;
            }
        }

        try {
            ItemSearchIndex::syncIds($item_ids);
            ItemSearchIndex::syncCounts($count_ids);
            LinkSearchIndex::syncItemIds($link_ids, $outgoing_ids);
        } catch (\Throwable $exception) {
            if ($fail_on_error) {
                throw $exception;
            }

            error_log('Search-index synchronization failed: ' . $exception -> getMessage());

            return 0;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $conditions = [];
            $values = [];

            foreach ($chunk as $row) {
                $conditions[] = '(`itemId` = ? AND `generation` = ?)';
                $values[] = (int) $row['itemId'];
                $values[] = (int) $row['generation'];
            }

            $delete = mysqli_prepare(Database::connection(), '
DELETE FROM `SearchIndexQueue`
    WHERE ' . implode(' OR ', $conditions) . '
');
            mysqli_stmt_bind_param($delete, str_repeat('ii', count($chunk)), ...$values);
            mysqli_stmt_execute($delete);
        }

        return count($rows);
    }

    public static function hasPending(): bool
    {
        $result = mysqli_query(Database::connection(), '
SELECT 1
    FROM `SearchIndexQueue`
    LIMIT 1
');

        return mysqli_fetch_row($result) !== null;
    }
}
