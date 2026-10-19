<?php

namespace App\Tests\Photos;

use App\Photo\ExifReader;
use App\Photo\PhotoPipeline;
use App\Tests\Support\ExifJpeg;
use PHPUnit\Framework\TestCase;

/** The only door into the photo library: header checks, EXIF, orientation, 10 MP cap, WebP. */
final class PhotoPipelineTest extends TestCase
{
    private string $in;
    private string $out;
    private PhotoPipeline $pipeline;

    protected function setUp(): void
    {
        $this->in = sys_get_temp_dir().'/pipeline-in-'.bin2hex(random_bytes(4));
        $this->out = sys_get_temp_dir().'/pipeline-out-'.bin2hex(random_bytes(4));
        mkdir($this->in);
        $this->pipeline = new PhotoPipeline(new ExifReader());
    }

    protected function tearDown(): void
    {
        foreach ([$this->in, $this->out] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    private function file(string $bytes, string $name = 'source'): string
    {
        file_put_contents($path = $this->in.'/'.$name, $bytes);

        return $path;
    }

    /** A PNG that is nothing but a header announcing the given size: enough for getimagesize, a bomb for any decoder. */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }

    private function animatedGif(int $width = 1, int $height = 1): string
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00".pack('vv', $width, $height)."\x00\x02\x02D\x01\x00";

        return "GIF89a".pack('vv', $width, $height)."\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF".$frame.$frame.';';
    }

    public function testBigPortraitPhotoIsRotatedUprightDownscaledAndStoredAsWebp(): void
    {
        // 4200 × 3000 = 12.6 MP, EXIF "rotate 90° clockwise" (what a phone held upright writes).
        $path = $this->file(ExifJpeg::build(4200, 3000, ['Orientation' => 6, 'Make' => 'Cam', 'DateTimeOriginal' => '2020-05-17 10:00:00'], 70), 'big.jpg');

        $stored = $this->pipeline->ingest($path, $this->out);

        self::assertSame('webp', $stored->extension());
        self::assertSame('image/webp', $stored->mimeType);
        self::assertLessThanOrEqual(PhotoPipeline::TARGET_PIXELS, $stored->width * $stored->height);
        self::assertGreaterThan(9_000_000, $stored->width * $stored->height, 'Downscaled to the cap, not further.');
        self::assertGreaterThan($stored->width, $stored->height, 'Rotated: the photo is now taller than wide.');
        self::assertEqualsWithDelta(3000 / 4200, $stored->height > 0 ? $stored->width / $stored->height : 0, 0.01, 'Aspect ratio kept.');
        self::assertSame([$stored->width, $stored->height], \array_slice(getimagesize($this->out.'/'.$stored->filename), 0, 2));
        self::assertSame('image/webp', getimagesize($this->out.'/'.$stored->filename)['mime']);
        self::assertSame($stored->fileSize, filesize($this->out.'/'.$stored->filename));

        // The red block was top-left; turned 90° clockwise it is top-right.
        $image = imagecreatefromwebp($this->out.'/'.$stored->filename);
        $corner = imagecolorsforindex($image, imagecolorat($image, $stored->width - 5, 5));
        self::assertGreaterThan(150, $corner['red']);
        self::assertLessThan(100, $corner['blue']);
        $opposite = imagecolorsforindex($image, imagecolorat($image, 5, 5));
        self::assertLessThan(100, $opposite['red'], 'The top-left corner is now the background.');

        self::assertSame('Cam', $stored->metadata->exif['Make']);
        self::assertSame(4200, $stored->metadata->exif['OriginalWidth']);
        self::assertSame(3000, $stored->metadata->exif['OriginalHeight']);
        self::assertSame('image/jpeg', $stored->metadata->exif['OriginalMimeType']);
        self::assertSame(filesize($path), $stored->metadata->exif['OriginalFileSize']);
        self::assertSame('2020-05-17 10:00:00', $stored->metadata->takenAt->format('Y-m-d H:i:s'));
    }

