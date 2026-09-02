<?php

declare(strict_types=1);

final class SearchIndexQueue
{
    /** @param int[] $item_ids */
    public static function record(array $item_ids, bool $sync_item, bool $sync_links): void
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
                $values[] = $sync_item ? 1 : 0;
                $values[] = $sync_links ? 1 : 0;
                $types .= 'iii';
            }

            $insert = mysqli_prepare(Database::connection(), '
INSERT INTO `SearchIndexQueue` (`itemId`, `syncItem`, `syncLinks`)
    VALUES ' . $rows . '
    ON DUPLICATE KEY UPDATE
        `syncItem` = GREATEST(`syncItem`, VALUES(`syncItem`)),
        `syncLinks` = GREATEST(`syncLinks`, VALUES(`syncLinks`)),
        `generation` = `generation` + 1
');
            mysqli_stmt_bind_param($insert, $types, ...$values);
            mysqli_stmt_execute($insert);
        }
    }

    public static function processPending(int $limit = 20, bool $fail_on_error = false): int
    {
        $limit = max(1, min(1000, $limit));
        $result = mysqli_query(Database::connection(), '
SELECT `itemId`, `syncItem`, `syncLinks`, `generation`
    FROM `SearchIndexQueue`
    ORDER BY `itemId`
    LIMIT ' . $limit . '
');
        $processed = 0;

        foreach (mysqli_fetch_all($result, MYSQLI_ASSOC) as $row) {
            $item_id = (int) $row['itemId'];

            try {
                if ((int) $row['syncItem'] === 1) {
                    ItemSearchIndex::syncIds([$item_id]);
                }

                if ((int) $row['syncLinks'] === 1) {
                    LinkSearchIndex::syncItemIds([$item_id]);
                }
            } catch (\Throwable $exception) {
                if ($fail_on_error) {
                    throw $exception;
                }

                error_log('Search-index synchronization failed: ' . $exception -> getMessage());
                break;
            }

            $delete = mysqli_prepare(Database::connection(), '
DELETE FROM `SearchIndexQueue`
    WHERE `itemId` = ? AND `generation` = ?
');
            $generation = (int) $row['generation'];
            mysqli_stmt_bind_param($delete, 'ii', $item_id, $generation);
            mysqli_stmt_execute($delete);
            $processed++;
        }

        return $processed;
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
