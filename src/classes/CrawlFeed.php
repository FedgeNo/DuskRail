<?php

declare(strict_types=1);

/**
 * One poll's worth of the live crawl feed behind watch.php, oldest first.
 *
 * crawledTime doubles as the cursor: the client passes back the highest one
 * it has seen and gets everything stamped at or since it. At or since, not
 * strictly since - crawledTime is a whole second and concurrent workers
 * routinely stamp several items inside one, so a poll that read the first of
 * them and then asked for "> that second" would never see the rest. The
 * client knows which itemIds it has already drawn and skips the repeats.
 *
 * Every row here is guaranteed real, presentable content: nothing keeps a
 * crawledTime after a failure (see Item::delete()), so this feed can't
 * surface junk.
 */
class CrawlFeed
{
    // How many rows a single poll can return, whether it's the initial seed
    // or a normal forward-poll batch.
    private const BATCH_SIZE = 50;

    /** @var list<CrawlFeedItem> */
    public array $items = [];
    public int $since;

    public function __construct(int $since)
    {
        $this -> since = max(0, $since);

        foreach ($this -> rows() as $row) {
            $this -> items[] = CrawlFeedItem::fromRow($row);
        }
    }

    public function toJSON(): array
    {
        return array_map(static fn (CrawlFeedItem $item): array => $item -> toJSON(), $this -> items);
    }

    private function rows(): array
    {
        return $this -> since === 0 ? $this -> seedRows() : $this -> forwardRows();
    }

    /**
     * The client's very first poll, with no cursor yet - seeded with only the
     * most recently crawled items rather than replaying the whole crawl
     * history forward from the beginning 50 rows at a time. Selected newest
     * first to get the right 50, then handed back oldest first so the client
     * appends them in its usual top-to-bottom order and derives its next
     * cursor from the last one exactly as it always does.
     *
     * Ordered by crawledTime alone, exactly the crawledTime_itemId_type index
     * read backwards. Rows sharing the seed's boundary second that the
     * arbitrary tie order left out arrive on the first forward poll (its
     * cursor is inclusive) and the client's seen-set drops the repeats, so a
     * tiebreak here buys nothing the polling protocol doesn't already
     * guarantee.
     */
    private function seedRows(): array
    {
        $result = mysqli_query(Database::connection(), '
SELECT `itemId`, `url`, `type`, `title`, `description`, `crawledTime`
    FROM `Items`
    WHERE `crawledTime` IS NOT NULL
    ORDER BY `crawledTime` DESC
    LIMIT ' . self::BATCH_SIZE . '
');

        return $result !== false ? array_reverse(mysqli_fetch_all($result, MYSQLI_ASSOC)) : [];
    }

    private function forwardRows(): array
    {
        $select = mysqli_prepare(Database::connection(), '
SELECT `itemId`, `url`, `type`, `title`, `description`, `crawledTime`
    FROM `Items`
    WHERE `crawledTime` >= ?
    ORDER BY `crawledTime` ASC, `itemId` ASC
    LIMIT ' . self::BATCH_SIZE . '
');
        mysqli_stmt_bind_param($select, 'i', $this -> since);
        mysqli_stmt_execute($select);

        return mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
    }
}
