<?php

declare(strict_types=1);

/**
 * One page of search results for a query.
 *
 * Ranked by how many distinct *hosts* link to a result with anchor text that
 * also matches the query - distinct hosts, not raw link rows, because ten
 * links from one site are one site's opinion, not ten. Ties break on the
 * item's own FULLTEXT relevance, then on how often the URL has been seen
 * linked anywhere (Items.inc - a cheap popularity signal), then on itemId so
 * OFFSET paging never shuffles.
 *
 * A query containing double quotes runs in BOOLEAN MODE, which is what gives
 * quoted phrases their exact-phrase meaning; everything else stays in
 * natural-language mode, whose ranking is better for bare words.
 *
 * HTML and image rows are fetched by separate, near-identical queries rather
 * than one combined one - fullText is meaningful full-body-text signal for
 * pages but doesn't exist for images, so folding both into a single MATCH()
 * column list would make an image's much sparser title/description/keywords
 * compete unfairly against a page's huge fullText on the same relevance
 * scale.
 */
class SearchResults
{
    public const PAGE_SIZE = 50;

    // Everything the "Pages" facet covers - matches what the crawler can
    // extract searchable text from (ContentType::isHTML(), plus the PDF and
    // plain-text paths in bin/crawler.php).
    private const PAGE_TYPE_CONDITION = '`Items`.`type` IN (\'text/html\', \'application/xhtml+xml\', \'application/pdf\', \'text/plain\')';
    private const IMAGE_TYPE_CONDITION = '`Items`.`type` LIKE \'image/%\'';

    // How much text around the first match the snippet carries - enough to
    // read the sentence the term landed in, small enough that fifty of them
    // stay scannable.
    private const SNIPPET_BEFORE = 150;
    private const SNIPPET_LENGTH = 400;

    // Two-stage retrieval: the link-signal ranking only ever considers the
    // top this-many results by content relevance. Without the bound, a query
    // matching half the index dragged every match through the grouping and
    // the final sort - fine at thousands of matches, a per-search scan of
    // most of the corpus at millions. The tradeoff is explicit: a page can
    // only be link-boosted into the results if it's already in the top
    // couple thousand by what it says itself, which is also roughly the
    // point past which nobody paginates.
    private const RELEVANCE_POOL_SIZE = 2000;

    /** @var list<SearchResult> */
    public array $results = [];
    public bool $hasMore = false;

    public string $query;
    public string $type;
    public int $offset;

    public function __construct(string $query, string $type, int $offset)
    {
        $this -> query = $query;
        $this -> type = $type === 'image' ? 'image' : 'html';
        $this -> offset = max(0, $offset);

        if ($this -> query === '') {
            return;
        }

        $rows = $this -> rows();

        // One row past the page boundary is fetched purely to answer "is
        // there another page" - trimmed off here, never handed onward.
        $this -> hasMore = count($rows) > self::PAGE_SIZE;

        foreach (array_slice($rows, 0, self::PAGE_SIZE) as $row) {
            $this -> results[] = SearchResult::fromRow($row);
        }
    }

    public function toJSON(): array
    {
        return [
            'results' => array_map(static fn (SearchResult $result): array => $result -> toJSON(), $this -> results),
            'hasMore' => $this -> hasMore,
        ];
    }

