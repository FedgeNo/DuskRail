<?php

declare(strict_types=1);

/**
 * Decodes a fetched image and writes a thumbnail for it. Checks the
 * declared dimensions before decoding - a "decompression bomb" image is a
 * tiny file (a few KB) that decodes to an enormous pixel grid (gigabytes in
 * memory), and getimagesizefromstring() reads those dimensions from the
 * header without decoding a single pixel, so the bomb can be refused before
 * imagecreatefromstring() ever touches it.
 */
class ImageLoader
{
    // ~40 megapixels - comfortably above any real photo or screenshot a page
    // would actually serve, well below what a crafted small file can claim.
    private const MAX_PIXELS = 40_000_000;

    private const THUMBNAIL_MAX_DIMENSION = 300;
    private const THUMBNAIL_DIRECTORY = ROOT_DIR . '/thumbnails';

    // A directory with hundreds of thousands of files in it gets slow to
    // work with (listing, backing up, even just `ls`) on most filesystems -
    // sharding into buckets of a few hundred keeps every individual
    // directory small regardless of how large the crawl gets.
    private const ITEMS_PER_SHARD = 500;

    public static function load(string $data, int $itemId): ?\GdImage
    {
        $size = @getimagesizefromstring($data);

        if ($size === false) {
            return null;
        }

        [$width, $height] = $size;

        if ($width <= 0 || $height <= 0 || $width * $height > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($data);

        if ($image === false) {
            return null;
        }

        self::writeThumbnail($image, $itemId);

        return $image;
    }

    private static function writeThumbnail(\GdImage $image, int $itemId): void
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

        $shard = intdiv($itemId, self::ITEMS_PER_SHARD);
        $directory = self::THUMBNAIL_DIRECTORY . '/' . $shard;

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        imagejpeg($thumbnail, $directory . '/' . $itemId . '.jpg', 85);
        imagedestroy($thumbnail);
    }
}
