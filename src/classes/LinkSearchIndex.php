<?php

declare(strict_types=1);

final class LinkSearchIndex extends SearchIndex
{
    public const TABLE = 'duskrail_links';

    /** @param int[] $item_ids */
    public static function syncItemIds(array $item_ids): void
    {
        $item_ids = array_values(array_unique(array_filter(array_map('intval', $item_ids), static fn (int $id): bool => $id > 0)));

        if ($item_ids === []) {
            return;
        }

        $placeholders = self::placeholders(count($item_ids));
        self::run(
            'DELETE FROM ' . self::TABLE . ' WHERE parentid IN (' . $placeholders . ') OR childid IN (' . $placeholders . ')',
            str_repeat('i', count($item_ids) * 2),
            ...array_merge($item_ids, $item_ids)
        );

        $select = mysqli_prepare(Database::connection(), '
SELECT `Links`.`parentId`, `Links`.`childId`, `Links`.`description`,
        `ParentHosts`.`domain`, (`ParentHosts`.`domain` <> `ChildHosts`.`domain`) AS `external`
    FROM `Links`
    INNER JOIN `Items` AS `ParentItems` ON `ParentItems`.`itemId` = `Links`.`parentId`
    INNER JOIN `Hosts` AS `ParentHosts` ON `ParentHosts`.`hostId` = `ParentItems`.`hostId`
    INNER JOIN `Items` AS `ChildItems` ON `ChildItems`.`itemId` = `Links`.`childId`
    INNER JOIN `Hosts` AS `ChildHosts` ON `ChildHosts`.`hostId` = `ChildItems`.`hostId`
    WHERE `Links`.`parentId` IN (' . $placeholders . ')
        OR `Links`.`childId` IN (' . $placeholders . ')
');
        $values = array_merge($item_ids, $item_ids);
        mysqli_stmt_bind_param($select, str_repeat('i', count($values)), ...$values);
        mysqli_stmt_execute($select);
        self::upsertRows(mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC));
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function upsertRows(array $rows): void
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            if ($chunk === []) {
                continue;
            }

            $tuples = implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?)'));
            $types = '';
            $values = [];

            foreach ($chunk as $row) {
                $parent_id = (int) $row['parentId'];
                $child_id = (int) $row['childId'];
                $values[] = self::documentId($parent_id, $child_id);
                $values[] = $parent_id;
                $values[] = $child_id;
                $values[] = (string) $row['domain'];
                $values[] = (int) $row['external'];
                $values[] = (string) ($row['description'] ?? '');
                $types .= 'iiisis';
            }

            self::run(
                'REPLACE INTO ' . self::TABLE . ' (id, parentid, childid, domain, external, description) VALUES ' . $tuples,
                $types,
                ...$values
            );
        }
    }

    /** @param int[] $item_ids @return array<int, int> */
    public static function matches(string $query, array $item_ids): array
    {
        $match = self::matchExpression($query);

        if ($match === '' || $item_ids === []) {
            return [];
        }

        $rows = self::rows(
            'SELECT childid, COUNT(DISTINCT domain) AS linkmatches FROM ' . self::TABLE
                . ' WHERE MATCH(?) AND external = 1 AND childid IN (' . self::placeholders(count($item_ids)) . ')'
                . ' GROUP BY childid LIMIT ' . count($item_ids)
                . ' OPTION max_matches=' . max(1000, count($item_ids)) . ', accurate_aggregation=1',
            's' . str_repeat('i', count($item_ids)),
            $match,
            ...$item_ids
        );
        $matches = [];

        foreach ($rows as $row) {
            $matches[(int) $row['childid']] = (int) $row['linkmatches'];
        }

        return $matches;
    }

    /** @return array<int, float> */
    public static function focusedCandidates(string $query, int $limit): array
    {
        $match = self::matchExpression($query);

        if ($match === '') {
            return [];
        }

        $limit = max(1, min(10000, $limit));
        $rows = self::rows(
            'SELECT childid, MAX(WEIGHT()) AS score FROM ' . self::TABLE
                . ' WHERE MATCH(?) GROUP BY childid ORDER BY score DESC, childid ASC LIMIT ' . $limit
                . ' OPTION max_matches=' . $limit,
            's',
            $match
        );
        $candidates = [];

        foreach ($rows as $row) {
            $candidates[(int) $row['childid']] = (float) $row['score'];
        }

        return $candidates;
    }

    public static function clear(): void
    {
        self::run('TRUNCATE TABLE ' . self::TABLE);
    }

    private static function documentId(int $parent_id, int $child_id): int
    {
        if ($parent_id > 2147483647) {
            throw new \OverflowException('A link parent id cannot be packed into a signed 64-bit Manticore document id.');
        }

        return $parent_id * 4294967296 + $child_id;
    }
}
