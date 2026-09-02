<?php

declare(strict_types=1);

/**
 * Decodes fetched image bytes into a bounded JPEG thumbnail. Checks the
 * declared dimensions before decoding - a "decompression bomb" image is a
 * tiny file (a few KB) that decodes to an enormous pixel grid (gigabytes in
 * memory), and getimagesizefromstring() reads those dimensions from the
 * header without decoding a single pixel, so the bomb can be refused before
 * imagecreatefromstring() ever touches it.
 *
 * Nothing here fetches. ThumbnailCache supplies bytes only when a reader asks
 * for an uncached thumbnail.
 */
class ImageLoader
{
    // ~40 megapixels - comfortably above any real photo or screenshot a page
    // would actually serve, well below what a crafted small file can claim.
    private const MAX_PIXELS = 40_000_000;

    // Below this on either side, it's a tracking pixel, spacer gif, or
    // decorative icon rather than real presentable content - not worth a
    // search result. Comfortably below a real favicon-sized logo (usually
    // 32-64px) while still catching 1x1s and other tiny junk.
    private const MIN_DIMENSION = 100;

    private const THUMBNAIL_MAX_DIMENSION = 300;

    private static ?string $thumbnailDirectory = null;

    /**
     * The site-relative URL an item's thumbnail is (or, if it hasn't been
     * crawled/decoded successfully yet, would be) written to - single source
     * of truth for the sharding scheme. Null for anything that isn't an
     * image, since only images ever get one.
     */
    public static function thumbnailURL(int $item_id, ?string $type): ?string
    {
        if ($type === null || !str_starts_with($type, 'image/')) {
            return null;
        }

        return '/thumbnails/' . self::shard($item_id) . '/' . $item_id . '.jpg';
    }

    public static function thumbnailDirectory(): string
    {
        if (self::$thumbnailDirectory === null) {
            $config = require ROOT_DIR . '/src/config.php';
            $directory = rtrim($config['thumbnailDirectory'], '/');

            if (!preg_match('~^/[A-Za-z0-9._/-]+$~D', $directory)) {
                throw new \RuntimeException('THUMBNAIL_DIRECTORY must be an absolute path containing only letters, numbers, dot, underscore, hyphen, and slash.');
            }

            self::$thumbnailDirectory = $directory;
        }

        return self::$thumbnailDirectory;
    }

    public static function thumbnailBytes(string $data): ?string
    {
        $size = @getimagesizefromstring($data);

        if ($size === false) {
            return null;
        }

        [$width, $height] = $size;

        if (!self::areDimensionsUsable($width, $height)) {
            return null;
        }

        $image = @imagecreatefromstring($data);

        if ($image === false) {
            return null;
        }

        $bytes = self::resizedJPEG($image);
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Removes an item's thumbnail, if it ever had one. Called when the item
     * itself is deleted - the file is only ever reachable through that row,
     * so leaving it behind just accumulates orphans nothing will ever serve
     * or clean up.
     */
    public static function deleteThumbnail(int $item_id): void
    {
        $path = self::thumbnailFile($item_id);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function areDimensionsUsable(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            return false;
        }

        return $width >= self::MIN_DIMENSION && $height >= self::MIN_DIMENSION;
    }

    public static function storeThumbnail(int $item_id, string $bytes): string
    {
        $file = self::thumbnailFile($item_id);
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create thumbnail directory ' . $directory . '.');
        }

        $partial = $file . '.' . bin2hex(random_bytes(6));

        if (file_put_contents($partial, $bytes) === false || !rename($partial, $file)) {
            if (is_file($partial)) {
                unlink($partial);
            }

            throw new \RuntimeException('Could not write thumbnail ' . $file . '.');
        }

        return $file;
    }

    public static function thumbnailFile(int $item_id): string
    {
        return self::thumbnailDirectory() . '/' . self::shard($item_id) . '/' . $item_id . '.jpg';
    }

    private static function resizedJPEG(\GdImage $image): ?string
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min(1, self::THUMBNAIL_MAX_DIMENSION / max($width, $height));
        $thumbnailWidth = max(1, (int) round($width * $scale));
        $thumbnailHeight = max(1, (int) round($height * $scale));

        $thumbnail = imagecreatetruecolor($thumbnailWidth, $thumbnailHeight);

        // JPEG has no alpha channel - flatten onto white first so a
        // transparent source (a PNG logo, say) doesn't fall back to GD's
        // default black canvas underneath it.
        $white = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefill($thumbnail, 0, 0, $white);
        imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbnailWidth, $thumbnailHeight, $width, $height);

        ob_start();
        $written = imagejpeg($thumbnail, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($thumbnail);

        return $written && is_string($bytes) ? $bytes : null;
    }

    /** Three base-100 directory levels, each named from 00 through 99. */
    private static function shard(int $item_id): string
    {
        return sprintf(
            '%02d/%02d/%02d',
            $item_id % 100,
            intdiv($item_id, 100) % 100,
            intdiv($item_id, 10_000) % 100
        );
    }
}
