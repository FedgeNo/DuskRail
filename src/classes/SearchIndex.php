<?php

declare(strict_types=1);

abstract class SearchIndex
{
    private static ?\mysqli $connection = null;

    protected static function connection(): \mysqli
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $config = require ROOT_DIR . '/src/config.php';

        if ($config['manticoreUsername'] === '' || $config['manticorePassword'] === '') {
            throw new SearchIndexUnavailable('Manticore credentials are not configured.');
        }

        try {
            $connection = mysqli_init();
            mysqli_options($connection, MYSQLI_OPT_CONNECT_TIMEOUT, 2);
            mysqli_real_connect(
                $connection,
                $config['manticoreHost'],
                $config['manticoreUsername'],
                $config['manticorePassword'],
                '',
                $config['manticorePort']
            );
            self::$connection = $connection;
        } catch (\Throwable $exception) {
            throw new SearchIndexUnavailable('Manticore connection failed: ' . $exception -> getMessage(), 0, $exception);
        }

        return self::$connection;
    }

    protected static function rows(string $sql, string $types = '', mixed ...$values): array
    {
        try {
            $statement = mysqli_prepare(self::connection(), $sql);

            if ($types !== '') {
                mysqli_stmt_bind_param($statement, $types, ...$values);
            }

            mysqli_stmt_execute($statement);
            $result = mysqli_stmt_get_result($statement);

            return $result !== false ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
        } catch (SearchIndexUnavailable $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            self::$connection = null;
            throw new SearchIndexUnavailable('Manticore query failed: ' . $exception -> getMessage(), 0, $exception);
        }
    }

    protected static function run(string $sql, string $types = '', mixed ...$values): void
    {
        self::rows($sql, $types, ...$values);
    }

    public static function installSchema(string $path): void
    {
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new SearchIndexUnavailable('Could not read the Manticore schema.');
        }

        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        foreach (explode(';', (string) $sql) as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                self::run($statement);
            }
        }
    }

    protected static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, $count, '?'));
    }

    /**
     * Converts public input to Manticore's expression language without
     * allowing the input itself to introduce operators. Bare terms keep the
     * current natural-language any-word behavior; quoted groups stay exact
     * phrases.
     */
    public static function matchExpression(string $query): string
    {
        preg_match_all('/"([^"]+)"|([\p{L}\p{N}]+)/u', mb_substr(trim($query), 0, SearchResults::MAX_QUERY_LENGTH), $matches, PREG_SET_ORDER);
        $parts = [];

        foreach ($matches as $match) {
            $phrase = $match[1] ?? '';

            if ($phrase !== '') {
                preg_match_all('/[\p{L}\p{N}]+/u', $phrase, $words);

                if (($words[0] ?? []) !== []) {
                    $parts[] = '"' . implode(' ', $words[0]) . '"';
                }

                continue;
            }

            if (($match[2] ?? '') !== '') {
                $parts[] = $match[2];
            }
        }

        return implode(' | ', $parts);
    }
}