    /**
     * The linkMatches count comes from joining a derived table of the Links
     * rows whose anchor text matches, not from a correlated subquery per row.
     * The correlated form reads better and is what this project reaches for
     * by default, but it re-runs a FULLTEXT match once per candidate item:
     * measured at 2.2 seconds against 19ms for identical results, getting
     * worse in proportion to how well the query matches.
     *
     * The snippet is cut in SQL around the first occurrence of the query's
     * first real word, rather than pulling whole fullText columns (routinely
     * megabytes each) into PHP just to cut them there. matchPosition rides
     * along so SearchResult can tell a snippet that starts mid-document from
     * one that starts at the top.
     */
    private function rows(): array
    {
        $mode = str_contains($this -> query, '"') ? ' IN BOOLEAN MODE' : ' IN NATURAL LANGUAGE MODE';
        $snippetWord = $this -> firstSearchWord();
        $snippetStartOffset = self::SNIPPET_BEFORE;
        $snippetLength = self::SNIPPET_LENGTH;

        // Three bounded stages, innermost out. The pool takes the top
        // RELEVANCE_POOL_SIZE matches by content relevance - the only
        // unbounded FULLTEXT read left, and it sorts two integers and a
        // float per match. The ranking stage groups link signal over pool
        // rows only. The outer stage joins the full Items rows by primary
        // key for display fields and cuts the snippet - LOCATE/SUBSTRING
        // over fullText runs against just the page that survived the LIMIT,
        // never against every match (measured at a full second that way).
        $select = mysqli_prepare(Database::connection(), '
SELECT `ranked`.*, `Items`.`url`, `Items`.`type`, `Items`.`title`, `Items`.`description`,
        LOCATE(?, `Items`.`fullText`) AS `matchPosition`,
        CASE WHEN LOCATE(?, `Items`.`fullText`) > 0
            THEN SUBSTRING(`Items`.`fullText`, GREATEST(1, LOCATE(?, `Items`.`fullText`) - ?), ?)
            ELSE NULL
        END AS `snippet`
    FROM (
        SELECT `pool`.`itemId`, `pool`.`inc`, `pool`.`relevance`,
                COUNT(DISTINCT `matchingLinks`.`hostId`) AS `linkMatches`
            FROM (
                SELECT `Items`.`itemId`, `Items`.`inc`,
                        MATCH(`Items`.`title`, `Items`.`description`, `Items`.`keywords`, `Items`.`fullText`) AGAINST (?' . $mode . ') AS `relevance`
                    FROM `Items`
                    WHERE `Items`.`crawledTime` IS NOT NULL
                        AND `Items`.`noindex` = 0
                        AND ' . ($this -> type === 'image' ? self::IMAGE_TYPE_CONDITION : self::PAGE_TYPE_CONDITION) . '
                        AND MATCH(`Items`.`title`, `Items`.`description`, `Items`.`keywords`, `Items`.`fullText`) AGAINST (?' . $mode . ')
                    ORDER BY `relevance` DESC
                    LIMIT ?
            ) AS `pool`
            LEFT JOIN (
                SELECT `Links`.`childId`, `ParentItems`.`hostId`
                    FROM `Links`
                    INNER JOIN `Items` AS `ParentItems` ON `ParentItems`.`itemId` = `Links`.`parentId`
                    WHERE MATCH(`Links`.`description`) AGAINST (?' . $mode . ')
            ) AS `matchingLinks` ON `matchingLinks`.`childId` = `pool`.`itemId`
            GROUP BY `pool`.`itemId`
            ORDER BY `linkMatches` DESC, `relevance` DESC, `pool`.`inc` DESC, `pool`.`itemId` ASC
            LIMIT ?, ?
    ) AS `ranked`
    INNER JOIN `Items` ON `Items`.`itemId` = `ranked`.`itemId`
    ORDER BY `ranked`.`linkMatches` DESC, `ranked`.`relevance` DESC, `ranked`.`inc` DESC, `ranked`.`itemId` ASC
');
        $poolSize = self::RELEVANCE_POOL_SIZE;
        $fetchLimit = self::PAGE_SIZE + 1;
        mysqli_stmt_bind_param(
            $select,
            'sssiissisii',
            $snippetWord,
            $snippetWord,
            $snippetWord,
            $snippetStartOffset,
            $snippetLength,
            $this -> query,
            $this -> query,
            $poolSize,
            $this -> query,
            $this -> offset,
            $fetchLimit
        );
        mysqli_stmt_execute($select);

        return mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
    }

    /**
     * The first word of the query that's substantial enough to anchor a
     * snippet on - quotes and boolean operators stripped, short words
     * skipped (the FULLTEXT index didn't match on those either). Falls back
     * to the whole query, which simply finds nothing and lets the
     * description stand in.
     */
    private function firstSearchWord(): string
    {
        $bare = str_replace(['"', '+', '-', '~', '<', '>', '(', ')', '*'], ' ', $this -> query);

        foreach (explode(' ', $bare) as $word) {
            if (mb_strlen($word) >= 3) {
                return $word;
            }
        }

        return $this -> query;
    }
}
