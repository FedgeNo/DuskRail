<?php

declare(strict_types=1);

// Requires disposable, socket-only MariaDB and Manticore instances in the
// same mktemp directory. Never opens the application's configured databases.
$directory = realpath($argv[1] ?? '');

if ($directory === false || !preg_match('#^/tmp/duskrail-optimization-tests\.[a-zA-Z0-9]+$#D', $directory)) {
    throw new RuntimeException('Supply a disposable /tmp/duskrail-optimization-tests.* directory.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = mysqli_connect('localhost', 'root', '', '', 0, $directory . '/db.sock');
$datadir = mysqli_fetch_row(mysqli_query($connection, 'SELECT @@datadir'))[0];

if (realpath($datadir) !== $directory . '/data') {
    throw new RuntimeException('Refusing a database outside the disposable directory.');
}

$database = 'duskrail_test_' . bin2hex(random_bytes(6));
mysqli_query($connection, 'CREATE DATABASE `' . $database . '`');
mysqli_select_db($connection, $database);
mysqli_query($connection, 'SET SESSION max_statement_time=3, innodb_lock_wait_timeout=1');
require __DIR__ . '/../../init.php';
Database::useConnection($connection);

$schema = proc_open(
    ['mariadb', '--no-defaults', '--socket=' . $directory . '/db.sock', '--user=root', $database],
    [0 => ['file', ROOT_DIR . '/schema.sql', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes
);

if (!is_resource($schema) || proc_close($schema) !== 0) {
    throw new RuntimeException('Could not load the test schema.');
}

$search = mysqli_connect('localhost', '', '', '', 0, $directory . '/search.sock');
$search_connection = new ReflectionProperty(SearchIndex::class, 'connection');
$search_connection -> setValue(null, $search);
SearchIndex::installSchema(ROOT_DIR . '/manticore-schema.sql');
ItemSearchIndex::clear();
LinkSearchIndex::clear();

$passed = 0;
function check(string $label, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    $GLOBALS['passed']++;
}

function discover(string $url, ?string $description = null): Item
{
    $item = Item::findOrCreateByURL(new URL($url), 'text/html', null, $description);

    if ($item === null) {
        throw new RuntimeException('Fixture discovery failed.');
    }

    return $item;
}

function drain(): void
{
    while (SearchIndexQueue::processPending(1000, true) > 0) {
    }
}

function indexed_items(): array
{
    return ItemSearchIndex::candidates('widgets', false, 1000);
}

$parent = discover('https://example.org/parent');
$child = discover('https://example.com/child');
$parent -> markCrawled('text/html', 'widgets parent', null, null, 'widgets parent text', '<p>widgets parent text</p>', 0, false);
$child -> markCrawled('text/html', 'widgets child', null, null, 'widgets child text', '<p>widgets child text</p>', 0, false);
drain();
check('initial documents are searchable', 2, count(indexed_items()));
check('initial counters', [2, 2, 0], [(new CrawlStatistics()) -> pages, (new CrawlStatistics()) -> indexed, (new CrawlStatistics()) -> queued]);

// Holding the shared counter row must not block a count-only item update.
$other = mysqli_connect('localhost', 'root', '', $database, 0, $directory . '/db.sock');
mysqli_query($other, 'SET SESSION max_statement_time=3, innodb_lock_wait_timeout=1');
mysqli_begin_transaction($other);
mysqli_query($other, 'SELECT * FROM `CrawlCounters` WHERE `counterId` = 1 FOR UPDATE');
Item::countInboundLinks([$child -> itemId]);
mysqli_rollback($other);
drain();
check('numeric attribute update is visible', 1, indexed_items()[$child -> itemId]['inc']);
check('count update preserves counters', 2, (new CrawlStatistics()) -> pages);

// Apply the installer's actual migration too, without running the installer.
$installer = file_get_contents(ROOT_DIR . '/bin/install.php');
preg_match('/\x27name\x27 => \x27skip_unchanged_crawl_counters\x27.*?run_sql\(<<<\x27SQL\x27\n(.*?)\nSQL\);/s', $installer, $migration);
check('counter migration exists', true, isset($migration[1]));
mysqli_query($connection, $migration[1]);
$child -> markCrawled('image/jpeg', 'widgets child', null, null, null, null, 1, false);
check('page to image changes counters', [1, 1, 1], [(new CrawlStatistics()) -> pages, (new CrawlStatistics()) -> images, (new CrawlStatistics()) -> searchable]);
$child -> markCrawled('text/html', 'widgets child', null, null, 'widgets child text', null, 0, false);
check('image to page restores counters', [2, 0, 2], [(new CrawlStatistics()) -> pages, (new CrawlStatistics()) -> images, (new CrawlStatistics()) -> searchable]);
drain();

// Metadata filled by rediscovery must not be lost by the count-only path.
discover('https://example.com/child', 'new description');
Item::countInboundLinks([$child -> itemId]);
drain();
$documents = mysqli_fetch_all(mysqli_query($search, 'SELECT id, description FROM duskrail_items LIMIT 1000'), MYSQLI_ASSOC);
$descriptions = array_column($documents, 'description', 'id');
check('count update retains discovered description', 'new description', $descriptions[$child -> itemId]);
check('count update retains new count', 2, indexed_items()[$child -> itemId]['inc']);

// Full work wins over partial work regardless of recording order.
SearchIndexQueue::recordCounts([$child -> itemId]);
SearchIndexQueue::record([$child -> itemId], true, false);
SearchIndexQueue::recordCounts([$child -> itemId]);
ItemSearchIndex::clear();
drain();
check('full synchronization restores a missing document', 1, count(indexed_items()));
SearchIndexQueue::recordCounts([$parent -> itemId]);
drain();
check('partial synchronization repairs a missing document', 2, count(indexed_items()));

// A competing consumer must leave pending work for the lock owner.
mysqli_query($other, 'SELECT GET_LOCK(CONCAT(\'duskrail-index:\', DATABASE()), 0)');
Item::countInboundLinks([$child -> itemId]);
check('competing consumer does no work', 0, SearchIndexQueue::processPending(1000, true));
check('competing consumer retains pending work', true, SearchIndexQueue::hasPending());
check('competing consumer leaves index untouched', 2, indexed_items()[$child -> itemId]['inc']);
mysqli_query($other, 'SELECT RELEASE_LOCK(CONCAT(\'duskrail-index:\', DATABASE()))');
drain();
check('subsequent consumer catches up', 3, indexed_items()[$child -> itemId]['inc']);

$discoveries = [];
for ($i = 0; $i < 405; $i++) {
    $url = 'https://example.net/child-' . $i;
    $discoveries[$url] = ['url' => new URL($url), 'type' => 'unknown', 'description' => 'widgets', 'count' => 1];
}
$items = Database::transaction(static fn (): array => Item::findOrCreateManyByURL($discoveries));
check('large fixture uses application discovery', 405, count($items));
$links = [];
foreach ($items as $item) {
    $links[$item -> itemId] = 'widgets';
}
Link::createMany($parent -> itemId, $links);
drain();
check('outgoing synchronization spans multiple pages', 405, count(LinkSearchIndex::focusedCandidates('widgets', 1000)));
foreach ($items as $item) {
    Link::create($item -> itemId, $child -> itemId, 'widgets');
}
drain();
SearchIndexQueue::record([$child -> itemId, $parent -> itemId], false, true);
SearchIndexQueue::record([$child -> itemId, $parent -> itemId], false, true);
drain();
check('incoming and outgoing rebuild preserves all edges', 810, (int) mysqli_fetch_row(mysqli_query($search, 'SELECT COUNT(*) FROM duskrail_links'))[0]);
check('external-domain ranking survives rebuild', 1, LinkSearchIndex::matches('widgets', [$child -> itemId])[$child -> itemId]);
Link::createMany($parent -> itemId, $links);
check('rediscovery still repairs derived link metadata', true, SearchIndexQueue::hasPending());
drain();

// Failures retain work and release the cross-process lock.
mysqli_query($search, 'DROP TABLE duskrail_links');
SearchIndexQueue::record([$parent -> itemId], false, true);
$failed = false;
try {
    SearchIndexQueue::processPending(1000, true);
} catch (SearchIndexUnavailable) {
    $failed = true;
}
check('index failure is surfaced', true, $failed);
check('failed work remains queued', true, SearchIndexQueue::hasPending());
check('failure releases consumer lock', 1, (int) mysqli_fetch_row(mysqli_query($other, 'SELECT GET_LOCK(CONCAT(\'duskrail-index:\', DATABASE()), 0)'))[0]);
mysqli_query($other, 'SELECT RELEASE_LOCK(CONCAT(\'duskrail-index:\', DATABASE()))');

echo $passed . ' integration checks passed.' . PHP_EOL;
