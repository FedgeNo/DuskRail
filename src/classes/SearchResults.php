<?php

declare(strict_types=1);

/**
 * One page of search results for a query.
 *
 * Ranked by how many distinct *domains* link to a result with anchor text
 * that also matches the query. Ten links from one site are one site's
 * opinion, not ten, and so are links from a.evil.com, b.evil.com and every
 * other hostname one registered domain mints from a wildcard DNS record -
 * counting hostnames makes that manipulation free, while the registrable
 * domain (PublicSuffixList, stored per host as Hosts.domain) is the unit
 * somebody pays a registrar for.
 *
 * A page's own domain never counts toward its link signal. Four out of five
 * link edges are a site linking itself, and while those are genuine
 * navigation they are not anyone's opinion of the page: counted, they hand
 * every site the +1 that decides the top of the results for any query where
 * nothing else has a link match, for the price of linking its own page with
 * the right anchor text.
 *
 * Ties break on the item's own FULLTEXT relevance, then on how many distinct
 * pages link to it (Items.inc), then on itemId so OFFSET paging never
 * shuffles.
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
     * by default, but it re-runs a FULLTEXT match once per candidate item -
     * 2.2 seconds against 19ms for identical results, and worse in
     * proportion to how well the query matches.
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
        // never against every match - inline it costs a full second.
        $select = mysqli_prepare(Database::connection(), '
SELECT `ranked`.*, `Items`.`url`, `Items`.`type`, `Items`.`title`, `Items`.`description`,
        LOCATE(?, `Items`.`fullText`) AS `matchPosition`,
        CASE WHEN LOCATE(?, `Items`.`fullText`) > 0
            THEN SUBSTRING(`Items`.`fullText`, GREATEST(1, LOCATE(?, `Items`.`fullText`) - ?), ?)
            ELSE NULL
        END AS `snippet`
    FROM (
        SELECT `pool`.`itemId`, `pool`.`inc`, `pool`.`relevance`,
                COUNT(DISTINCT CASE WHEN `matchingLinks`.`domain` <> `PoolHosts`.`domain`
                    THEN `matchingLinks`.`domain` END) AS `linkMatches`
            FROM (
                SELECT `Items`.`itemId`, `Items`.`inc`, `Items`.`hostId`,
                        MATCH(`Items`.`title`, `Items`.`description`, `Items`.`fullText`) AGAINST (?' . $mode . ') AS `relevance`
                    FROM `Items`
                    WHERE `Items`.`crawledTime` IS NOT NULL
                        AND `Items`.`noindex` = 0
                        AND ' . ($this -> type === 'image' ? self::IMAGE_TYPE_CONDITION : self::PAGE_TYPE_CONDITION) . '
                        AND MATCH(`Items`.`title`, `Items`.`description`, `Items`.`fullText`) AGAINST (?' . $mode . ')
                    ORDER BY `relevance` DESC
                    LIMIT ?
            ) AS `pool`
            INNER JOIN `Hosts` AS `PoolHosts` ON `PoolHosts`.`hostId` = `pool`.`hostId`
            LEFT JOIN (
                SELECT `Links`.`childId`, `ParentHosts`.`domain`
                    FROM `Links`
                    INNER JOIN `Items` AS `ParentItems` ON `ParentItems`.`itemId` = `Links`.`parentId`
                    INNER JOIN `Hosts` AS `ParentHosts` ON `ParentHosts`.`hostId` = `ParentItems`.`hostId`
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
