<?php

declare(strict_types=1);

final class LinkSearchIndex extends SearchIndex {
    public const TABLE = 'duskrail_links';
    private const CACHE_SETTING = 'focusedCrawlCandidates';
    private static bool $mutating = false;

    /** @param int[] $item_ids */
    public static function syncItemIds(array $item_ids, array $outgoing_ids = []): void {
        if ($item_ids !== [] || $outgoing_ids !== []) {
            self::mutate(static fn () => self::syncLinks($item_ids, $outgoing_ids));
        }
    }

    private static function syncLinks(array $item_ids, array $outgoing_ids): void {
        $item_ids = array_values(array_unique(array_filter(array_map('intval', $item_ids), static fn (int $id): bool => $id > 0)));
        $parent_ids = array_values(array_unique(array_filter(array_map('intval', array_merge($item_ids, $outgoing_ids)), static fn (int $id): bool => $id > 0)));
        if ($parent_ids === []) {
            return;
        }

        $revision = Setting::value('linkDomainRevision');
        if ($revision === null || !ctype_digit($revision)) {
            throw new SearchIndexUnavailable('Link-domain revision is missing or invalid; run the schema migration.');
        }

        // A domain change anywhere may affect incoming-edge metadata. Only
        // skip those edges after this item has had a full refresh at the
        // current revision; NULL also covers pre-migration items.
        $select = mysqli_prepare(Database::connection(), '
SELECT `Items`.`itemId`
    FROM `Items`
    LEFT JOIN `LinkIndexRevisions` ON `LinkIndexRevisions`.`itemId` = `Items`.`itemId`
    WHERE `Items`.`itemId` IN (' . self::placeholders(count($parent_ids)) . ')
        AND (`LinkIndexRevisions`.`domainRevision` IS NULL OR `LinkIndexRevisions`.`domainRevision` <> ?)
');
        $values = array_merge($parent_ids, [$revision]);
        mysqli_stmt_bind_param($select, str_repeat('i', count($parent_ids)) . 's', ...$values);
        mysqli_stmt_execute($select);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC) as $row) {
            $item_ids[] = (int) $row['itemId'];
        }
        $item_ids = array_values(array_unique($item_ids));

        self::run(
            'DELETE FROM ' . self::TABLE . ' WHERE parentid IN (' . self::placeholders(count($parent_ids)) . ')',
            str_repeat('i', count($parent_ids)),
            ...$parent_ids
        );

        if ($item_ids !== []) {
            self::run(
                'DELETE FROM ' . self::TABLE . ' WHERE childid IN (' . self::placeholders(count($item_ids)) . ')',
                str_repeat('i', count($item_ids)),
                ...$item_ids
            );
        }

        self::syncEdges($parent_ids, false, []);
        if ($item_ids !== []) {
            self::syncEdges($item_ids, true, $parent_ids);
            $update = mysqli_prepare(Database::connection(), '
INSERT INTO `LinkIndexRevisions` (`itemId`, `domainRevision`)
    SELECT `itemId`, ? FROM `Items`
        WHERE `itemId` IN (' . self::placeholders(count($item_ids)) . ')
    ON DUPLICATE KEY UPDATE `domainRevision` = VALUES(`domainRevision`)
');
            mysqli_stmt_bind_param($update, 's' . str_repeat('i', count($item_ids)), $revision, ...$item_ids);
            mysqli_stmt_execute($update);
        }
    }

