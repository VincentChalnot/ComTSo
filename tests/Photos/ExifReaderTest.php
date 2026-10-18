<?php

namespace App\Tests\Photos;

use App\Photo\ExifReader;
use App\Tests\Support\ExifJpeg;
use PHPUnit\Framework\TestCase;

final class ExifReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/exif-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private function read(string $bytes): \App\Photo\PhotoMetadata
    {
        file_put_contents($path = $this->dir.'/photo.jpg', $bytes);

        return (new ExifReader())->read($path);
    }

    public function testFullDataOfAPhone(): void
    {
        $metadata = $this->read(ExifJpeg::build(120, 80, [
            'Make' => 'samsung', 'Model' => 'SM-A310F', 'Software' => 'A310FXXU', 'Orientation' => 6, 'Artist' => 'Vincent',
            'DateTimeOriginal' => '2018:10:20 13:22:05', 'OffsetTimeOriginal' => '+02:00',
            'ExposureTime' => [1, 33], 'FNumber' => [19, 10], 'ISOSpeedRatings' => 160, 'FocalLength' => [37, 10], 'FocalLengthIn35mmFilm' => 27,
            'ExposureBiasValue' => [1, 3], 'Flash' => 16, 'WhiteBalance' => 0, 'ColorSpace' => 1, 'LensModel' => 'Selfie lens',
        ]));

        self::assertSame('2018-10-20 13:22:05', $metadata->takenAt->format('Y-m-d H:i:s'));
        self::assertSame(6, $metadata->orientation);
        $exif = $metadata->exif;
        self::assertSame('SM-A310F', $exif['Model']);
        self::assertSame('samsung', $exif['Make']);
        self::assertSame('1/33', $exif['ExposureTimeFraction']);
        self::assertEqualsWithDelta(0.030303, $exif['ExposureTime'], 0.000001);
        self::assertSame(1.9, $exif['FNumber']);
        self::assertSame(160, $exif['ISOSpeedRatings']);
        self::assertSame(3.7, $exif['FocalLength']);
        self::assertSame(27, $exif['FocalLengthIn35mmFilm']);
        self::assertEqualsWithDelta(0.3333, $exif['ExposureBiasValue'], 0.0001);
        self::assertSame('2018-10-20 13:22:05', $exif['DateTimeOriginal']);
        self::assertSame('+02:00', $exif['OffsetTimeOriginal']);
        self::assertSame('Vincent', $exif['Artist']);
        self::assertSame('Selfie lens', $exif['LensModel']);
        self::assertArrayNotHasKey('MakerNote', $exif);
        self::assertNull($metadata->latitude, 'No GPS in this file.');
    }

    public function testGpsInTheNorthEast(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40, [
            'GPSLatitudeRef' => 'N', 'GPSLatitude' => [[48, 1], [51, 1], [2340, 100]],
            'GPSLongitudeRef' => 'E', 'GPSLongitude' => [[2, 1], [21, 1], [804, 100]],
            'GPSAltitudeRef' => ExifJpeg::bytes("\0"), 'GPSAltitude' => [356, 10],
            'GPSImgDirection' => [1805, 10], 'GPSSpeedRef' => 'K', 'GPSSpeed' => [12, 1],
            'GPSDateStamp' => '2018:10:20', 'GPSTimeStamp' => [[11, 1], [22, 1], [5, 1]],
        ]));

        self::assertEqualsWithDelta(48.8565, $metadata->latitude, 0.0001);
        self::assertEqualsWithDelta(2.3522, $metadata->longitude, 0.0001);
        self::assertSame(35.6, $metadata->altitude);
        self::assertSame(180.5, $metadata->exif['GPSImgDirection']);
        self::assertSame(12.0, $metadata->exif['GPSSpeed']);
        self::assertSame('2018-10-20', $metadata->exif['GPSDateStamp']);
        self::assertSame('11:22:05', $metadata->exif['GPSTimeStamp']);
    }

    public function testSouthAndWestHemispheresAreNegativeAndBelowSeaLevelToo(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40, [
            'GPSLatitudeRef' => 'S', 'GPSLatitude' => [[33, 1], [52, 1], [0, 1]],
            'GPSLongitudeRef' => 'W', 'GPSLongitude' => [[70, 1], [39, 1], [0, 1]],
            'GPSAltitudeRef' => ExifJpeg::bytes("\x01"), 'GPSAltitude' => [30, 1],
        ]));

        self::assertEqualsWithDelta(-33.866667, $metadata->latitude, 0.000001);
        self::assertEqualsWithDelta(-70.65, $metadata->longitude, 0.000001);
        self::assertSame(-30.0, $metadata->altitude);
    }

    public function testZeroDenominatorsAreDroppedNotFatal(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40, [
            'ExposureTime' => [1, 0], 'FNumber' => [0, 0], 'FocalLength' => [5, 0], 'ISOSpeedRatings' => 100,
            'GPSLatitudeRef' => 'N', 'GPSLatitude' => [[48, 0], [51, 1], [0, 1]],
            'GPSLongitudeRef' => 'E', 'GPSLongitude' => [[2, 1], [21, 1], [0, 1]],
        ]));

        self::assertArrayNotHasKey('ExposureTime', $metadata->exif);
        self::assertArrayNotHasKey('FNumber', $metadata->exif);
        self::assertArrayNotHasKey('FocalLength', $metadata->exif);
        self::assertSame(100, $metadata->exif['ISOSpeedRatings']);
        self::assertNull($metadata->latitude, 'A coordinate with an impossible fraction is no coordinate.');
        self::assertArrayNotHasKey('GPSLatitude', $metadata->exif);
    }

    public function testNullIslandIsNoFix(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40, [
            'GPSLatitudeRef' => 'N', 'GPSLatitude' => [[0, 1], [0, 1], [0, 1]],
            'GPSLongitudeRef' => 'E', 'GPSLongitude' => [[0, 1], [0, 1], [0, 1]],
        ]));

        self::assertNull($metadata->latitude);
    }

    public function testStringsAreValidUtf8WithoutControlCharacters(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40, ['Make' => "Caf\xE9 Cam\0\0", 'Model' => "Mod\x01el \xC3\xA9"]));

        self::assertSame('Café Cam', $metadata->exif['Make'], 'Windows-1252 bytes are converted, NUL padding dropped.');
        self::assertSame('Model é', $metadata->exif['Model']);
        self::assertNotFalse(json_encode($metadata->exif));
    }

    public function testNoExifGivesEmptyMetadata(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40));

        self::assertNull($metadata->takenAt);
        self::assertSame([], $metadata->exif);
        self::assertSame(1, $metadata->orientation);
    }

    public function testCorruptedExifNeverThrows(): void
    {
        $jpeg = ExifJpeg::build(60, 40, ['Make' => 'Cam', 'DateTimeOriginal' => '2018:10:20 13:22:05']);
        // Scramble the TIFF header and cut the APP1 segment short.
        $broken = substr($jpeg, 0, 12).str_repeat("\xFF", 20).substr($jpeg, 40);

        $metadata = $this->read($broken);
        self::assertInstanceOf(\App\Photo\PhotoMetadata::class, $metadata);

        file_put_contents($path = $this->dir.'/garbage.jpg', "\xFF\xD8\xFF\xE1\x00\x10Exif\0\0garbage");
        self::assertSame([], (new ExifReader())->read($path)->exif);
        self::assertSame([], (new ExifReader())->read($this->dir.'/missing.jpg')->exif);
    }

    public function testImpossibleDatesAreIgnored(): void
    {
        $metadata = $this->read(ExifJpeg::build(60, 40, ['DateTimeOriginal' => '0000:00:00 00:00:00']));

        self::assertNull($metadata->takenAt);
        self::assertArrayNotHasKey('DateTimeOriginal', $metadata->exif);
    }

    public function testExposureTimeFractions(): void
    {
        self::assertSame('1/33', ExifReader::fraction(0.0303030303));
        self::assertSame('1/1000', ExifReader::fraction(0.001));
        self::assertSame('2.5', ExifReader::fraction(2.5));
        self::assertSame('30', ExifReader::fraction(30.0));
    }
}
