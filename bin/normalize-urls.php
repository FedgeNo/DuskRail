<?php

declare(strict_types=1);

/**
 * Re-normalizes every Items.url against URL's current rules and merges the
 * rows that turn out to be the same resource: `php bin/normalize-urls.php`
 * (add `--apply` to actually write; it reports and changes nothing without).
 *
 * URL normalization has gained rules over time - stripping image
 * cache-busters, percent-encoding raw characters in a path, dropping an
 * explicit :80 from an upgraded http URL. Rows stored before a rule existed
 * keep the spelling they were stored under, so the same image can sit in the
 * index two or three times over, competing with itself in search results and
 * costing a crawl of each copy.
 *
 * Safe to re-run: it only ever acts on rows whose normalized form differs
 * from what's stored, so a second run over an already-clean index does
 * nothing.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$apply = in_array('--apply', $argv, true);
$connection = Database::connection();

echo 'Reading items...
';

$result = mysqli_query($connection, '
SELECT `itemId`, `url`, `crawledTime`
    FROM `Items`
');

// Every item that normalizes to a given URL, keyed by that URL. Anything with
// more than one entry is a set of duplicates to merge; a single entry whose
// stored url differs from the key is simply a row to rewrite in place.
$byNormalized = [];
$total = 0;

while ($row = mysqli_fetch_assoc($result)) {
    $total++;
    $normalized = (new URL($row['url'])) -> toString();

    $byNormalized[$normalized][] = [
        'itemId' => (int) $row['itemId'],
        'url' => $row['url'],
        'crawledTime' => $row['crawledTime'] !== null ? (int) $row['crawledTime'] : null,
    ];
}

$rewrites = [];
$merges = [];

foreach ($byNormalized as $normalized => $items) {
    if (count($items) === 1) {
        if ($items[0]['url'] !== $normalized) {
            $rewrites[] = ['itemId' => $items[0]['itemId'], 'url' => $normalized];
        }

        continue;
    }

    // The row worth keeping is a crawled one - it has the content, the
    // thumbnail and the crawl history. Among equals, the lowest itemId, so
    // the surviving row is the one everything has been linking to longest.
    usort($items, static function (array $a, array $b): int {
        if (($a['crawledTime'] !== null) !== ($b['crawledTime'] !== null)) {
            return $a['crawledTime'] !== null ? -1 : 1;
        }

        return $a['itemId'] <=> $b['itemId'];
    });

    $merges[] = ['keep' => array_shift($items), 'drop' => $items, 'url' => $normalized];
}

echo 'Items: ' . $total . '
';
echo 'Rows whose URL normalizes differently: ' . count($rewrites) . '
';
echo 'Duplicate groups to merge: ' . count($merges) . ' (' . array_sum(array_map(static fn (array $merge): int => count($merge['drop']), $merges)) . ' redundant rows)
';

if (!$apply) {
    echo '
Nothing written - re-run with --apply to make these changes.
';
    exit(0);
}

$rewrite = mysqli_prepare($connection, '
UPDATE `Items`
    SET `url` = ?
    WHERE `itemId` = ?
');

// Links has (parentId, childId) as its primary key, so repointing a dropped
// item's links at the surviving one collides wherever both already link to
// the same thing - INSERT IGNORE ... SELECT keeps the ones that fit and
// silently skips the rest, which is exactly right: the link already exists.
$moveParentLinks = mysqli_prepare($connection, '
INSERT IGNORE INTO `Links` (`parentId`, `childId`, `description`)
    SELECT ?, `childId`, `description`
        FROM `Links`
        WHERE `parentId` = ?
');

$moveChildLinks = mysqli_prepare($connection, '
INSERT IGNORE INTO `Links` (`parentId`, `childId`, `description`)
    SELECT `parentId`, ?, `description`
        FROM `Links`
        WHERE `childId` = ?
');

$mergedCount = 0;

foreach ($merges as $merge) {
    $keepId = $merge['keep']['itemId'];

    foreach ($merge['drop'] as $drop) {
        mysqli_stmt_bind_param($moveParentLinks, 'ii', $keepId, $drop['itemId']);
        mysqli_stmt_execute($moveParentLinks);

        mysqli_stmt_bind_param($moveChildLinks, 'ii', $keepId, $drop['itemId']);
        mysqli_stmt_execute($moveChildLinks);

        // No dead-URL reason - this URL isn't unusable, it's the same
        // resource as the row being kept, and recording it dead would stop
        // that row ever being rediscovered.
        Item::findById($drop['itemId']) ?-> delete();
        $mergedCount++;
    }

    // Done after the duplicates are gone, since the surviving row is taking
    // over a URL one of them may still have been holding under the unique key.
    if ($merge['keep']['url'] !== $merge['url']) {
        mysqli_stmt_bind_param($rewrite, 'si', $merge['url'], $keepId);
        mysqli_stmt_execute($rewrite);
    }
}

$rewrittenCount = 0;

foreach ($rewrites as $row) {
    mysqli_stmt_bind_param($rewrite, 'si', $row['url'], $row['itemId']);
    mysqli_stmt_execute($rewrite);
    $rewrittenCount++;
}

echo 'Merged away ' . $mergedCount . ' duplicate row(s).
';
echo 'Rewrote ' . $rewrittenCount . ' URL(s).
';
