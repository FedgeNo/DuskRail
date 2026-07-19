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
 * Also launches and owns the one persistent, shared headless Chrome instance
 * every real fetch goes through (ChromeConnection, HeadlessBrowser) - its
 * "host:port" is written to CHROME_DEVTOOLS_ENDPOINT_FILE for each fresh
 * bin/crawler.php worker to read. Health-checked every idle tick and rotated
 * (launched fresh) either on a crash or once it's run MAX_CHROME_AGE_SECONDS.
 * Concurrent workers each open their own tab (ChromeTab, its own isolated
 * browser context) in this same shared browser - cheap to open/close per
 * fetch, but not free, so WORKER_COUNT should be picked with the machine's
 * actual headroom in mind.
 *
 * A rotation doesn't tear down the outgoing Chrome instance the moment its
 * replacement is up - with more than one worker, there's usually *some*
 * worker mid-fetch against it at any given moment, so "wait until every slot
 * happens to be idle at once" could mean never rotating at all during a busy
 * stretch. Instead each running worker is tagged with the Chrome generation
 * it was dispatched against (see $chromeInstances/$chromeGeneration below);
 * a superseded generation is only actually shut down once every worker
 * tagged with it has finished, however long that drain takes.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

// Each concurrent worker opens its own tab in the one shared Chrome instance
// - see this file's own docblock on why this isn't "however many CPU cores".
const WORKER_COUNT = 3;
const TIMEOUT_SECONDS = 30.0;
const POLL_INTERVAL_SECONDS = 0.1;
const MAX_HANGS_PER_ITEM = 3;

// "Every hour or so" - long enough to amortize Chrome's ~1-2 second startup
// cost across many crawled items, short enough that a slow memory leak or
// other multi-hour degradation never gets the chance to matter.
const MAX_CHROME_AGE_SECONDS = 3600.0;

// Applies both to the very first launch attempt and to every later
// relaunch-after-a-crash - without it, a Chrome that fails to come up at all
// (binary missing, launch erroring) would have every single idle tick retry
// it back-to-back as fast as the loop spins, rather than backing off.
const CHROME_RETRY_COOLDOWN_SECONDS = 5.0;

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
 * Launches a fresh Chrome instance and publishes its endpoint for
 * bin/crawler.php workers to read, or null (logging why) if it couldn't be
 * started - a missing binary or a launch failure, never something worth
 * retrying immediately (see CHROME_RETRY_COOLDOWN_SECONDS at the call site).
 */
function launch_chrome(): ?ChromeProcess
{
    try {
        $chrome = new ChromeProcess();
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Failed to launch Chrome: ' . $exception -> getMessage() . '
');
        @unlink(CHROME_DEVTOOLS_ENDPOINT_FILE);

        return null;
    }

    file_put_contents(CHROME_DEVTOOLS_ENDPOINT_FILE, $chrome -> hostAndPort);
    echo 'Chrome instance ready at ' . $chrome -> hostAndPort . '
';

    return $chrome;
}

/**
 * Ensures $chromeInstances[$currentGeneration] is healthy and hasn't aged
 * out, rotating in a fresh generation (launched and published BEFORE the
 * outgoing one is even considered for teardown, so there's never a moment
 * where the endpoint file points at nothing usable) if not. The outgoing
 * generation is left in $chromeInstances with whatever refCount it already
 * has - see release_chrome_reference(), which is what actually shuts it down
 * once every worker still tagged with it has finished - never torn down
 * here even if that count happens to be 0 already (that just means nothing
 * was using it at this exact instant, not that nothing ever will again
 * before the next check). Returns false (leaving $lastAttemptAt untouched)
 * if a real replacement attempt is due but still within its retry cooldown,
 * or if the attempt itself just failed - true otherwise, including when the
 * existing generation was already fine as-is.
 */
function ensure_current_chrome_generation(array &$chromeInstances, int &$currentGeneration, float &$lastAttemptAt): bool
{
    $current = $chromeInstances[$currentGeneration] ?? null;

    if ($current !== null && $current['process'] -> isHealthy() && $current['process'] -> ageSeconds() < MAX_CHROME_AGE_SECONDS) {
        return true;
    }

    if (microtime(true) - $lastAttemptAt < CHROME_RETRY_COOLDOWN_SECONDS) {
        return $current !== null && $current['process'] -> isHealthy();
    }

    $lastAttemptAt = microtime(true);
    $replacement = launch_chrome();

    if ($replacement === null) {
        return $current !== null && $current['process'] -> isHealthy();
    }

    if ($current !== null) {
        echo 'Rotating Chrome instance (' . ($current['process'] -> isHealthy() ? 'scheduled rotation' : 'unhealthy')
            . '), draining ' . $current['refCount'] . ' in-flight worker(s) still tagged with it.
';
    }

    $currentGeneration++;
    $chromeInstances[$currentGeneration] = ['process' => $replacement, 'refCount' => 0];

    return true;
}

