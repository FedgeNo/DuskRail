<?php

declare(strict_types=1);

/**
 * Test runner: `php bin/test.php`. Includes every file in tests/ and reports
 * one line per failure, a summary, and a non-zero exit code when anything
 * failed. No framework - the assertions below are the whole vocabulary, and
 * a test file is plain PHP that calls them.
 *
 * Tests cover the pure logic - URL parsing and resolution, robots.txt
 * matching, HTML extraction, text handling. Nothing here touches the
 * database: the classes under test are constructible bare, and keeping the
 * suite dependency-free is what keeps it a two-second habit instead of a
 * chore with setup.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../init.php';

$GLOBALS['testsPassed'] = 0;
$GLOBALS['testsFailed'] = 0;

function assert_same(string $label, mixed $expected, mixed $actual): void
{
    if ($expected === $actual) {
        $GLOBALS['testsPassed']++;

        return;
    }

    $GLOBALS['testsFailed']++;
    fwrite(STDERR, '[FAIL] ' . $label . '
    expected: ' . var_export($expected, true) . '
    actual:   ' . var_export($actual, true) . '
');
}

function assert_true(string $label, bool $actual): void
{
    assert_same($label, true, $actual);
}

function assert_false(string $label, bool $actual): void
{
    assert_same($label, false, $actual);
}

foreach (glob(__DIR__ . '/../tests/*.php') ?: [] as $testFile) {
    require $testFile;
}

echo $GLOBALS['testsPassed'] . ' passed, ' . $GLOBALS['testsFailed'] . ' failed.
';

exit($GLOBALS['testsFailed'] === 0 ? 0 : 1);
