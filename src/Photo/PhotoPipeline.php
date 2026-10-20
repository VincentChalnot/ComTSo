<?php

namespace App\Photo;

/**
 * The only way an image enters the photo library (uploads, `app:photos:reprocess`):
 *
 * 1. the header is inspected WITHOUT decoding the pixels: real type (never the client's), dimensions, pixel bomb check;
 * 2. EXIF is read from the untouched original (before it is lost by the re-encoding);
 * 3. the picture is decoded, rotated according to EXIF, downscaled to at most {@see self::TARGET_PIXELS} (never upscaled)
 *    and stored as WebP: that file is "the original" from then on (it also drops embedded payloads of polyglot files);
 * 4. animated GIFs are the exception: they are kept as GIF, byte for byte (re-encoding would flatten them to one frame),
 *    and are refused when larger than the target size instead of being downscaled.

 * The stored file is never rewritten afterwards: a member's quarter turns are stored as {@see \App\Entity\Photo::getRotation()}
 * and applied when the thumbnails are generated ({@see \App\Service\ImageProcessingService}).
 */
final class PhotoPipeline
{
    /** A source with more pixels than this is refused before any decoding (decompression bomb). */
    public const MAX_SOURCE_PIXELS = 100_000_000;
    /** Longest side accepted in a source (WebP itself is limited to 16383 px). */
    public const MAX_SIDE = 30_000;
    /** Stored pictures never exceed this area. */
    public const TARGET_PIXELS = 10_000_000;
    /** ... nor this long side. */
    public const TARGET_SIDE = 8_000;
    public const WEBP_QUALITY = 85;

    private const TYPES = [\IMAGETYPE_JPEG => 'image/jpeg', \IMAGETYPE_PNG => 'image/png', \IMAGETYPE_GIF => 'image/gif', \IMAGETYPE_WEBP => 'image/webp'];

    public function __construct(private readonly ExifReader $exif)
    {
    }

    /**
     * Reads the image header only.
     *
     * @return array{int, int, int} width, height, IMAGETYPE_* constant
     *
     * @throws \InvalidArgumentException French message when the file is not an acceptable image
     */
    public function inspect(string $path): array
    {
        set_error_handler(static fn (): bool => true);
        try {
            $info = getimagesize($path);
        } finally {
            restore_error_handler();
        }
        if (false === $info || !isset(self::TYPES[$info[2]])) {
            throw new \InvalidArgumentException('Seules les images JPEG, PNG, GIF et WebP sont acceptées.');
        }
        [$width, $height, $type] = $info;
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Cette image est vide ou illisible.');
        }
        if ($width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_SOURCE_PIXELS) {
            throw new \InvalidArgumentException(sprintf(
                'Cette image est trop grande (%d × %d pixels) : %d mégapixels au maximum.',
                $width,
                $height,
                self::MAX_SOURCE_PIXELS / 1_000_000,
            ));
        }

