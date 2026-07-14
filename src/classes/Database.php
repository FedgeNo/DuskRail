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
}
