<?php

namespace App\Tests\Support;

/**
 * Tiny JPEG fixtures carrying hand-made EXIF: `ExifJpeg::build(300, 200, ['Orientation' => 6], …)` returns the bytes.
 * Tags are given by name; values are int (SHORT), string (ASCII), `[n, d]` or a list of `[n, d]` (RATIONAL), or `bytes('..')`.
 */
final class ExifJpeg
{
    private const IFD0 = ['Make' => 0x010F, 'Model' => 0x0110, 'Orientation' => 0x0112, 'Software' => 0x0131, 'DateTime' => 0x0132, 'Artist' => 0x013B, 'Copyright' => 0x8298, 'ImageDescription' => 0x010E];
    private const EXIF = ['ExposureTime' => 0x829A, 'FNumber' => 0x829D, 'ISOSpeedRatings' => 0x8827, 'DateTimeOriginal' => 0x9003, 'DateTimeDigitized' => 0x9004, 'OffsetTimeOriginal' => 0x9011, 'ExposureBiasValue' => 0x9204, 'MeteringMode' => 0x9207, 'Flash' => 0x9209, 'FocalLength' => 0x920A, 'ColorSpace' => 0xA001, 'WhiteBalance' => 0xA403, 'FocalLengthIn35mmFilm' => 0xA405, 'SceneCaptureType' => 0xA406, 'LensMake' => 0xA433, 'LensModel' => 0xA434, 'ExposureProgram' => 0x8822];
    private const GPS = ['GPSLatitudeRef' => 1, 'GPSLatitude' => 2, 'GPSLongitudeRef' => 3, 'GPSLongitude' => 4, 'GPSAltitudeRef' => 5, 'GPSAltitude' => 6, 'GPSTimeStamp' => 7, 'GPSSpeedRef' => 12, 'GPSSpeed' => 13, 'GPSImgDirection' => 17, 'GPSDateStamp' => 29];

    /** @param array<string, mixed> $tags any of the tag names above, mixed (IFD0, Exif and GPS tags are sorted out) */
    public static function build(int $width, int $height, array $tags = [], int $quality = 85): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        // A red block in the top-left corner shows where "up" went after an orientation fix.
        imagefilledrectangle($image, 0, 0, (int) ($width / 4), (int) ($height / 4), imagecolorallocate($image, 230, 20, 20));
        ob_start();
        imagejpeg($image, null, $quality);
        $jpeg = (string) ob_get_clean();

        if (!$tags) {
            return $jpeg;
        }

        return substr($jpeg, 0, 2).self::app1(self::tiff(
            array_intersect_key($tags, self::IFD0),
            array_intersect_key($tags, self::EXIF),
            array_intersect_key($tags, self::GPS),
        )).substr($jpeg, 2);
    }

    public static function bytes(string $raw): array
    {
        return ['bytes' => $raw];
    }

    private static function app1(string $tiff): string
    {
        return "\xFF\xE1".pack('n', 2 + 6 + \strlen($tiff))."Exif\0\0".$tiff;
    }

    /** @param array<string, mixed> $ifd0 @param array<string, mixed> $exif @param array<string, mixed> $gps */
    private static function tiff(array $ifd0, array $exif, array $gps): string
    {
        $ifd0Entries = self::entries($ifd0, self::IFD0);
        $exifEntries = self::entries($exif, self::EXIF);
        $gpsEntries = self::entries($gps, self::GPS);
        // Pointers first with a dummy offset: IFD sizes do not depend on offsets.
        if ($exifEntries) {
            $ifd0Entries[0x8769] = ['long', 0];
        }
        if ($gpsEntries) {
            $ifd0Entries[0x8825] = ['long', 0];
        }
        $size0 = \strlen(self::ifd($ifd0Entries, 8));
        $exifOffset = 8 + $size0;
        $gpsOffset = $exifOffset + ($exifEntries ? \strlen(self::ifd($exifEntries, $exifOffset)) : 0);
        if ($exifEntries) {
            $ifd0Entries[0x8769] = ['long', $exifOffset];
        }
        if ($gpsEntries) {
            $ifd0Entries[0x8825] = ['long', $gpsOffset];
        }

        return "II*\0".pack('V', 8).self::ifd($ifd0Entries, 8).($exifEntries ? self::ifd($exifEntries, $exifOffset) : '').($gpsEntries ? self::ifd($gpsEntries, $gpsOffset) : '');
    }

    /** @param array<string, mixed> $values @param array<string, int> $map @return array<int, array{string, mixed}> */
    private static function entries(array $values, array $map): array
    {
        $entries = [];
        foreach ($values as $name => $value) {
            $entries[$map[$name]] = match (true) {
                \is_int($value) => ['short', $value],
                \is_string($value) => ['ascii', $value],
                isset($value['bytes']) => ['byte', $value['bytes']],
                isset($value[0]) && \is_array($value[0]) => ['rational', $value],
                default => ['rational', [$value]],
            };
        }

        return $entries;
    }

    /** @param array<int, array{string, mixed}> $entries */
    private static function ifd(array $entries, int $offset): string
    {
        ksort($entries);
        $dataOffset = $offset + 2 + \count($entries) * 12 + 4;
        $table = pack('v', \count($entries));
        $data = '';
        foreach ($entries as $tag => [$type, $value]) {
            [$code, $count, $payload] = match ($type) {
                'ascii' => [2, \strlen($value) + 1, $value."\0"],
                'short' => [3, 1, pack('v', $value)."\0\0"],
                'long' => [4, 1, pack('V', $value)],
                'byte' => [1, \strlen($value), $value],
                'rational' => [5, \count($value), implode('', array_map(static fn (array $r): string => pack('VV', $r[0], $r[1]), $value))],
            };
            $table .= pack('vvV', $tag, $code, $count);
            if (\strlen($payload) <= 4) {
                $table .= str_pad($payload, 4, "\0");
            } else {
                $table .= pack('V', $dataOffset + \strlen($data));
                $data .= $payload.(\strlen($payload) % 2 ? "\0" : '');
            }
        }

        return $table.pack('V', 0).$data;
    }
}
