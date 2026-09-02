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
 * Ties break on the item's own Manticore relevance, then on how many distinct
 * pages link to it (Items.inc), then on itemId so OFFSET paging never
 * shuffles.
 *
 * Quoted groups become exact phrases. Bare words use explicit any-word
 * semantics, and operator punctuation from public input is discarded before
 * the expression reaches Manticore.
 */
class SearchResults
{
    public const PAGE_SIZE = 50;
    public const MAX_QUERY_LENGTH = 256;

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

    /** Manticore ranks a bounded pool; MariaDB hydrates only the final page. */
    private function rows(): array
    {
        $candidates = ItemSearchIndex::candidates(
            $this -> query,
            $this -> type === 'image',
            self::RELEVANCE_POOL_SIZE
        );

        if ($candidates === []) {
            return [];
        }

        $link_matches = LinkSearchIndex::matches($this -> query, array_keys($candidates));

        foreach ($candidates as $item_id => &$candidate) {
            $candidate['linkMatches'] = $link_matches[$item_id] ?? 0;
        }
        unset($candidate);

        uasort($candidates, static function (array $left, array $right): int {
            return ($right['linkMatches'] <=> $left['linkMatches'])
                ?: ($right['relevance'] <=> $left['relevance'])
                ?: ($right['inc'] <=> $left['inc'])
                ?: ($left['itemId'] <=> $right['itemId']);
        });

        $page = array_slice(array_values($candidates), $this -> offset, self::PAGE_SIZE + 1);

        if ($page === []) {
            return [];
        }

        $item_ids = array_column($page, 'itemId');
        $snippetWord = $this -> firstSearchWord();
        $snippetStartOffset = self::SNIPPET_BEFORE;
        $snippetLength = self::SNIPPET_LENGTH;
        $placeholders = implode(', ', array_fill(0, count($item_ids), '?'));
        $select = mysqli_prepare(Database::connection(), '
SELECT `Items`.`itemId`, `Items`.`url`, `Items`.`type`, `Items`.`title`, `Items`.`description`,
        LOCATE(?, `Items`.`fullText`) AS `matchPosition`,
        CASE WHEN LOCATE(?, `Items`.`fullText`) > 0
            THEN SUBSTRING(`Items`.`fullText`, GREATEST(1, LOCATE(?, `Items`.`fullText`) - ?), ?)
            ELSE NULL
        END AS `snippet`
    FROM `Items`
    WHERE `Items`.`itemId` IN (' . $placeholders . ')
');
        mysqli_stmt_bind_param(
            $select,
            'sssii' . str_repeat('i', count($item_ids)),
            $snippetWord,
            $snippetWord,
            $snippetWord,
            $snippetStartOffset,
            $snippetLength,
            ...$item_ids
        );
        mysqli_stmt_execute($select);
        $hydrated = [];

        foreach (mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC) as $row) {
            $hydrated[(int) $row['itemId']] = $row;
        }

        $rows = [];

        foreach ($page as $candidate) {
            $item_id = $candidate['itemId'];

            if (!isset($hydrated[$item_id])) {
                continue;
            }

            $rows[] = $hydrated[$item_id] + $candidate;
        }

        return $rows;
    }

    /**
     * The first word of the query that's substantial enough to anchor a
     * snippet on - quotes and operators stripped, short words skipped. Falls
     * back to the whole query, which simply finds nothing and lets the
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
