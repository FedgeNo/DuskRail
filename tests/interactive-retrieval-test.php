<?php

declare(strict_types=1);

$request = new InteractiveRetrieval();
$request -> deadline = 1000;
$item = new Item();
$item -> claimedUntil = 1200;
assert_same('interactive retrieval budget is one minute', 60, InteractiveRetrieval::TIMEOUT_SECONDS);
assert_same('active attempt is reading before deadline', 'reading', $request -> state($item, 999));
assert_same('reading cannot outlast deadline', 'failed', $request -> state($item, 1000));
$item -> claimedUntil = null;
assert_same('queued cannot outlast deadline', 'failed', $request -> state($item, 1000));
$item -> crawledTime = 999;
assert_same('completed in budget remains available', 'available', $request -> state($item, 1001));
$item -> crawledTime = 1001;
assert_same('late completion cannot revive timed-out attempt', 'failed', $request -> state($item, 1002));
$item -> crawledTime = 999;
$request -> failure = 'HTTP 429';
assert_same('explicit failure is terminal', 'failed', $request -> state($item, 999));
