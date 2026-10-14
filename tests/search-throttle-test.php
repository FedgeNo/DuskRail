<?php

declare(strict_types=1);

$state = tempnam(sys_get_temp_dir(), 'search-throttle-state-');
$events = tempnam(sys_get_temp_dir(), 'search-throttle-events-');
try {
    $children = [];
    for ($i = 0; $i < 3; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Could not fork throttle test.');
        if ($pid === 0) {
            SearchThrottle::run($state, static function () use ($events): void {
                file_put_contents($events, 'start ' . sprintf('%.6f', microtime(true)) . PHP_EOL, FILE_APPEND | LOCK_EX);
                usleep(150000);
                file_put_contents($events, 'end ' . sprintf('%.6f', microtime(true)) . PHP_EOL, FILE_APPEND | LOCK_EX);
            });
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        assert_same('Concurrent throttle child succeeds', 0, pcntl_wexitstatus($status));
    }
    $rows = array_map(static fn(string $row): array => explode(' ', trim($row)), file($events));
    assert_same('Global throttle prevents overlap', ['start', 'end', 'start', 'end', 'start', 'end'], array_column($rows, 0));
    assert_true('Cooldown starts at first completion', (float) $rows[2][1] - (float) $rows[1][1] >= 2.0);
    assert_true('Cooldown starts at second completion', (float) $rows[4][1] - (float) $rows[3][1] >= 2.0);
    file_put_contents($state, '0');
    try {
        SearchThrottle::run($state, static fn() => throw new RuntimeException('Expected test failure'));
    } catch (RuntimeException $exception) {
        assert_same('Search failure propagates', 'Expected test failure', $exception -> getMessage());
    }
    assert_true('Failed search records completion for cooldown', (float) file_get_contents($state) > microtime(true) - 1);
} finally {
    unlink($state);
    unlink($events);
}