    public function testEveryOrientationEndsUprightWithTheRightShape(): void
    {
        foreach ([1 => false, 2 => false, 3 => false, 4 => false, 5 => true, 6 => true, 7 => true, 8 => true] as $orientation => $swaps) {
            $path = $this->file(ExifJpeg::build(300, 200, ['Orientation' => $orientation]), "o$orientation.jpg");
            $stored = $this->pipeline->ingest($path, $this->out);

            self::assertSame($swaps ? [200, 300] : [300, 200], [$stored->width, $stored->height], "orientation $orientation");
        }
    }

    public function testAQuarterTurnSwapsTheShapeAndGoesTheRequestedWay(): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, 59, 39, imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagepng($image);
        $source = $this->file((string) ob_get_clean(), 'wide.png');

        $right = $this->pipeline->rotate($source, $this->out, true);
        $left = $this->pipeline->rotate($source, $this->out, false);

        foreach ([$right, $left] as $turned) {
            self::assertSame('image/webp', $turned->mimeType);
            self::assertSame([200, 300], [$turned->width, $turned->height]);
            self::assertSame([200, 300], \array_slice(getimagesize($this->out.'/'.$turned->filename), 0, 2));
            self::assertSame($turned->fileSize, filesize($this->out.'/'.$turned->filename));
        }
        self::assertNotSame($right->filename, $left->filename);
        $isRed = static function (string $file, int $x, int $y): bool {
            $rgb = imagecolorat(imagecreatefromwebp($file), $x, $y);

            return (($rgb >> 16) & 0xFF) > 150 && ($rgb & 0xFF) < 100;
        };
        self::assertTrue($isRed($this->out.'/'.$right->filename, 180, 20), 'Clockwise: top-left goes to the top-right.');
        self::assertTrue($isRed($this->out.'/'.$left->filename, 20, 280), 'Counter-clockwise: top-left goes to the bottom-left.');
        self::assertFileExists($source, 'The source is left untouched.');
    }

    public function testATurnAlsoAppliesTheExifOrientationOfAFileThatNeverWentThroughTheLibrary(): void
    {
        // EXIF 6 = "rotate 90° clockwise to read": 300 × 200 on disk is a 200 × 300 portrait; one more clockwise turn makes it 300 × 200.
        $source = $this->file(ExifJpeg::build(300, 200, ['Orientation' => 6]), 'legacy.jpg');

        $turned = $this->pipeline->rotate($source, $this->out, true);

        self::assertSame([300, 200], [$turned->width, $turned->height]);
    }

    public function testAnAnimatedGifCannotBeTurned(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GIF animé');

        try {
            $this->pipeline->rotate($this->file($this->animatedGif(), 'anim.gif'), $this->out, true);
        } finally {
            self::assertSame([], glob($this->out.'/*') ?: [], 'Nothing is written.');
        }
    }

    public function testATurnRefusesWhatIsNotAnImage(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pipeline->rotate($this->file('<?php echo 1;', 'evil.jpg'), $this->out, true);
    }

    public function testSmallPicturesAreNeverUpscaled(): void
    {
        $stored = $this->pipeline->ingest($this->file(ExifJpeg::build(300, 200)), $this->out);

        self::assertSame([300, 200], [$stored->width, $stored->height]);
    }

    public function testTransparentPngKeepsItsTransparency(): void
    {
        $image = imagecreatetruecolor(40, 40);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 20, 20, 39, 39, imagecolorallocatealpha($image, 200, 0, 0, 0));
        ob_start();
        imagepng($image);

        $stored = $this->pipeline->ingest($this->file((string) ob_get_clean()), $this->out);

        self::assertSame('image/webp', $stored->mimeType);
        $webp = imagecreatefromwebp($this->out.'/'.$stored->filename);
        self::assertGreaterThan(100, (imagecolorat($webp, 5, 5) >> 24) & 0x7F, 'Transparent corner stays transparent (GD alpha 0 = opaque, 127 = clear).');
        self::assertLessThan(10, (imagecolorat($webp, 30, 30) >> 24) & 0x7F, 'The opaque square stays opaque.');
    }

    public function testStillGifBecomesWebpButAnimatedGifIsKeptAsGif(): void
    {
        $still = imagecreatetruecolor(20, 20);
        ob_start();
        imagegif($still);
        self::assertSame('webp', $this->pipeline->ingest($this->file((string) ob_get_clean(), 'still.gif'), $this->out)->extension());

        $animated = $this->file($this->animatedGif(), 'anim.gif');
        self::assertTrue(PhotoPipeline::isAnimatedGif($animated));
        $stored = $this->pipeline->ingest($animated, $this->out);
        self::assertSame('gif', $stored->extension());
        self::assertSame('image/gif', $stored->mimeType);
        self::assertSame(file_get_contents($animated), file_get_contents($this->out.'/'.$stored->filename), 'Byte for byte: the animation survives.');
        self::assertTrue(PhotoPipeline::isAnimatedGif($this->out.'/'.$stored->filename));
        self::assertTrue($this->pipeline->isUpToDate($this->out.'/'.$stored->filename));
    }

    public function testAnimatedGifLargerThanTheTargetIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('GIF animé');

        $this->pipeline->ingest($this->file($this->animatedGif(4000, 4000), 'huge.gif'), $this->out);
    }

    public function testPixelBombIsRefusedFromTheHeaderWithoutDecoding(): void
    {
        $path = $this->file($this->pngHeader(20000, 20000), 'bomb.png'); // 400 megapixels in 33 bytes
        $before = memory_get_peak_usage(true);

        try {
            $this->pipeline->ingest($path, $this->out);
            self::fail('A 400 MP image must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('trop grande', $e->getMessage());
            self::assertStringContainsString('100 mégapixels', $e->getMessage());
        }

        self::assertLessThan(30 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'Nothing was decoded.');
        self::assertSame([], glob($this->out.'/*') ?: []);
    }

    public function testAnImageThatCannotFitInMemoryIsRefusedInsteadOfKillingTheRequest(): void
    {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '256M');
        try {
            // 81 MP: below the pixel cap, but 4 bytes per pixel decoded would not fit in 256 MB.
            $this->pipeline->ingest($this->file($this->pngHeader(9000, 9000), 'wide.png'), $this->out);
            self::fail('Must be refused.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('trop grande pour être traitée', $e->getMessage());
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    public function testAbsurdSidesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pipeline->inspect($this->file($this->pngHeader(40000, 10), 'strip.png'));
    }

    public function testNonImagesSvgAndEmptyFilesAreRefused(): void
    {
        foreach (['<?php echo "x";', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', ''] as $i => $bytes) {
            try {
                $this->pipeline->ingest($this->file($bytes, "bad$i.jpg"), $this->out);
                self::fail('Not an image: '.$bytes);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('JPEG, PNG, GIF et WebP', $e->getMessage());
            }
        }
        self::assertSame([], glob($this->out.'/*') ?: []);
    }

    public function testPolyglotPayloadDoesNotSurviveTheReencoding(): void
    {
        $polyglot = ExifJpeg::build(40, 40).'<?php system($_GET["c"]); ?>';

        $stored = $this->pipeline->ingest($this->file($polyglot, 'poly.jpg'), $this->out);

        self::assertStringNotContainsString('<?php', (string) file_get_contents($this->out.'/'.$stored->filename));
    }

    public function testIsUpToDate(): void
    {
        $webp = $this->pipeline->ingest($this->file(ExifJpeg::build(300, 200)), $this->out);

        self::assertTrue($this->pipeline->isUpToDate($this->out.'/'.$webp->filename), 'Already WebP within the cap.');
        self::assertFalse($this->pipeline->isUpToDate($this->file(ExifJpeg::build(300, 200), 'old.jpg')), 'A JPEG still has to be converted.');
        self::assertTrue($this->pipeline->isUpToDate($this->file('not an image', 'junk')), 'Nothing the pipeline could do.');
    }
}
