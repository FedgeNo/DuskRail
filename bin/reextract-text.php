<?php

declare(strict_types=1);

/**
 * Recomputes fullText (and, where it was body-derived, description) for every
 * crawled HTML item from its stored fullHTML: `php bin/reextract-text.php`
 * (add `--apply` to write; it reports and changes nothing without).
 *
 * Exists because the extraction pipeline improves over time - block-element
 * whitespace injection, boilerplate rules, charset handling - and rows
 * crawled under an older pipeline keep whatever it produced until their next
 * recrawl. fullHTML is the exact bytes the extraction ran on, so the current
 * pipeline can be re-run offline: no refetching, no politeness cost, and the
 * result is identical to what a recrawl of unchanged content would store.
 *
 * contentHash is updated alongside, so the next real recrawl compares
 * against what the current pipeline produces rather than resetting every
 * item's recrawl interval over a text change that wasn't the site's doing.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$apply = in_array('--apply', $argv, true);
$connection = Database::connection();

$ids = [];
$result = mysqli_query($connection, '
SELECT `itemId`
    FROM `Items`
    WHERE `crawledTime` IS NOT NULL
        AND `fullHTML` IS NOT NULL
        AND `type` IN (\'text/html\', \'application/xhtml+xml\')
');

while ($row = mysqli_fetch_assoc($result)) {
    $ids[] = (int) $row['itemId'];
}

echo count($ids) . ' crawled HTML item(s) with stored fullHTML.
';

$update = mysqli_prepare($connection, '
UPDATE `Items`
    SET `fullText` = ?, `description` = ?, `contentHash` = ?
    WHERE `itemId` = ?
');

$changed = 0;
$unchanged = 0;
$unreadable = 0;

foreach ($ids as $itemId) {
    $item = Item::findWithContentById($itemId);

    if ($item === null) {
        continue;
    }

    $html = $item -> decompressedFullHTML();

    if ($html === null || $html === '') {
        $unreadable++;
        continue;
    }

    // The same pipeline bin/crawler.php runs after a fetch, minus discovery.
    // fullHTML was stored after charset decoding, so it's already UTF-8.
    $document = HTMLLoader::load($html, 'UTF-8');
    HTMLLoader::separateBlockElements($document);
    HTMLLoader::inlineImageAltText($document);
    $metadata = HTMLLoader::extractMetadata($document);
    HTMLLoader::removeStyleAndScriptTags($document);
    HTMLLoader::removeBoilerplateElements($document);
    // Same cap Item::markCrawled() applies, so the comparison below is
    // stored-form against stored-form rather than reporting every page with a
    // repeated word as "extracts differently".
    $bodyText = Text::capRepeatedTerms(HTMLLoader::extractBodyText($document));

    // A page with its own declared description keeps it; one whose stored
    // description was the old pipeline's body-text excerpt gets the new
    // excerpt, same fallback the crawler applies.
    $description = $metadata['description'] ?? mb_substr($bodyText, 0, 500);

    if ($bodyText === $item -> fullText) {
        $unchanged++;
        continue;
    }

    $changed++;

    if ($apply) {
        $contentHash = sha1($bodyText);
        mysqli_stmt_bind_param($update, 'sssi', $bodyText, $description, $contentHash, $itemId);
        mysqli_stmt_execute($update);
    }
}

echo $changed . ' item(s) extract differently under the current pipeline, ' . $unchanged . ' unchanged, ' . $unreadable . ' unreadable.
';

if (!$apply) {
    echo 'Nothing written - re-run with --apply to store the re-extracted text.
';
    exit(0);
}

echo 'Updated ' . $changed . ' item(s).
';
