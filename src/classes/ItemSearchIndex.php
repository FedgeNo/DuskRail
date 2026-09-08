<?php

declare(strict_types=1);

final class ItemSearchIndex extends SearchIndex
{
    public const TABLE = 'duskrail_items';
    public const MARIA_SEARCHABLE_CONDITION = '
`crawledTime` IS NOT NULL
    AND `noindex` = 0
    AND (`type` IN (\'text/html\', \'application/xhtml+xml\', \'application/pdf\', \'text/plain\')
        OR `type` LIKE \'image/%\')
    AND (COALESCE(`title`, \'\') <> \'\'
        OR COALESCE(`description`, \'\') <> \'\'
        OR COALESCE(`fullText`, \'\') <> \'\')';

    /** @param int[] $item_ids */
    public static function syncIds(array $item_ids): void
    {
        $item_ids = self::normalizedIds($item_ids);

        if ($item_ids === []) {
            return;
        }

        $placeholders = self::placeholders(count($item_ids));
        $select = mysqli_prepare(Database::connection(), '
SELECT `itemId`, `type`, `title`, `description`, `fullText`, `inc`
    FROM `Items`
    WHERE `itemId` IN (' . $placeholders . ')
        AND ' . self::MARIA_SEARCHABLE_CONDITION . '
');
        mysqli_stmt_bind_param($select, str_repeat('i', count($item_ids)), ...$item_ids);
        mysqli_stmt_execute($select);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);

        self::upsertRows($rows);

        $present_ids = array_map(static fn (array $row): int => (int) $row['itemId'], $rows);
        $missing_ids = array_values(array_diff($item_ids, $present_ids));

        if ($missing_ids !== []) {
            self::run(
                'DELETE FROM ' . self::TABLE . ' WHERE id IN (' . self::placeholders(count($missing_ids)) . ')',
                str_repeat('i', count($missing_ids)),
                ...$missing_ids
            );
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function upsertRows(array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $tuples = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'));
            $types = '';
            $values = [];

            foreach ($chunk as $row) {
                $values[] = (int) $row['itemId'];
                $values[] = (int) $row['inc'];
                $values[] = str_starts_with((string) $row['type'], 'image/') ? 1 : 0;
                $values[] = (string) ($row['title'] ?? '');
                $values[] = (string) ($row['description'] ?? '');
                $values[] = (string) ($row['fullText'] ?? '');
                $types .= 'iiisss';
            }

            self::run(
                'REPLACE INTO ' . self::TABLE . ' (id, inc, isimage, title, description, fulltext) VALUES ' . $tuples,
                $types,
                ...$values
            );
        }
    }

    /** @param int[] $item_ids */
    public static function syncCounts(array $item_ids): void
    {
        $item_ids = self::normalizedIds($item_ids);

        if ($item_ids === []) {
            return;
        }

        $placeholders = self::placeholders(count($item_ids));
        $select = mysqli_prepare(Database::connection(), '
SELECT `itemId`, `inc`, `title`, `description`
    FROM `Items`
    WHERE `itemId` IN (' . $placeholders . ')
        AND ' . self::MARIA_SEARCHABLE_CONDITION . '
');
        mysqli_stmt_bind_param($select, str_repeat('i', count($item_ids)), ...$item_ids);
        mysqli_stmt_execute($select);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
        $indexed = [];

        foreach (self::rows(
            'SELECT id, title, description FROM ' . self::TABLE . ' WHERE id IN (' . $placeholders . ') LIMIT ' . count($item_ids),
            str_repeat('i', count($item_ids)),
            ...$item_ids
        ) as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        $full_ids = array_fill_keys($item_ids, true);
        $counts = [];

        foreach ($rows as $row) {
            $item_id = (int) $row['itemId'];

            // Discovery may also fill an empty title or description. Keep
            // that change, and recreate documents missing from the index.
            if (!isset($indexed[$item_id])
                || (string) ($row['title'] ?? '') !== (string) $indexed[$item_id]['title']
                || (string) ($row['description'] ?? '') !== (string) $indexed[$item_id]['description']
            ) {
                continue;
            }

            unset($full_ids[$item_id]);
            $counts[(int) $row['inc']][] = $item_id;
        }

        foreach ($counts as $count => $ids) {
            self::run(
                'UPDATE ' . self::TABLE . ' SET inc = ? WHERE id IN (' . self::placeholders(count($ids)) . ')',
                'i' . str_repeat('i', count($ids)),
                $count,
                ...$ids
            );
        }

        self::syncIds(array_keys($full_ids));
    }

    /** @return array<int, array{itemId: int, inc: int, relevance: float}> */
    public static function candidates(string $query, bool $images, int $limit): array
    {
        $match = self::matchExpression($query);

        if ($match === '') {
            return [];
        }

        $limit = max(1, min(10000, $limit));
        $rows = self::rows(
            'SELECT id, inc, WEIGHT() AS relevance FROM ' . self::TABLE
                . ' WHERE MATCH(?) AND isimage = ?'
                . ' ORDER BY relevance DESC, inc DESC, id ASC LIMIT ' . $limit
                . ' OPTION max_matches=' . $limit . ', field_weights=(title=10,description=3,fulltext=1)',
            'si',
            $match,
            $images ? 1 : 0
        );
        $candidates = [];

        foreach ($rows as $row) {
            $item_id = (int) $row['id'];
            $candidates[$item_id] = [
                'itemId' => $item_id,
                'inc' => (int) $row['inc'],
                'relevance' => (float) $row['relevance'],
            ];
        }

        return $candidates;
    }

    public static function clear(): void
    {
        self::run('TRUNCATE TABLE ' . self::TABLE);
    }

    /** @param int[] $ids */
    private static function normalizedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
