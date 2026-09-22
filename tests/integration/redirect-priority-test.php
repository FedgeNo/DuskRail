<?php

declare(strict_types=1);

$directory = realpath($argv[1] ?? '');
if ($directory === false || !preg_match('#^/tmp/duskrail-optimization-tests\.[a-zA-Z0-9]+$#D', $directory)) {
    throw new RuntimeException('Supply a disposable /tmp/duskrail-optimization-tests.* directory.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = mysqli_connect('localhost', 'root', '', '', 0, $directory . '/db.sock');
if (realpath(mysqli_fetch_row(mysqli_query($connection, 'SELECT @@datadir'))[0]) !== $directory . '/data') {
    throw new RuntimeException('Refusing a database outside the disposable directory.');
}
$database = 'redirect_test_' . bin2hex(random_bytes(6));
mysqli_query($connection, 'CREATE DATABASE `' . $database . '`');
mysqli_select_db($connection, $database);
mysqli_query($connection, 'SET SESSION max_statement_time=3, innodb_lock_wait_timeout=1');

// Exercise the real merge against SQL without touching a search service or files.
class SearchIndexQueue {
    public static function record(array $ids, bool $items, bool $links): void {}
    public static function processPending(): void {}
}
class ImageLoader {
    public static function deleteThumbnail(int $id): void {}
}
require __DIR__ . '/../../init.php';
Database::useConnection($connection);
$schema = proc_open(
    ['mariadb', '--no-defaults', '--socket=' . $directory . '/db.sock', '--user=root', $database],
    [0 => ['file', ROOT_DIR . '/schema.sql', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes
);
if (!is_resource($schema) || proc_close($schema) !== 0) throw new RuntimeException('Could not load schema.');

function check(string $label, mixed $expected, mixed $actual): void {
    if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    fwrite(STDOUT, 'PASS ' . $label . PHP_EOL);
}
function fixture(string $path, int $priority): Item {
    $url = 'https://redirect.example/' . $path;
    $host = Host::findOrCreateByName('redirect.example');
    $insert = Database::connection() -> prepare('INSERT INTO Items (url, hostId, type, crawlPriority) VALUES (?, ?, \'text/html\', ?)');
    $insert -> bind_param('sii', $url, $host -> hostId, $priority);
    $insert -> execute();
    return Item::findById((int) Database::connection() -> insert_id);
}

$source = fixture('source', 255);
$request = InteractiveRetrieval::start($source);
$target = fixture('target', 0);
$survivor = $source -> redirectTo(new URL($target -> url));
check('merge uses existing target', $target -> itemId, $survivor -> itemId);
check('interactive priority survives hydration', 255, $survivor -> crawlPriority);
check('interactive priority persists for retries', 255, Item::findById($target -> itemId) -> crawlPriority);
check('merged source is removed', null, Item::findById($source -> itemId));
$redirect = $connection -> prepare('SELECT targetItemId FROM SourceLedgerRedirects WHERE sourceItemId = ?');
$redirect -> bind_param('i', $source -> itemId);
$redirect -> execute();
check('integration alias points at survivor', $target -> itemId, (int) $redirect -> get_result() -> fetch_row()[0]);

$next = fixture('next', 0);
$survivor = $survivor -> redirectTo(new URL($next -> url));
check('priority survives another merge', 255, $survivor -> crawlPriority);
check('deadline survives redirect merges', $request -> deadline, InteractiveRetrieval::find($source -> itemId) -> deadline);
check('deadline follows the surviving item', $next -> itemId, InteractiveRetrieval::find($source -> itemId) -> itemId);
$redirect -> execute();
check('original alias survives multiple merges', $next -> itemId, (int) $redirect -> get_result() -> fetch_row()[0]);

$ordinary = fixture('ordinary', 0);
$priority_target = fixture('priority-target', 255);
check('ordinary redirect preserves target priority', 255, $ordinary -> redirectTo(new URL($priority_target -> url)) -> crawlPriority);
$ordinary = fixture('ordinary-two', 0);
$ordinary_target = fixture('ordinary-target', 0);
check('ordinary redirects remain ordinary', 0, $ordinary -> redirectTo(new URL($ordinary_target -> url)) -> crawlPriority);
$fresh = fixture('fresh', 255);
check('same-row redirect keeps priority', 255, $fresh -> redirectTo(new URL('https://redirect.example/new-path')) -> crawlPriority);

InteractiveRetrieval::workerFinished($request -> requestItemId, $request -> deadline, 'HTTP 429');
check('failure is recorded for caller', 'HTTP 429', InteractiveRetrieval::find($source -> itemId) -> failure);
check('failed request returns to ordinary priority', 0, Item::findById($next -> itemId) -> crawlPriority);
check('failed URL remains stored', $next -> url, Item::findById($next -> itemId) -> url);
check('failure does not tombstone URL', null, DeadURL::reasonFor($next -> url));
$new_request = InteractiveRetrieval::start($next);
check('explicit new request has no old failure', null, $new_request -> failure);

$expired = fixture('expired', 255);
$expired_request = InteractiveRetrieval::start($expired);
$stmt = $connection -> prepare('UPDATE InteractiveRetrievals SET deadline = UNIX_TIMESTAMP() - 1 WHERE requestItemId = ?');
$stmt -> bind_param('i', $expired -> itemId);
$stmt -> execute();
InteractiveRetrieval::expire();
check('expiry clears interactive priority', 0, Item::findById($expired -> itemId) -> crawlPriority);
check('expiry records a failure', true, InteractiveRetrieval::find($expired -> itemId) -> failure !== null);
check('expiry preserves URL', $expired -> url, Item::findById($expired -> itemId) -> url);
