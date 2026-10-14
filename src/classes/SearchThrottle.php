<?php

declare(strict_types=1);

/** One active search, followed by a global cooldown, across all workers. */
final class SearchThrottle {
    private const INTERVAL_SECONDS = 2.0;
    private const WAIT_SECONDS = 10.0;

    public static function run(string $state_file, callable $search): mixed {
        $state = fopen($state_file, 'c+');
        if ($state === false) throw new RuntimeException('Could not open global search throttle state.');
        $deadline = microtime(true) + self::WAIT_SECONDS;
        try {
            while (!flock($state, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Global search throttle is busy; try again later.');
                usleep(50000);
            }
            $value = trim(stream_get_contents($state));
            if ($value !== '' && (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0)) throw new RuntimeException('Invalid global search throttle state.');
            $ready = (float) $value + self::INTERVAL_SECONDS;
            if ($ready > $deadline) throw new RuntimeException('Global search throttle timestamp is ahead of the current clock.');
            while (($remaining = $ready - microtime(true)) > 0) usleep((int) ceil(min($remaining, 0.1) * 1000000));
            try {
                return $search();
            } finally {
                $timestamp = sprintf('%.6f', microtime(true));
                if (!rewind($state) || fwrite($state, $timestamp) !== strlen($timestamp) || !ftruncate($state, strlen($timestamp)) || !fflush($state)) throw new RuntimeException('Could not save global search throttle state.');
            }
        } finally {
            flock($state, LOCK_UN);
            fclose($state);
        }
    }
}
