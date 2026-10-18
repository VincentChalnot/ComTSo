<?php

namespace App\Photo;

/**
 * Extracts the useful EXIF data of an image file into a {@see PhotoMetadata}: a curated, flat, UTF-8-clean map
 * (rationals and GPS degrees/minutes/seconds are normalised to floats; MakerNote, thumbnails and every other binary
 * blob are dropped), the shooting date, the GPS position and the orientation. Broken or missing EXIF never throws:
 * the result is simply empty.
 */
final class ExifReader
{
    private const STRINGS = ['Make', 'Model', 'LensMake', 'LensModel', 'Software', 'Artist', 'Copyright', 'ImageDescription', 'OffsetTimeOriginal'];
    private const INTEGERS = ['ISOSpeedRatings', 'FocalLengthIn35mmFilm', 'ExposureProgram', 'MeteringMode', 'Flash', 'WhiteBalance', 'SceneCaptureType', 'ColorSpace'];
    private const RATIONALS = ['FNumber', 'FocalLength', 'ExposureBiasValue'];
    private const SPEED_TO_KMH = ['K' => 1.0, 'M' => 1.609344, 'N' => 1.852];

    public function read(string $path): PhotoMetadata
    {
        $raw = $this->readRaw($path);
        if (!$raw) {
            return new PhotoMetadata();
        }

        try {
            return $this->normalise($raw);
        } catch (\Throwable) {
            return new PhotoMetadata();
        }
    }

    /** @return array<string, mixed>|null */
    private function readRaw(string $path): ?array
    {
        if (!\function_exists('exif_read_data') || !is_file($path)) {
            return null;
        }
        $type = @getimagesize($path)[2] ?? null;
        if (!\in_array($type, [\IMAGETYPE_JPEG, \IMAGETYPE_TIFF_II, \IMAGETYPE_TIFF_MM, \IMAGETYPE_WEBP], true)) {
            return null;
        }

        // Camera EXIF is routinely malformed: PHP warns (even notices) about it, which must not become exceptions.
        set_error_handler(static fn (): bool => true);
        try {
            $raw = exif_read_data($path, 'ANY_TAG', false);
        } catch (\Throwable) {
            $raw = false;
        } finally {
            restore_error_handler();
        }

        return \is_array($raw) ? $raw : null;
    }

    /** @param array<string, mixed> $raw */
    private function normalise(array $raw): PhotoMetadata
    {
        // PHP's EXIF parser does not know the lens tags (EXIF 2.3): they come out under their number.
        $raw += ['LensMake' => $raw['UndefinedTag:0xA433'] ?? null, 'LensModel' => $raw['UndefinedTag:0xA434'] ?? null, 'OffsetTimeOriginal' => $raw['UndefinedTag:0x9011'] ?? null];
        $exif = [];

        foreach (self::STRINGS as $key) {
            if (null !== $value = self::text($raw[$key] ?? null)) {
                $exif[$key] = $value;
            }
        }

        $taken = self::date($raw['DateTimeOriginal'] ?? null) ?? self::date($raw['DateTimeDigitized'] ?? null);
        if ($original = self::date($raw['DateTimeOriginal'] ?? null)) {
            $exif['DateTimeOriginal'] = $original->format('Y-m-d H:i:s');
        }
        if ($digitized = self::date($raw['DateTimeDigitized'] ?? null)) {
            $exif['CreateDate'] = $digitized->format('Y-m-d H:i:s');
        }

        if (null !== $time = self::rational($raw['ExposureTime'] ?? null)) {
            if ($time > 0) {
                $exif['ExposureTime'] = round($time, 6);
                $exif['ExposureTimeFraction'] = self::fraction($time);
            }
        }
        foreach (self::RATIONALS as $key) {
            if (null !== $value = self::rational($raw[$key] ?? null)) {
                $exif[$key] = round($value, 4);
            }
        }
        foreach (self::INTEGERS as $key) {
            if (null !== $value = self::integer($raw[$key] ?? null)) {
                $exif[$key] = $value;
            }
        }

        $orientation = self::integer($raw['Orientation'] ?? null);
        if (null !== $orientation && $orientation >= 1 && $orientation <= 8) {
            $exif['Orientation'] = $orientation;
        } else {
            $orientation = 1;
        }

        [$latitude, $longitude, $altitude] = $this->gps($raw, $exif);

        return new PhotoMetadata($taken, $latitude, $longitude, $altitude, $exif, $orientation);
    }

