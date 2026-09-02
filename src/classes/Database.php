<?php

declare(strict_types=1);

class Database
{
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
}
