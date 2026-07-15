<?php

declare(strict_types=1);

/**
 * Supervises bin/crawler.php: runs WORKER_COUNT of it concurrently, one
 * process per item per worker slot, killing and moving a slot on if its run
 * hangs past TIMEOUT_SECONDS (a stuck network read, a pathological page, ...)
 * rather than letting the whole crawl stall on one item forever. If the
 * *same* item hangs enough times in a row (MAX_HANGS_PER_ITEM), it's deleted
 * - a URL that reliably hangs the crawler isn't a transient fluke, it's not
 * something worth ever retrying.
 *
 * Running several workers at once is safe because Item::nextToCrawl()
 * atomically claims the row it hands back (see claimedUntil) - two workers
 * asking at the same moment can't be given the same item.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

const WORKER_COUNT = 5;
const TIMEOUT_SECONDS = 30.0;
const POLL_INTERVAL_SECONDS = 0.1;
const MAX_HANGS_PER_ITEM = 3;

// SIGINT (Ctrl+C) or SIGTERM (a plain `kill`) means "stop when convenient",
// not "stop now" - crawler.php itself ignores both, so the only thing that
// actually changes here is this flag: no new workers get spawned into freed
// slots, and the main loop exits once every worker still running has
// finished its current item on its own. A genuinely hung worker is still
// SIGKILLed on schedule regardless (see TIMEOUT_SECONDS below) - shutting
// down doesn't mean waiting forever on a stuck one.
$shuttingDown = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$shuttingDown): void {
        $shuttingDown = true;
    });
    pcntl_signal(SIGTERM, function () use (&$shuttingDown): void {
        $shuttingDown = true;
    });
}

function delete_item_by_id(int $itemId): void
{
    $connection = Database::connection();

    $select = mysqli_prepare($connection, '
SELECT *
    FROM `Items`
    WHERE `itemId` = ?
');
    mysqli_stmt_bind_param($select, 'i', $itemId);
    mysqli_stmt_execute($select);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

    if ($row !== null) {
        Item::fromRow($row) -> delete();
    }
}

/**
 * Starts one crawler.php process for the given slot number - passed through
 * as its only argument so it knows which per-slot "what am I working on"
 * file to write (see CURRENT_CRAWL_ITEM_FILE usage in bin/crawler.php).
 */
function start_worker(int $slot): array
{
    $pipes = [];
    $process = proc_open(
        'php ' . escapeshellarg(ROOT_DIR . '/bin/crawler.php') . ' ' . escapeshellarg((string) $slot),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if ($process === false) {
        return ['process' => false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return [
        'process' => $process,
        'pipes' => $pipes,
        'startedAt' => microtime(true),
        'outBuffer' => '',
        'errBuffer' => '',
    ];
}

/**
 * Buffers $chunk against whatever partial line was left over from the last
 * read and writes out only whole, "[slot] "-prefixed lines - reading raw
 * chunks straight through would otherwise interleave partial lines from
 * different workers into unreadable output.
 */
function emit_lines(int $slot, string &$buffer, string $chunk, $target): void
{
    if ($chunk === '') {
        return;
    }

    $buffer .= $chunk;
    $lines = explode("\n", $buffer);
    $buffer = array_pop($lines);

    foreach ($lines as $line) {
        fwrite($target, '[' . $slot . '] ' . $line . "\n");
    }
}

function flush_buffer(int $slot, string &$buffer, $target): void
{
    if ($buffer !== '') {
        fwrite($target, '[' . $slot . '] ' . $buffer . "\n");
        $buffer = '';
    }
}

$hangCounts = [];
$workers = array_fill(0, WORKER_COUNT, null);

echo 'Crawler manager starting ' . WORKER_COUNT . " workers. Press Ctrl+C to stop.\n";

$announcedShutdown = false;

while (true) {
    if ($shuttingDown && !$announcedShutdown) {
        $announcedShutdown = true;
        echo "Shutdown requested - letting in-flight workers finish, not spawning new ones.\n";
    }

    foreach ($workers as $slot => $worker) {
        if ($worker === null && !$shuttingDown) {
            $workers[$slot] = start_worker($slot);

            if ($workers[$slot]['process'] === false) {
                fwrite(STDERR, '[' . $slot . "] Failed to start crawler process.\n");
                $workers[$slot] = null;
            }
        }
    }

    if ($shuttingDown && array_filter($workers) === []) {
        echo "All workers finished, exiting.\n";
        exit(0);
    }

    foreach ($workers as $slot => &$worker) {
        if ($worker === null) {
            continue;
        }

        emit_lines($slot, $worker['outBuffer'], stream_get_contents($worker['pipes'][1]), STDOUT);
        emit_lines($slot, $worker['errBuffer'], stream_get_contents($worker['pipes'][2]), STDERR);

        $timedOut = microtime(true) - $worker['startedAt'] >= TIMEOUT_SECONDS;

        if (!$timedOut && proc_get_status($worker['process'])['running']) {
            continue;
        }

        if ($timedOut) {
            // SIGKILL (9), not SIGTERM - a process hung on a stuck network
            // read may never even notice a SIGTERM.
            proc_terminate($worker['process'], 9);

            $currentItemFile = CURRENT_CRAWL_ITEM_FILE . '-' . $slot;
            $stuckItemId = is_file($currentItemFile) ? (int) file_get_contents($currentItemFile) : null;

            if ($stuckItemId === null) {
                fwrite(STDERR, '[' . $slot . "] Crawler process hung (couldn't tell which item), killed.\n");
            } else {
                $hangCounts[$stuckItemId] = ($hangCounts[$stuckItemId] ?? 0) + 1;
                fwrite(STDERR, '[' . $slot . '] Crawler process hung on itemId ' . $stuckItemId . ' (' . $hangCounts[$stuckItemId] . '/' . MAX_HANGS_PER_ITEM . '), killed.' . "\n");

                if ($hangCounts[$stuckItemId] >= MAX_HANGS_PER_ITEM) {
                    delete_item_by_id($stuckItemId);
                    unset($hangCounts[$stuckItemId]);
                    fwrite(STDERR, '[' . $slot . '] itemId ' . $stuckItemId . ' hung ' . MAX_HANGS_PER_ITEM . " times in a row, deleted.\n");
                }
            }
        }

        flush_buffer($slot, $worker['outBuffer'], STDOUT);
        flush_buffer($slot, $worker['errBuffer'], STDERR);

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);
        $worker = null;
    }
    unset($worker);

    usleep((int) (POLL_INTERVAL_SECONDS * 1_000_000));
}
