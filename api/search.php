<?php

declare(strict_types=1);

require __DIR__ . '/../init.php';

header('Content-Type: application/json');

// Page size for both the SQL LIMIT and the infinite-scroll contract with
// search.js - each request returns at most this many results, plus a
// hasMore flag so the frontend knows whether to request another page.
const PAGE_SIZE = 50;

$query = trim((string) ($_GET['q'] ?? ''));
$type = ($_GET['type'] ?? 'html') === 'image' ? 'image' : 'html';
$offset = max(0, (int) ($_GET['offset'] ?? 0));

if ($query === '') {
    echo json_encode(['results' => [], 'hasMore' => false]);
    return;
}

$connection = Database::connection();

// Rank by how many times the item was linked to using anchor text that also
// matches the query first (an external "what do other pages say this page is
// about" signal), then break ties with direct relevance. HTML and image rows
// are fetched with separate, near-identical queries rather than one combined
// one - fullText is meaningful full-body-text signal for HTML but doesn't
// exist for images, so folding both into a single MATCH() column list would
// make an image's much sparser title/description/keywords compete unfairly
// against an HTML page's huge fullText on the same relevance scale.
//
// Both fetch one row past the page boundary (PAGE_SIZE + 1) purely so the
// caller can tell whether another page exists - that extra row is trimmed
// off below and never makes it into the response.
function search_html(mysqli $connection, string $query, int $offset): array
{
    // Matches ContentType::HTML_TYPES - a crawled item's `type` is stored
    // verbatim from whatever bare MIME type the server sent, and both of
    // these are treated as HTML there, not just 'text/html'.
    $select = mysqli_prepare($connection, '
SELECT `itemId`, `url`, `type`, `title`, `description`,
        MATCH(`title`, `description`, `keywords`, `fullText`) AGAINST (? IN NATURAL LANGUAGE MODE) AS `relevance`,
        (
            SELECT COUNT(*)
                FROM `Links`
                WHERE `Links`.`childId` = `Items`.`itemId`
                    AND MATCH(`Links`.`description`) AGAINST (? IN NATURAL LANGUAGE MODE)
        ) AS `linkMatches`
    FROM `Items`
    WHERE `crawledTime` IS NOT NULL
        AND `type` IN (\'text/html\', \'application/xhtml+xml\')
        AND MATCH(`title`, `description`, `keywords`, `fullText`) AGAINST (? IN NATURAL LANGUAGE MODE)
    ORDER BY `linkMatches` DESC, `relevance` DESC
    LIMIT ?, ?
');
    $fetchLimit = PAGE_SIZE + 1;
    mysqli_stmt_bind_param($select, 'sssii', $query, $query, $query, $offset, $fetchLimit);
    mysqli_stmt_execute($select);

    return mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
}

function search_images(mysqli $connection, string $query, int $offset): array
{
    // No dedicated 3-column FULLTEXT index exists for images - MATCH()'s
    // column list must exactly match some defined index, so this still
    // matches against all 4 columns like search_html(). fullText is always
    // NULL on image rows, so it never actually contributes to relevance here.
    $select = mysqli_prepare($connection, '
SELECT `itemId`, `url`, `type`, `title`, `description`,
        MATCH(`title`, `description`, `keywords`, `fullText`) AGAINST (? IN NATURAL LANGUAGE MODE) AS `relevance`,
        (
            SELECT COUNT(*)
                FROM `Links`
                WHERE `Links`.`childId` = `Items`.`itemId`
                    AND MATCH(`Links`.`description`) AGAINST (? IN NATURAL LANGUAGE MODE)
        ) AS `linkMatches`
    FROM `Items`
    WHERE `crawledTime` IS NOT NULL
        AND `type` LIKE \'image/%\'
        AND MATCH(`title`, `description`, `keywords`, `fullText`) AGAINST (? IN NATURAL LANGUAGE MODE)
    ORDER BY `linkMatches` DESC, `relevance` DESC
    LIMIT ?, ?
');
    $fetchLimit = PAGE_SIZE + 1;
    mysqli_stmt_bind_param($select, 'sssii', $query, $query, $query, $offset, $fetchLimit);
    mysqli_stmt_execute($select);

    return mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);
}

// Already in linkMatches DESC, relevance DESC order - both queries' own
// ORDER BY already did this server-side, so there's nothing left to sort
// here.
$rows = $type === 'image' ? search_images($connection, $query, $offset) : search_html($connection, $query, $offset);

$hasMore = count($rows) > PAGE_SIZE;
$rows = array_slice($rows, 0, PAGE_SIZE);

$results = [];

foreach ($rows as $row) {
    $itemId = (int) $row['itemId'];
    $description = $row['description'] !== null ? mb_substr($row['description'], 0, 500) : null;

    $results[] = [
        'itemId' => $itemId,
        'url' => $row['url'],
        'type' => $row['type'],
        'title' => $row['title'],
        'description' => $description,
        'thumbnailUrl' => str_starts_with($row['type'], 'image/') ? ImageLoader::thumbnailURL($itemId) : null,
    ];
}

echo json_encode(['results' => $results, 'hasMore' => $hasMore]);
