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

        if (!is_file($path)) {
            return [];
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
