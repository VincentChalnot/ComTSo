<?php

namespace App\Service;

use Imagine\Filter\Basic\Autorotate;
use Imagine\Gd\Imagine as GdImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;

/** Lazily generated, cached thumbnails (always WebP) of the stored photos. Stored photos themselves are produced by App\Photo\PhotoPipeline. */
class ImageProcessingService
{
    /** Thumbnail variants: name => [width, height, mode]. "cover" crops to fill, "inset" fits inside the box. */
    public const VARIANTS = [
        'thumb' => [320, 320, 'cover'],
        'medium' => [1400, 1400, 'inset'],
        'hero' => [1600, 1600, 'inset'],
        'avatar' => [96, 96, 'cover'],
    ];

    public const VARIANT_EXTENSION = 'webp';
    public const VARIANT_MIME_TYPE = 'image/webp';

    public function __construct(
        private readonly string $photosDirectory,
        private readonly string $cacheDirectory,
    ) {
    }

    /**
     * Returns the path of the (lazily generated, cached) WebP thumbnail of a stored photo, or null when the
     * variant is unknown or the original is missing/unreadable.
     */
    public function thumbnailPath(string $filename, string $variant): ?string
    {
        if (!isset(self::VARIANTS[$variant]) || $filename !== basename($filename)) {
            return null;
        }

        $source = $this->originalPath($filename);
        if (!is_file($source)) {
            return null;
        }

        $target = $this->cacheDirectory.'/'.$variant.'/'.pathinfo($filename, PATHINFO_FILENAME).'.'.self::VARIANT_EXTENSION;
        if (is_file($target) && filemtime($target) >= filemtime($source)) {
            return $target;
        }

        [$width, $height, $mode] = self::VARIANTS[$variant];
        try {
            $image = (new GdImagine())->open($source);
            // Photos stored by the pipeline are already upright; legacy JPEGs not yet reprocessed may still carry an orientation.
            if (\in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg'], true)) {
                (new Autorotate())->apply($image);
            }
            $size = $image->getSize();
            if ('inset' === $mode && $size->getWidth() <= $width && $size->getHeight() <= $height) {
                // Never upscale: serve a re-encoded copy at the original size.
                $out = $image;
            } else {
                $out = $image->thumbnail(new Box($width, $height), 'cover' === $mode ? ImageInterface::THUMBNAIL_OUTBOUND : ImageInterface::THUMBNAIL_INSET);
            }
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0775, true);
            }
            $out->save($target, ['format' => 'webp', 'webp_quality' => 82]);
        } catch (\Throwable) {
            return null;
        }

        return $target;
    }

    public function cacheDirectory(): string
    {
        return $this->cacheDirectory;
    }

    public function originalPath(string $filename): string
    {
        return $this->photosDirectory.'/'.$filename;
    }
}
