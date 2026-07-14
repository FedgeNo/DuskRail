<?php

declare(strict_types=1);

class ServerURL
{
    private static ?string $siteURL = null;

    public static function absolute(string $path): string
    {
        if (self::$siteURL === null) {
            $config = require ROOT_DIR . '/src/config.php';
            self::$siteURL = rtrim($config['siteURL'], '/');
        }

        return self::$siteURL . $path;
    }
}
