<?php

declare(strict_types=1);

class Database
{
    private const TRANSACTION_ATTEMPTS = 3;

    private static ?\mysqli $connection = null;

    public static function connection(): \mysqli
    {
        if (self::$connection === null) {
            $config = require ROOT_DIR . '/src/config.php';

            self::$connection = mysqli_connect(
                $config['host'],
                $config['username'],
                $config['password'],
                $config['database'],
                $config['port']
            );

            mysqli_set_charset(self::$connection, 'utf8mb4');
        }

        return self::$connection;
    }

    /**
     * Installer-only connection injection: schema migrations run through the
     * administrator identity supplied for that invocation, never through the
     * permanently configured least-privilege runtime account.
     */
    public static function useConnection(\mysqli $connection): void
    {
        if (self::$connection !== null && self::$connection !== $connection) {
            mysqli_close(self::$connection);
        }

        self::$connection = $connection;
        mysqli_set_charset(self::$connection, 'utf8mb4');
    }

    public static function transaction(callable $work): mixed
    {
        $connection = self::connection();

        for ($attempt = 1; $attempt <= self::TRANSACTION_ATTEMPTS; $attempt++) {
            mysqli_begin_transaction($connection);

            try {
                $result = $work();
                mysqli_commit($connection);

                return $result;
            } catch (\Throwable $exception) {
                mysqli_rollback($connection);

                $retryable = $exception instanceof \mysqli_sql_exception
                    && in_array($exception -> getCode(), [1205, 1213], true);

                if (!$retryable || $attempt === self::TRANSACTION_ATTEMPTS) {
                    throw $exception;
                }

                usleep(random_int(20000, 100000) * $attempt);
            }
        }

        throw new \LogicException('Transaction retry loop ended without returning or throwing.');
    }
}
