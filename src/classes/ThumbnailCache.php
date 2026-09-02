<?php

declare(strict_types=1);

/** Fetches and stores a missing thumbnail from the image Item's recorded URL. */
final class ThumbnailCache
{
    public const ON_DEMAND_TIMEOUT_SECONDS = 8;
    public const MAXIMUM_USED_RATIO = 0.95;

    private const LOCK_TIMEOUT_SECONDS = self::ON_DEMAND_TIMEOUT_SECONDS + 2;
    private const MAXIMUM_REDIRECTS = 3;
    private const FAILURE_COOLDOWN_SECONDS = 300;
    private const CONCURRENT_FETCHES_PER_CLIENT = 18;

    private static ?bool $writeCapacity = null;

    public static function useWriteCapacity(?bool $available): void
    {
        self::$writeCapacity = $available;
    }

    public static function storageAllowsWrite(
        float $free_bytes,
        float $total_bytes,
        ?int $minimum_free_bytes = null
    ): bool
    {
        if ($free_bytes < 0 || $total_bytes <= 0 || $free_bytes > $total_bytes) {
            return false;
        }

        if ($minimum_free_bytes === null) {
            $minimum_free_bytes = self::minimumFreeBytes();
        }

        return $free_bytes >= $minimum_free_bytes
            && 1 - ($free_bytes / $total_bytes) < self::MAXIMUM_USED_RATIO;
    }

    public static function minimumFreeBytes(): int
    {
        $config = require ROOT_DIR . '/src/config.php';
        try {
            return ByteSize::bytes($config['thumbnailMinimumFreeBytes']);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(
                'Invalid THUMBNAIL_MINIMUM_FREE_BYTES: ' . $exception -> getMessage(),
                0,
                $exception
            );
        }
    }

    public static function thumbnail(int $item_id): ?ThumbnailPayload
    {
        $file = ImageLoader::thumbnailFile($item_id);

        if (is_file($file)) {
            return new ThumbnailPayload($file, null);
        }

        $item_lock = 'thumbnail:' . $item_id;

        if (!self::acquireLock($item_lock, self::LOCK_TIMEOUT_SECONDS)) {
            return null;
        }

        try {
            if (is_file($file)) {
                return new ThumbnailPayload($file, null);
            }

            if (self::recentlyFailed($item_id)) {
                return null;
            }

            $item = self::item($item_id);

            if ($item === null) {
                return null;
            }

            $slot = self::acquireFetchSlot();

            if ($slot === null) {
                return null;
            }

            try {
                $bytes = self::fetch((string) $item -> url);
            } finally {
                self::releaseLock($slot);
            }

            $thumbnail = $bytes !== null ? ImageLoader::thumbnailBytes($bytes) : null;

            if ($thumbnail === null) {
                self::recordFailure($item_id);

                return null;
            }

            self::clearFailure($item_id);

            if (!self::hasWriteCapacity()) {
                return new ThumbnailPayload(null, $thumbnail);
            }

            return new ThumbnailPayload(ImageLoader::storeThumbnail($item_id, $thumbnail), null);
        } finally {
            self::releaseLock($item_lock);
        }
    }

    private static function item(int $item_id): ?\stdClass
    {
        $select = mysqli_prepare(Database::connection(), '
SELECT `url`
    FROM `Items`
    WHERE `itemId` = ?
        AND `crawledTime` IS NOT NULL
        AND `type` LIKE \'image/%\'
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 'i', $item_id);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($select));