/**
 * Drops one worker's claim on $generation, shutting it down once nothing's
 * tagged with it anymore - but never the current generation just because
 * it's momentarily idle between dispatches, only ever a superseded one that
 * ensure_current_chrome_generation() has already moved past.
 */
function release_chrome_reference(array &$chromeInstances, int $generation, int $currentGeneration): void
{
    if (!isset($chromeInstances[$generation])) {
        return;
    }

    $chromeInstances[$generation]['refCount']--;

    if ($generation !== $currentGeneration && $chromeInstances[$generation]['refCount'] <= 0) {
        $chromeInstances[$generation]['process'] -> shutdown();
        unset($chromeInstances[$generation]);
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
    $lines = explode('
', $buffer);
    $buffer = array_pop($lines);

    foreach ($lines as $line) {
        fwrite($target, '[' . $slot . '] ' . $line . '
');
    }
}

function flush_buffer(int $slot, string &$buffer, $target): void
{
    if ($buffer !== '') {
        fwrite($target, '[' . $slot . '] ' . $buffer . '
');
        $buffer = '';
    }
}

$hangCounts = [];
$workers = array_fill(0, WORKER_COUNT, null);

echo 'Crawler manager starting ' . WORKER_COUNT . ' worker(s). Press Ctrl+C to stop.
';

$chromeGeneration = 0;
$chromeInstances = [];
$lastChromeAttemptAt = 0.0;

if (!ensure_current_chrome_generation($chromeInstances, $chromeGeneration, $lastChromeAttemptAt)) {
    fwrite(STDERR, 'Couldn\'t launch Chrome, exiting.
');
    exit(1);
}

$announcedShutdown = false;

while (true) {
    if ($shuttingDown && !$announcedShutdown) {
        $announcedShutdown = true;
        echo 'Shutdown requested - letting in-flight workers finish, not spawning new ones.
';
    }

    foreach ($workers as $slot => $worker) {
        if ($worker === null && !$shuttingDown) {
            if (!ensure_current_chrome_generation($chromeInstances, $chromeGeneration, $lastChromeAttemptAt)) {
                continue;
            }

            $workers[$slot] = start_worker($slot);

            if ($workers[$slot]['process'] === false) {
                fwrite(STDERR, '[' . $slot . '] Failed to start crawler process.
');
                $workers[$slot] = null;
            } else {
                $workers[$slot]['chromeGeneration'] = $chromeGeneration;
                $chromeInstances[$chromeGeneration]['refCount']++;
            }
        }
    }

    if ($shuttingDown && array_filter($workers) === []) {
        echo 'All workers finished, exiting.
';

        foreach ($chromeInstances as $instance) {
            $instance['process'] -> shutdown();
        }

        @unlink(CHROME_DEVTOOLS_ENDPOINT_FILE);
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
                fwrite(STDERR, '[' . $slot . '] Crawler process hung (couldn\'t tell which item), killed.
');
            } else {
                $hangCounts[$stuckItemId] = ($hangCounts[$stuckItemId] ?? 0) + 1;
                fwrite(STDERR, '[' . $slot . '] Crawler process hung on itemId ' . $stuckItemId . ' (' . $hangCounts[$stuckItemId] . '/' . MAX_HANGS_PER_ITEM . '), killed.' . '
');

                if ($hangCounts[$stuckItemId] >= MAX_HANGS_PER_ITEM) {
                    delete_item_by_id($stuckItemId);
                    unset($hangCounts[$stuckItemId]);
                    fwrite(STDERR, '[' . $slot . '] itemId ' . $stuckItemId . ' hung ' . MAX_HANGS_PER_ITEM . ' times in a row, deleted.
');
                }
            }
        } else {
            // Normal (non-timeout) exit: whatever item this slot just finished
            // (crawled, deleted, redirected away, or skipped) is no longer
            // stuck, so drop its hang counter. Without this a one-off earlier
            // hang would linger forever, and - worse - a later unrelated hang
            // could tip an item that actually succeeded in between over the
            // deletion threshold. An empty file (this run found nothing to
            // crawl, see bin/crawler.php) reads as 0 and is skipped, leaving a
            // genuinely-hung item's count intact rather than resetting it.
            $currentItemFile = CURRENT_CRAWL_ITEM_FILE . '-' . $slot;
            $finishedItemId = is_file($currentItemFile) ? (int) file_get_contents($currentItemFile) : 0;

            if ($finishedItemId > 0) {
                unset($hangCounts[$finishedItemId]);
            }
        }

        flush_buffer($slot, $worker['outBuffer'], STDOUT);
        flush_buffer($slot, $worker['errBuffer'], STDERR);

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);
        release_chrome_reference($chromeInstances, $worker['chromeGeneration'], $chromeGeneration);
        $worker = null;
    }
    unset($worker);

    usleep((int) (POLL_INTERVAL_SECONDS * 1_000_000));
}