        return [$width, $height, $type];
    }

    /**
     * Stores a copy of an image in `$directory` following the pipeline above. `$path` is left untouched.
     *
     * @throws \InvalidArgumentException French message when the image is refused
     */
    public function ingest(string $path, string $directory): ProcessedPhoto
    {
        [$width, $height, $type] = $this->inspect($path);
        $originalSize = (int) filesize($path);
        $metadata = $this->exif->read($path);
        $source = ['OriginalWidth' => $width, 'OriginalHeight' => $height, 'OriginalFileSize' => $originalSize, 'OriginalMimeType' => self::TYPES[$type]];

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        if (\IMAGETYPE_GIF === $type && self::isAnimatedGif($path)) {
            if ($width * $height > self::TARGET_PIXELS) {
                throw new \InvalidArgumentException('Ce GIF animé est trop grand : 10 mégapixels au maximum.');
            }
            $filename = $this->freeName($directory, 'gif');
            if (!copy($path, $directory.'/'.$filename)) {
                throw new \InvalidArgumentException('La photo n\'a pas pu être enregistrée.');
            }

            return new ProcessedPhoto($filename, 'image/gif', (int) filesize($directory.'/'.$filename), $width, $height, $metadata->withExif($source + ['Animated' => 1]));
        }

        [$filename, $size, $storedWidth, $storedHeight] = $this->render($path, $type, $width, $height, $metadata->orientation, $directory);

        return new ProcessedPhoto($filename, 'image/webp', $size, $storedWidth, $storedHeight, $metadata->withExif($source));
    }

    /**
     * Decodes, applies the EXIF orientation, caps the size and writes the WebP.
     *
     * @return array{string, int, int, int} file name, file size, width, height of what was stored
     */
    private function render(string $path, int $type, int $width, int $height, int $orientation, string $directory): array
    {
        $image = $this->decode($path, $type, $width, $height);
        try {
            $image = $this->orient($image, $orientation);
            $image = $this->shrink($image);
            $storedWidth = imagesx($image);
            $storedHeight = imagesy($image);

            $filename = $this->freeName($directory, 'webp');
            if (!imagewebp($image, $directory.'/'.$filename, self::WEBP_QUALITY)) {
                @unlink($directory.'/'.$filename);
                throw new \InvalidArgumentException('La photo n\'a pas pu être enregistrée.');
            }
        } finally {
            unset($image);
        }
        // GD writes a zero-length file when the encoder runs out of memory.
        $size = (int) filesize($directory.'/'.$filename);
        if ($size < 1) {
            @unlink($directory.'/'.$filename);
            throw new \InvalidArgumentException('La photo n\'a pas pu être enregistrée.');
        }

        return [$filename, $size, $storedWidth, $storedHeight];
    }

    public static function isAnimatedGif(string $path): bool
    {
        $data = @file_get_contents($path);

        return false !== $data && preg_match_all('~\x21\xF9\x04.{4}\x00[\x2C\x21]~s', $data) > 1;
    }

    /** True when {@see ingest()} would change nothing: already a WebP within the size caps, or an animated GIF within the cap. */
    public function isUpToDate(string $path): bool
    {
        try {
            [$width, $height, $type] = $this->inspect($path);
        } catch (\InvalidArgumentException) {
            return true; // nothing the pipeline could do with it
        }
        if (\IMAGETYPE_GIF === $type) {
            return self::isAnimatedGif($path) && $width * $height <= self::TARGET_PIXELS;
        }

        return \IMAGETYPE_WEBP === $type && $width * $height <= self::TARGET_PIXELS && max($width, $height) <= self::TARGET_SIDE;
    }

    private function decode(string $path, int $type, int $width, int $height): \GdImage
    {
        // GD holds 4 bytes per pixel (more for some JPEGs) and the resized copies come on top: refuse what cannot fit
        // instead of dying with a fatal "allowed memory size exhausted" that no code can catch.
        $needed = (int) ($width * $height * 4 * 1.3) + 3 * self::TARGET_PIXELS * 4 + 16 * 1024 * 1024;
        $limit = self::memoryLimit();
        if (null !== $limit && $needed > $limit - memory_get_usage(true)) {
            throw new \InvalidArgumentException(sprintf('Cette image (%d × %d pixels) est trop grande pour être traitée.', $width, $height));
        }

        set_error_handler(static fn (): bool => true);
        try {
            $image = match ($type) {
                \IMAGETYPE_JPEG => imagecreatefromjpeg($path),
                \IMAGETYPE_PNG => imagecreatefrompng($path),
                \IMAGETYPE_GIF => imagecreatefromgif($path),
                default => imagecreatefromwebp($path),
            };
        } catch (\Throwable) {
            $image = false;
        } finally {
            restore_error_handler();
        }
        if (false === $image) {
            throw new \InvalidArgumentException('Cette image est corrompue ou illisible.');
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }

    /** Applies the EXIF orientation (1-8) so that the stored picture needs no metadata to be displayed upright. */
    private function orient(\GdImage $image, int $orientation): \GdImage
    {
        if (\in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, 4 === $orientation ? \IMG_FLIP_VERTICAL : \IMG_FLIP_HORIZONTAL);
        }
        // GD rotates counter-clockwise.
        $angle = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        return 0 === $angle ? $image : $this->turn($image, $angle);
    }

    /** @param int $angle GD angle in degrees, counter-clockwise */
    private function turn(\GdImage $image, int $angle): \GdImage
    {
        $rotated = imagerotate($image, $angle, imagecolorallocatealpha($image, 0, 0, 0, 127));
        if (false === $rotated) {
            throw new \InvalidArgumentException('Cette image n\'a pas pu être traitée.');
        }
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function shrink(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, sqrt(self::TARGET_PIXELS / ($width * $height)), self::TARGET_SIDE / max($width, $height));
        if ($scale >= 1.0) {
            return $image;
        }

        $target = imagecreatetruecolor(max(1, (int) floor($width * $scale)), max(1, (int) floor($height * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $image, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        return $target;
    }

    private function freeName(string $directory, string $extension): string
    {
        do {
            $name = uniqid().'.'.$extension;
        } while (is_file($directory.'/'.$name));

        return $name;
    }

    /** memory_limit in bytes, null when unlimited. */
    private static function memoryLimit(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ('' === $value || '-1' === $value) {
            return null;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
