<?php

declare(strict_types=1);

/** Parses a byte count written as bytes or with a decimal G/T suffix. */
final class ByteSize
{
    private const MULTIPLIERS = [
        'G' => 1_000_000_000,
        'T' => 1_000_000_000_000,
    ];

    public static function bytes(string $value): int
    {
        $value = trim($value);

        if (ctype_digit($value)) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)
            ) {
                throw new \InvalidArgumentException('Byte count exceeds this system\'s integer range.');
            }

            return (int) $value;
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([GT])B?$/i', $value, $matches)) {
            throw new \InvalidArgumentException('Byte count must be an integer or a number followed by G or T.');
        }

        $bytes = (float) $matches[1] * self::MULTIPLIERS[strtoupper($matches[2])];

        if (!is_finite($bytes) || $bytes > PHP_INT_MAX || floor($bytes) !== $bytes) {
            throw new \InvalidArgumentException('Byte count must resolve to a whole number within this system\'s integer range.');
        }

        return (int) $bytes;
    }
}
