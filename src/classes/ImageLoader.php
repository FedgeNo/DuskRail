<?php

declare(strict_types=1);

/**
 * Decodes a fetched image and writes a thumbnail for it. Checks the
 * declared dimensions before decoding - a "decompression bomb" image is a
 * tiny file (a few KB) that decodes to an enormous pixel grid (gigabytes in
 * memory), and getimagesizefromstring() reads those dimensions from the
 * header without decoding a single pixel, so the bomb can be refused before
 * imagecreatefromstring() ever touches it.
 *
 * SVG goes a different way (loadSVG()): GD can't decode it at all, so it's
 * rendered by the same shared headless Chrome instance every fetch already
 * goes through, and the resulting bitmap comes back through the identical
 * dimension checks and thumbnailing as any other image.
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
    private const THUMBNAIL_DIRECTORY = ROOT_DIR . '/thumbnails';

    // A directory with hundreds of thousands of files in it gets slow to
    // work with (listing, backing up, even just `ls`) on most filesystems -
    // sharding into buckets of a few hundred keeps every individual
    // directory small regardless of how large the crawl gets.
    private const ITEMS_PER_SHARD = 500;

    /**
     * The site-relative URL an item's thumbnail is (or, if it hasn't been
     * crawled/decoded successfully yet, would be) written to - single source
     * of truth for the sharding scheme, so nothing else has to duplicate
     * ITEMS_PER_SHARD to construct this path itself. Null for anything that
     * isn't an image, since only images ever get one.
     */
    public static function thumbnailURL(int $itemId, ?string $type): ?string
    {
        if ($type === null || !str_starts_with($type, 'image/')) {
            return null;
        }

        return '/thumbnails/' . intdiv($itemId, self::ITEMS_PER_SHARD) . '/' . $itemId . '.jpg';
    }

    public static function load(string $data, int $itemId): ?\GdImage
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

        self::writeThumbnail($image, $itemId);

        return $image;
    }

    /**
     * An SVG, rendered to a bitmap by the shared browser and then treated as
     * any other image. SVG is a real and common class of page content -
     * diagrams, logos, charts - that GD has no decoder for at all, so it was
     * being discarded outright along with genuinely broken images.
     *
     * Rendering rather than storing the SVG as-is is deliberate. An SVG is a
     * document: it can carry <script>, event handlers and foreign objects,
     * and serving one from this site's own origin would run all of that with
     * this site's permissions. A rasterized copy carries none of it, and the
     * rendering happens in Chrome's sandbox rather than in PHP.
     */
    public static function loadSVG(string $data, int $itemId, string $chromeEndpoint): ?\GdImage
    {
        $png = SVGRasterizer::toPNG($chromeEndpoint, $data);

        return $png !== null ? self::load($png, $itemId) : null;
    }

    /**
     * Removes an item's thumbnail, if it ever had one. Called when the item
     * itself is deleted - the file is only ever reachable through that row,
     * so leaving it behind just accumulates orphans nothing will ever serve
     * or clean up.
     */
    public static function deleteThumbnail(int $itemId): void
    {
        $path = self::THUMBNAIL_DIRECTORY . '/' . intdiv($itemId, self::ITEMS_PER_SHARD) . '/' . $itemId . '.jpg';

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
