<?php

declare(strict_types=1);

// Only the network fetch and image decoder are stubs. Slot ownership and
// item/failure queries run against an isolated MariaDB instance.
final class HTTPConnection {
    public ?int $statusCode = 200;
    public bool $bodyTruncated = false;

    public function __construct(URL $url, int $timeout) {
        check_budget('fetch');
    }

    public function contentType(): ContentType {
        return new ContentType('image/jpeg');
    }

    public function readBody(): string {
        return 'fixture bytes';
    }
}

final class ImageLoader {
    public static function thumbnailFile(int $id): string {
        return $GLOBALS['directory'] . '/thumbnail-' . $id . '.jpg';
    }

    public static function thumbnailBytes(string $bytes): string {
        check_budget('decode');

        if ($GLOBALS['throw_decode']) {
            throw new RuntimeException('fixture decode exception');
        }

        return 'fixture JPEG';
    }
}

$directory = realpath($argv[1] ?? '');
if ($directory === false || !preg_match('#^/tmp/duskrail-optimization-tests\.[a-zA-Z0-9]+$#D', $directory)) {
    throw new RuntimeException('Supply a disposable test directory.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = mysqli_connect('localhost', 'root', '', '', 0, $directory . '/db.sock');
if (realpath(mysqli_fetch_row(mysqli_query($connection, 'SELECT @@datadir'))[0]) !== $directory . '/data') {
    throw new RuntimeException('Refusing a database outside the disposable directory.');
}
$database = 'duskrail_test_' . bin2hex(random_bytes(6));
mysqli_query($connection, 'CREATE DATABASE `' . $database . '`');
mysqli_select_db($connection, $database);
mysqli_query($connection, 'SET SESSION max_statement_time=3, innodb_lock_wait_timeout=1');
require __DIR__ . '/../../init.php';
Database::useConnection($connection);
$schema = proc_open(['mariadb', '--no-defaults', '--socket=' . $directory . '/db.sock', '--user=root', $database], [0 => ['file', ROOT_DIR . '/schema.sql', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($schema) || proc_close($schema) !== 0) {
    throw new RuntimeException('Test schema failed.');
}
$other = mysqli_connect('localhost', 'root', '', $database, 0, $directory . '/db.sock');
mysqli_query($other, 'SET SESSION max_statement_time=3');
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$passed = 0;
$throw_decode = false;

function check(string $label, mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $GLOBALS['passed']++;
}

function lock_name(string $key): string {
    return 'duskrail:' . md5($key);
}

function used_slots(): int {
    $used = 0;
    for ($i = 0; $i < 18; $i++) {
        $name = lock_name('thumbnail-global:' . $i);
        $select = mysqli_prepare($GLOBALS['other'], 'SELECT IS_USED_LOCK(?)');
        mysqli_stmt_bind_param($select, 's', $name);
        mysqli_stmt_execute($select);
        $used += mysqli_fetch_row(mysqli_stmt_get_result($select))[0] !== null ? 1 : 0;
    }
    return $used;
}

function check_budget(string $stage): void {
    check($stage . ' holds global slot', 1, used_slots());
    $name = lock_name('thumbnail-client:' . hash('sha256', $_SERVER['REMOTE_ADDR']) . ':0');
    $select = mysqli_prepare($GLOBALS['other'], 'SELECT IS_USED_LOCK(?)');
    mysqli_stmt_bind_param($select, 's', $name);
    mysqli_stmt_execute($select);
    check($stage . ' holds client slot', true, mysqli_fetch_row(mysqli_stmt_get_result($select))[0] !== null);
}

function check_released(int $item_id): void {
    foreach (['thumbnail:' . $item_id, 'thumbnail-client:' . hash('sha256', $_SERVER['REMOTE_ADDR']) . ':0'] as $key) {
        $name = lock_name($key);
        $select = mysqli_prepare($GLOBALS['other'], 'SELECT IS_USED_LOCK(?)');
        mysqli_stmt_bind_param($select, 's', $name);
        mysqli_stmt_execute($select);
        check('item/client lock released', null, mysqli_fetch_row(mysqli_stmt_get_result($select))[0]);
    }
}

$item = Item::findOrCreateByURL(new URL('https://example.org/photo'), 'image/jpeg');
$item -> markCrawled('image/jpeg', null, null, null, null, null, 0, false);
ThumbnailCache::useWriteCapacity(false);
for ($i = 0; $i < 18; $i++) {
    $name = lock_name('thumbnail-global:' . $i);
    $select = mysqli_prepare($other, 'SELECT GET_LOCK(?, 0)');
    mysqli_stmt_bind_param($select, 's', $name);
    mysqli_stmt_execute($select);
    mysqli_stmt_get_result($select);
}
$busy = false;
try {
    ThumbnailCache::thumbnail($item -> itemId);
} catch (ThumbnailBusy) {
    $busy = true;
}
check('a different client cannot exceed the global budget', true, $busy);
check_released($item -> itemId);
check('contention does not poison the source cooldown', 0, (int) mysqli_fetch_row(mysqli_query($connection, 'SELECT COUNT(*) FROM `ThumbnailFetchFailures`'))[0]);
mysqli_query($other, 'SELECT RELEASE_ALL_LOCKS()');
$payload = ThumbnailCache::thumbnail($item -> itemId);
ob_start();
$payload -> send();
check('released capacity serves the original image', 'fixture JPEG', ob_get_clean());
check('successful processing releases global slots', 0, used_slots());
check_released($item -> itemId);
$throw_decode = true;
$failed = false;
try {
    ThumbnailCache::thumbnail($item -> itemId);
} catch (RuntimeException $exception) {
    $failed = $exception -> getMessage() === 'fixture decode exception';
}
check('decoder errors are surfaced', true, $failed);
check('decoder errors release global slots', 0, used_slots());
check_released($item -> itemId);
echo $passed . ' thumbnail budget checks passed.' . PHP_EOL;
