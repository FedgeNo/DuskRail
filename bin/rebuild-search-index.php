<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

const SEARCH_INDEX_BACKFILL_SETTING = 'searchIndexBackfill';
const SEARCH_INDEX_PAGE_SIZE = 500;

$lock = fopen(VAR_DIR . '/search-index-backfill.lock', 'c');

if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, 'Another search-index backfill is already running.' . PHP_EOL);
    exit(75);
}

$state = json_decode((string) Setting::value(SEARCH_INDEX_BACKFILL_SETTING), true);

if (!is_array($state) || ($state['phase'] ?? '') === 'complete') {
    ItemSearchIndex::clear();
    LinkSearchIndex::clear();
    $state = [
        'phase' => 'items',
        'crawledTime' => 0,
        'itemId' => 0,
        'parentId' => 0,
        'childId' => 0,
    ];
    Setting::store(SEARCH_INDEX_BACKFILL_SETTING, json_encode($state, JSON_THROW_ON_ERROR));
}

while ($state['phase'] === 'items') {
    $select = mysqli_prepare(Database::connection(), '
SELECT `itemId`, `type`, `title`, `description`, `fullText`, `inc`, `crawledTime`
    FROM `Items` FORCE INDEX (`crawledTime_itemId_type_noindex`)
    WHERE ' . ItemSearchIndex::MARIA_SEARCHABLE_CONDITION . '
        AND (`crawledTime` > ? OR (`crawledTime` = ? AND `itemId` > ?))
    ORDER BY `crawledTime`, `itemId`
    LIMIT ' . SEARCH_INDEX_PAGE_SIZE . '
');
    mysqli_stmt_bind_param($select, 'iii', $state['crawledTime'], $state['crawledTime'], $state['itemId']);
    mysqli_stmt_execute($select);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);

    if ($rows === []) {
        $state['phase'] = 'links';
        Setting::store(SEARCH_INDEX_BACKFILL_SETTING, json_encode($state, JSON_THROW_ON_ERROR));
        echo 'Searchable items complete.' . PHP_EOL;
        break;
    }

    ItemSearchIndex::upsertRows($rows);
    $last = $rows[array_key_last($rows)];
    $state['crawledTime'] = (int) $last['crawledTime'];
    $state['itemId'] = (int) $last['itemId'];
    Setting::store(SEARCH_INDEX_BACKFILL_SETTING, json_encode($state, JSON_THROW_ON_ERROR));
    echo 'Searchable items through ' . $state['crawledTime'] . '/' . $state['itemId'] . ': ' . count($rows) . PHP_EOL;
}

while ($state['phase'] === 'links') {
    $select = mysqli_prepare(Database::connection(), '
SELECT `Links`.`parentId`, `Links`.`childId`, `Links`.`description`,
        `ParentHosts`.`domain`, (`ParentHosts`.`domain` <> `ChildHosts`.`domain`) AS `external`
    FROM `Links`
    INNER JOIN `Items` AS `ParentItems` ON `ParentItems`.`itemId` = `Links`.`parentId`
    INNER JOIN `Hosts` AS `ParentHosts` ON `ParentHosts`.`hostId` = `ParentItems`.`hostId`
    INNER JOIN `Items` AS `ChildItems` ON `ChildItems`.`itemId` = `Links`.`childId`
    INNER JOIN `Hosts` AS `ChildHosts` ON `ChildHosts`.`hostId` = `ChildItems`.`hostId`
    WHERE `Links`.`parentId` > ?
        OR (`Links`.`parentId` = ? AND `Links`.`childId` > ?)
    ORDER BY `Links`.`parentId`, `Links`.`childId`
    LIMIT ' . SEARCH_INDEX_PAGE_SIZE . '
');
    mysqli_stmt_bind_param($select, 'iii', $state['parentId'], $state['parentId'], $state['childId']);
    mysqli_stmt_execute($select);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($select), MYSQLI_ASSOC);

    if ($rows === []) {
        $state['phase'] = 'queue';
        Setting::store(SEARCH_INDEX_BACKFILL_SETTING, json_encode($state, JSON_THROW_ON_ERROR));
        echo 'Link anchors complete.' . PHP_EOL;
        break;
    }

    LinkSearchIndex::upsertRows($rows);
    $last = $rows[array_key_last($rows)];
    $state['parentId'] = (int) $last['parentId'];
    $state['childId'] = (int) $last['childId'];
    Setting::store(SEARCH_INDEX_BACKFILL_SETTING, json_encode($state, JSON_THROW_ON_ERROR));
    echo 'Link anchors through ' . $state['parentId'] . '/' . $state['childId'] . ': ' . count($rows) . PHP_EOL;
}

while ($state['phase'] === 'queue' && SearchIndexQueue::processPending(1000, true) > 0) {
    echo 'Applied a queued synchronization page.' . PHP_EOL;
}

if ($state['phase'] === 'queue' && SearchIndexQueue::hasPending()) {
    throw new RuntimeException('Queued search-index changes remain after synchronization stopped.');
}

$state['phase'] = 'complete';
Setting::store(SEARCH_INDEX_BACKFILL_SETTING, json_encode($state, JSON_THROW_ON_ERROR));
echo 'Search-index backfill complete.' . PHP_EOL;