    /** Read each indexed adjacency list in bounded primary/secondary-key pages. */
    private static function syncEdges(array $item_ids, bool $incoming, array $rebuilt_parent_ids): void {
        $fixed = $incoming ? 'childId' : 'parentId';
        $cursor_column = $incoming ? 'parentId' : 'childId';
        $index = $incoming ? 'childId_parentId' : 'PRIMARY';
        $group_cursor = 0;
        $cursor = 0;
        $excluded = $rebuilt_parent_ids === [] ? ''
            : ' AND `Links`.`parentId` NOT IN (' . self::placeholders(count($rebuilt_parent_ids)) . ')';
        $select = mysqli_prepare(Database::connection(), '
SELECT STRAIGHT_JOIN `Links`.`parentId`, `Links`.`childId`, `Links`.`description`,
        `ParentHosts`.`domain`, (`ParentHosts`.`domain` <> `ChildHosts`.`domain`) AS `external`
    FROM `Links` FORCE INDEX (`' . $index . '`)
    INNER JOIN `Items` AS `ParentItems` ON `ParentItems`.`itemId` = `Links`.`parentId`
    INNER JOIN `Hosts` AS `ParentHosts` ON `ParentHosts`.`hostId` = `ParentItems`.`hostId`
    INNER JOIN `Items` AS `ChildItems` ON `ChildItems`.`itemId` = `Links`.`childId`
    INNER JOIN `Hosts` AS `ChildHosts` ON `ChildHosts`.`hostId` = `ChildItems`.`hostId`
    WHERE `Links`.`' . $fixed . '` IN (' . self::placeholders(count($item_ids)) . ')
        AND (`Links`.`' . $fixed . '` > ?
            OR (`Links`.`' . $fixed . '` = ? AND `Links`.`' . $cursor_column . '` > ?))' . $excluded . '
    ORDER BY `Links`.`' . $fixed . '`, `Links`.`' . $cursor_column . '`
    LIMIT 200
');
        do {
            $values = array_merge($item_ids, [$group_cursor, $group_cursor, $cursor], $rebuilt_parent_ids);
            mysqli_stmt_bind_param($select, str_repeat('i', count($values)), ...$values);
            mysqli_stmt_execute($select);
            $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
            self::upsertRows($rows);

            if ($rows !== []) {
                $group_cursor = (int) $rows[array_key_last($rows)][$fixed];
                $cursor = (int) $rows[array_key_last($rows)][$cursor_column];
            }
        } while (count($rows) === 200);
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function upsertRows(array $rows): void {
        if ($rows !== []) {
            $direct = !self::$mutating;
            self::mutate(static function () use ($rows, $direct): void {
                // A backfill may have read its metadata before a concurrent
                // host repair. Do not certify previously refreshed items
                // against an externally supplied batch of link documents.
                if ($direct) {
                    self::advanceDomainRevision();
                }
                self::writeRows($rows);
            });
        }
    }

    private static function writeRows(array $rows): void {
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
    public static function matches(string $query, array $item_ids): array {
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
    public static function focusedCandidates(string $query, int $limit): array {
        $limit = max(1, min(10000, $limit));

        // A writer in flight leaves readers on the original uncached path.
        // They must never publish its intermediate state as a reusable pool.
        if (!self::acquireCacheLock(0)) {
            return self::rankedCandidates($query, $limit);
        }

        try {
            $stored = Setting::value(self::CACHE_SETTING);
            $bytes = $stored !== null ? base64_decode($stored, true) : false;
            $decoded = is_string($bytes) && str_starts_with($bytes, chr(0x1F) . chr(0x8B))
                ? gzdecode($bytes, 1048576) : false;
            $cached = is_string($decoded) ? json_decode($decoded, true) : null;

            if (is_array($cached)
                && ($cached['query'] ?? null) === $query
                && ($cached['limit'] ?? null) === $limit
                && is_array($cached['candidates'] ?? null)
            ) {
                return $cached['candidates'];
            }

            $candidates = self::rankedCandidates($query, $limit);
            $stored = base64_encode(gzencode(json_encode([
                'query' => $query,
                'limit' => $limit,
                'candidates' => $candidates,
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 1));

            // Settings.value is TEXT. An unusually varied pool can exceed
            // its byte limit even compressed; it remains an uncached result.
            if (strlen($stored) <= 65535) {
                Setting::store(self::CACHE_SETTING, $stored);
            }

            return $candidates;
        } finally {
            self::releaseCacheLock();
        }
    }

    private static function rankedCandidates(string $query, int $limit): array {
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

    public static function clear(): void {
        self::mutate(static function (): void {
            self::advanceDomainRevision();
            self::run('TRUNCATE TABLE ' . self::TABLE);
        });
    }

    private static function advanceDomainRevision(): void {
        mysqli_query(Database::connection(), '
UPDATE `Settings`
    SET `value` = CAST(`value` AS UNSIGNED) + 1
    WHERE `name` = \'linkDomainRevision\'
');
        if (mysqli_affected_rows(Database::connection()) !== 1) {
            throw new SearchIndexUnavailable('Link-domain revision is missing; run the schema migration.');
        }
    }

    private static function mutate(callable $write): void {
        if (self::$mutating) {
            $write();
            return;
        }

        if (!self::acquireCacheLock(10)) {
            throw new SearchIndexUnavailable('Link-index writer is busy.');
        }

        try {
            // Invalidate before writing, including writes that fail partway.
            // The lock also covers direct backfill/upsert/clear callers.
            Setting::store(self::CACHE_SETTING, '');
            self::$mutating = true;
            $write();
        } finally {
            self::$mutating = false;
            self::releaseCacheLock();
        }
    }

    private static function acquireCacheLock(int $timeout): bool {
        $select = mysqli_prepare(Database::connection(), 'SELECT GET_LOCK(CONCAT(\'duskrail-link-index:\', DATABASE()), ?)');
        mysqli_stmt_bind_param($select, 'i', $timeout);
        mysqli_stmt_execute($select);

        return (int) mysqli_fetch_row(mysqli_stmt_get_result($select))[0] === 1;
    }

    private static function releaseCacheLock(): void {
        mysqli_query(Database::connection(), 'SELECT RELEASE_LOCK(CONCAT(\'duskrail-link-index:\', DATABASE()))');
    }

    private static function documentId(int $parent_id, int $child_id): int {
        if ($parent_id > 2147483647) {
            throw new \OverflowException('A link parent id cannot be packed into a signed 64-bit Manticore document id.');
        }

        return $parent_id * 4294967296 + $child_id;
    }
}