        return $row !== null ? (object) $row : null;
    }

    private static function fetch(string $source_url): ?string
    {
        $url = new URL($source_url);
        $deadline = microtime(true) + self::ON_DEMAND_TIMEOUT_SECONDS;
        $visited = [];

        for ($redirects = 0; $redirects <= self::MAXIMUM_REDIRECTS; $redirects++) {
            if (!$url -> isValid() || isset($visited[$url -> toString()])) {
                return null;
            }

            $visited[$url -> toString()] = true;
            $remaining_seconds = (int) ceil($deadline - microtime(true));

            if ($remaining_seconds < 1) {
                return null;
            }

            $connection = new HTTPConnection($url, $remaining_seconds);

            if ($connection -> statusCode !== null
                && $connection -> statusCode >= 300
                && $connection -> statusCode < 400
            ) {
                $location = $connection -> headers['location'] ?? null;
                $connection -> readBody();

                if ($location === null) {
                    return null;
                }

                $url = $url -> resolve(new URL($location));
                continue;
            }

            $content_type = $connection -> contentType();

            if ($connection -> statusCode === null
                || $connection -> statusCode < 200
                || $connection -> statusCode >= 300
                || $content_type === null
                || !$content_type -> isImage()
            ) {
                $connection -> readBody();

                return null;
            }

            $bytes = $connection -> readBody();

            return !$connection -> bodyTruncated && $bytes !== '' ? $bytes : null;
        }

        return null;
    }

    private static function hasWriteCapacity(): bool
    {
        if (self::$writeCapacity !== null) {
            return self::$writeCapacity;
        }

        $free_bytes = disk_free_space(ImageLoader::thumbnailDirectory());
        $total_bytes = disk_total_space(ImageLoader::thumbnailDirectory());

        return $free_bytes !== false
            && $total_bytes !== false
            && self::storageAllowsWrite($free_bytes, $total_bytes);
    }

    private static function recentlyFailed(int $item_id): bool
    {
        $after = time() - self::FAILURE_COOLDOWN_SECONDS;
        $select = mysqli_prepare(Database::connection(), '
SELECT 1
    FROM `ThumbnailFetchFailures`
    WHERE `itemId` = ? AND `failedTime` > ?
    LIMIT 1
');
        mysqli_stmt_bind_param($select, 'ii', $item_id, $after);
        mysqli_stmt_execute($select);

        return mysqli_fetch_assoc(mysqli_stmt_get_result($select)) !== null;
    }

    private static function recordFailure(int $item_id): void
    {
        $failed_time = time();
        $insert = mysqli_prepare(Database::connection(), '
INSERT INTO `ThumbnailFetchFailures` (`itemId`, `failedTime`)
    VALUES (?, ?)
    ON DUPLICATE KEY UPDATE `failedTime` = VALUES(`failedTime`)
');
        mysqli_stmt_bind_param($insert, 'ii', $item_id, $failed_time);
        mysqli_stmt_execute($insert);
    }

    private static function clearFailure(int $item_id): void
    {
        $delete = mysqli_prepare(Database::connection(), '
DELETE FROM `ThumbnailFetchFailures`
    WHERE `itemId` = ?
');
        mysqli_stmt_bind_param($delete, 'i', $item_id);
        mysqli_stmt_execute($delete);
    }

    private static function acquireFetchSlot(): ?string
    {
        $client = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        if ($client === '') {
            return '';
        }

        $prefix = 'thumbnail-client:' . hash('sha256', $client) . ':';

        for ($slot = 0; $slot < self::CONCURRENT_FETCHES_PER_CLIENT; $slot++) {
            $key = $prefix . $slot;

            if (self::acquireLock($key, 0)) {
                return $key;
            }
        }

        return null;
    }

    private static function acquireLock(string $key, int $timeout_seconds): bool
    {
        $name = 'duskrail:' . md5($key);
        $select = mysqli_prepare(Database::connection(), '
SELECT GET_LOCK(?, ?)
');
        mysqli_stmt_bind_param($select, 'si', $name, $timeout_seconds);
        mysqli_stmt_execute($select);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($select));

        return $row !== null && (int) $row[0] === 1;
    }

    private static function releaseLock(string $key): void
    {
        if ($key === '') {
            return;
        }

        $name = 'duskrail:' . md5($key);
        $select = mysqli_prepare(Database::connection(), '
SELECT RELEASE_LOCK(?)
');
        mysqli_stmt_bind_param($select, 's', $name);
        mysqli_stmt_execute($select);
        mysqli_stmt_get_result($select);
    }
}