    /**
     * @param array<string, mixed>       $raw
     * @param array<string, scalar>      $exif
     *
     * @return array{?float, ?float, ?float}
     */
    private function gps(array $raw, array &$exif): array
    {
        $latitude = self::coordinate($raw['GPSLatitude'] ?? null, $raw['GPSLatitudeRef'] ?? null, 'S', 90.0);
        $longitude = self::coordinate($raw['GPSLongitude'] ?? null, $raw['GPSLongitudeRef'] ?? null, 'W', 180.0);
        // 0°,0° is what phones write when they have no fix.
        if (null === $latitude || null === $longitude || (0.0 === $latitude && 0.0 === $longitude)) {
            return [null, null, null];
        }
        $exif['GPSLatitude'] = $latitude;
        $exif['GPSLongitude'] = $longitude;

        $altitude = self::rational($raw['GPSAltitude'] ?? null);
        if (null !== $altitude) {
            // GPSAltitudeRef is a single byte: 1 = below sea level.
            $below = \is_string($raw['GPSAltitudeRef'] ?? null) && 1 === \strlen($raw['GPSAltitudeRef']) ? 1 === \ord($raw['GPSAltitudeRef']) : 1 === self::integer($raw['GPSAltitudeRef'] ?? null);
            $altitude = round($below ? -$altitude : $altitude, 2);
            $exif['GPSAltitude'] = $altitude;
        }
        if (null !== $direction = self::rational($raw['GPSImgDirection'] ?? null)) {
            $exif['GPSImgDirection'] = round($direction, 2);
        }
        if (null !== $speed = self::rational($raw['GPSSpeed'] ?? null)) {
            $exif['GPSSpeed'] = round($speed * (self::SPEED_TO_KMH[strtoupper((string) self::text($raw['GPSSpeedRef'] ?? 'K'))] ?? 1.0), 2);
        }
        if (\is_string($raw['GPSDateStamp'] ?? null) && ($stamp = self::date($raw['GPSDateStamp'].' 00:00:00'))) {
            $exif['GPSDateStamp'] = $stamp->format('Y-m-d');
        }
        if (\is_array($raw['GPSTimeStamp'] ?? null) && 3 === \count($raw['GPSTimeStamp'])) {
            [$h, $m, $s] = array_map(self::rational(...), array_values($raw['GPSTimeStamp']));
            if (null !== $h && null !== $m && null !== $s) {
                $exif['GPSTimeStamp'] = sprintf('%02d:%02d:%02d', (int) $h, (int) $m, (int) $s);
            }
        }

        return [$latitude, $longitude, $altitude];
    }

    /** "n/d", "n", int or float to float; zero denominators and garbage give null. */
    public static function rational(mixed $value): ?float
    {
        if (\is_array($value)) {
            $value = reset($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (!\is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('~^(-?\d+(?:\.\d+)?)\s*/\s*(-?\d+(?:\.\d+)?)$~', $value, $m)) {
            return 0.0 == (float) $m[2] ? null : (float) $m[1] / (float) $m[2];
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** Degrees, minutes, seconds + hemisphere to signed decimal degrees. */
    private static function coordinate(mixed $dms, mixed $reference, string $negative, float $limit): ?float
    {
        if (!\is_array($dms) || 3 !== \count($dms)) {
            return null;
        }
        [$d, $m, $s] = array_map(self::rational(...), array_values($dms));
        if (null === $d || null === $m || null === $s) {
            return null;
        }
        $value = abs($d) + $m / 60 + $s / 3600;
        if (strtoupper(trim((string) self::text($reference))) === $negative) {
            $value = -$value;
        }

        return abs($value) <= $limit ? round($value, 6) : null;
    }

    /** 0.0303 => "1/33", 2.5 => "2.5". */
    public static function fraction(float $seconds): string
    {
        if ($seconds >= 1) {
            return rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.');
        }

        return '1/'.max(1, (int) round(1 / $seconds));
    }

    private static function integer(mixed $value): ?int
    {
        if (\is_array($value)) {
            $value = reset($value);
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || !preg_match('~^(\d{4})[:-](\d{2})[:-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})~', $value, $m) || '0000' === $m[1]) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ? new \DateTimeImmutable(sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]), new \DateTimeZone('Europe/Paris'))
            : null;
    }

    /** Valid UTF-8 without control characters, trimmed and bounded; null when nothing is left. */
    public static function text(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        // Cameras pad with NULs and sometimes write Windows-1252.
        $value = rtrim($value, "\0 ");
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }
        $value = trim(preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+~', '', $value) ?? '');

        return '' === $value ? null : mb_substr($value, 0, 500);
    }
}
