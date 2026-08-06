<?php

declare(strict_types=1);

class Env
{
    private static ?array $values = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$values === null) {
            self::$values = self::load();
        }

        return self::$values[$key] ?? $default;
    }

    private static function load(): array
    {
        $path = ROOT_DIR . '/.env';

        // Genuinely absent is fine - a fresh clone hasn't been through
        // bin/install.php yet, and every key has a working default.
        if (!is_file($path)) {
            return [];
        }

        // Present but unreadable is not. .env is deliberately readable by
        // only a couple of accounts, so this is what running as the wrong
        // one looks like - and falling back to defaults there would silently
        // connect to the wrong database, or treat "no login configured" as
        // the real state of an install that has one.
        if (!is_readable($path)) {
            throw new \RuntimeException($path . ' exists but this user (' . current_user_name() . ') cannot read it');
        }

        $values = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        return $values;
    }
}
