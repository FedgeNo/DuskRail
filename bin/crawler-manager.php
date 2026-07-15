<?php

declare(strict_types=1);

/**
 * Supervises bin/crawler.php: runs it repeatedly, one process per item,
 * killing and moving on if a single run hangs past TIMEOUT_SECONDS (a stuck
 * network read, a pathological page, ...) rather than letting the whole
 * crawl stall on one item forever. If the *same* item hangs enough times in
 * a row (MAX_HANGS_PER_ITEM), it's deleted - a URL that reliably hangs the
 * crawler isn't a transient fluke, it's not something worth ever retrying.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

const TIMEOUT_SECONDS = 20.0;
const POLL_INTERVAL_SECONDS = 0.1;
const MAX_HANGS_PER_ITEM = 3;

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

$hangCounts = [];

echo "Crawler manager starting. Press Ctrl+C to stop.\n";

while (true) {
    $pipes = [];
    $process = proc_open(
        'php ' . escapeshellarg(ROOT_DIR . '/bin/crawler.php'),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if ($process === false) {
        fwrite(STDERR, "Failed to start crawler process.\n");
        sleep(1);
        continue;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $startedAt = microtime(true);
    $timedOut = false;

    while (true) {
        echo stream_get_contents($pipes[1]);
        fwrite(STDERR, stream_get_contents($pipes[2]));

        if (!proc_get_status($process)['running']) {
            break;
        }

        if (microtime(true) - $startedAt >= TIMEOUT_SECONDS) {
            $timedOut = true;
            break;
        }

        usleep((int) (POLL_INTERVAL_SECONDS * 1_000_000));
    }

    if ($timedOut) {
        // SIGKILL (9), not SIGTERM - a process hung on a stuck network read
        // may never even notice a SIGTERM.
        proc_terminate($process, 9);

        $stuckItemId = is_file(CURRENT_CRAWL_ITEM_FILE) ? (int) file_get_contents(CURRENT_CRAWL_ITEM_FILE) : null;

        if ($stuckItemId === null) {
            fwrite(STDERR, "Crawler process hung (couldn't tell which item), killed.\n");
        } else {
            $hangCounts[$stuckItemId] = ($hangCounts[$stuckItemId] ?? 0) + 1;
            fwrite(STDERR, 'Crawler process hung on itemId ' . $stuckItemId . ' (' . $hangCounts[$stuckItemId] . '/' . MAX_HANGS_PER_ITEM . '), killed.' . "\n");

            if ($hangCounts[$stuckItemId] >= MAX_HANGS_PER_ITEM) {
                delete_item_by_id($stuckItemId);
                unset($hangCounts[$stuckItemId]);
                fwrite(STDERR, 'itemId ' . $stuckItemId . ' hung ' . MAX_HANGS_PER_ITEM . " times in a row, deleted.\n");
            }
        }
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
