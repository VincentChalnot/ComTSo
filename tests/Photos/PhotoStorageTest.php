<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Topic;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\ExifJpeg;
use App\Tests\Support\PhotoFilesTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** What an upload becomes: limits, the pipeline's output, what is persisted and how it is served. */
final class PhotoStorageTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
        $this->createForum();
        $this->loginAs($this->createUser('alice'));
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    /** Creates a topic "Album" with the files; returns the page that answered. */
    private function newTopic(array $files): string
    {
        $crawler = $this->client->request('GET', '/forums/general/nouveau');
        $form = $crawler->selectButton('Créer le sujet')->form();
        $values = $form->getPhpValues();
        $values['topic']['title'] = 'Album';
        $this->client->request('POST', $form->getUri(), $values, ['topic' => ['photos' => $files]]);
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }

        return $this->client->getResponse()->getContent();
    }

    private function upload(string $bytes, string $name, string $type = 'image/jpeg'): UploadedFile
    {
        file_put_contents($path = sys_get_temp_dir().'/'.uniqid('up-', true), $bytes);

        return new UploadedFile($path, $name, $type, null, true);
    }

    private function onlyPhoto(): Photo
    {
        $this->em()->clear();
        $photos = $this->em()->getRepository(Photo::class)->findAll();
        self::assertCount(1, $photos);

        return $photos[0];
    }

    private function assertNothingStored(): void
    {
        self::assertNull($this->em()->getRepository(Topic::class)->findOneBy(['title' => 'Album']), 'The topic is not created.');
        self::assertSame([], $this->storedFiles());
        self::assertSame(0, $this->em()->getRepository(Photo::class)->count([]));
    }

    public function testBigUprightPhoneJpegIsStoredAsAnUprightWebpWithItsExifInTheDatabase(): void
    {
        $bytes = ExifJpeg::build(4200, 3000, [
            'Orientation' => 6, 'Make' => 'samsung', 'Model' => 'SM-A310F', 'DateTimeOriginal' => '2018:10:20 13:22:05',
            'ExposureTime' => [1, 33], 'FNumber' => [19, 10], 'ISOSpeedRatings' => 160, 'FocalLength' => [37, 10],
            'GPSLatitudeRef' => 'S', 'GPSLatitude' => [[33, 1], [52, 1], [0, 1]], 'GPSLongitudeRef' => 'W', 'GPSLongitude' => [[70, 1], [39, 1], [0, 1]],
        ], 70);

        $this->newTopic([$this->upload($bytes, 'IMG_0001.jpg')]);

        $photo = $this->onlyPhoto();
        self::assertSame('IMG_0001.jpg', $photo->getOriginalFilename(), 'The name the member knows is kept for display.');
        self::assertStringEndsWith('.webp', $photo->getFilename());
        self::assertSame('image/webp', $photo->getFileType());
        self::assertLessThanOrEqual(10_000_000, $photo->getWidth() * $photo->getHeight());
        self::assertGreaterThan($photo->getWidth(), $photo->getHeight(), 'Rotated upright.');
        $stored = $this->photoDir.'/'.$photo->getFilename();
        self::assertSame([$photo->getWidth(), $photo->getHeight()], \array_slice(getimagesize($stored), 0, 2));
        self::assertSame(filesize($stored), $photo->getFileSize());
        self::assertSame('2018-10-20 13:22:05', $photo->getTakenAt()->format('Y-m-d H:i:s'));
        self::assertEqualsWithDelta(-33.866667, $photo->getGpsLatitude(), 0.000001);
        self::assertEqualsWithDelta(-70.65, $photo->getGpsLongitude(), 0.000001);
        $exif = $photo->getExif();
        self::assertSame('SM-A310F', $exif['Model']);
        self::assertSame('1/33', $exif['ExposureTimeFraction']);
        self::assertSame(160, $exif['ISOSpeedRatings']);
        self::assertSame(4200, $exif['OriginalWidth']);
        self::assertSame('image/jpeg', $exif['OriginalMimeType']);
        self::assertCount(1, $this->em()->getRepository(PhotoTopic::class)->findAll());
    }

    public function testStoredPhotoAndItsThumbnailsAreServedAsWebpWithNosniff(): void
    {
        $this->newTopic([$this->upload(ExifJpeg::build(1800, 1200), 'wide.jpg')]);
        $photo = $this->onlyPhoto();

        foreach (['source/'.$photo->getId() => 'image/webp', $photo->getId().'/thumb' => 'image/webp', $photo->getId().'/medium' => 'image/webp', $photo->getId().'/hero' => 'image/webp', $photo->getId().'/avatar' => 'image/webp'] as $path => $type) {
            $this->client->request('GET', '/photos/'.$path);
            self::assertResponseIsSuccessful($path);
            self::assertSame($type, $this->client->getResponse()->headers->get('Content-Type'), $path);
            self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'), $path);
        }
        foreach (['thumb', 'medium', 'hero', 'avatar'] as $variant) {
            self::assertFileExists(static::getContainer()->getParameter('photos_cache_directory').'/'.$variant.'/'.pathinfo($photo->getFilename(), PATHINFO_FILENAME).'.webp');
        }
    }

    public function testDownloadKeepsTheMembersFileNameWithTheRightExtension(): void
    {
        $this->newTopic([$this->upload(ExifJpeg::build(300, 200), 'Holiday 2020.JPG')]);
        $photo = $this->onlyPhoto();

        $this->client->request('GET', "/photos/source/{$photo->getId()}?download=1");

        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('Holiday%202020.webp', $disposition);
    }

    public function testFilesOverTenMegabytesAreRefused(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('big-', true);
        $handle = fopen($path, 'w');
        fwrite($handle, "\xFF\xD8\xFF\xE0");
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);

        $page = $this->newTopic([new UploadedFile($path, 'huge.jpg', 'image/jpeg', null, true)]);

        self::assertStringContainsString('10 Mo au maximum', $page);
        $this->assertNothingStored();
    }

    public function testExactlyTenMegabytesPassTheSizeCheck(): void
    {
        // A valid JPEG padded with a comment segment up to the limit: the size check must not be off by one.
        $jpeg = ExifJpeg::build(200, 100);
        $pad = 10 * 1024 * 1024 - \strlen($jpeg) - 4;
        $segments = '';
        while ($pad > 65_000) {
            $segments .= "\xFF\xFE\xFF\xFF".str_repeat('x', 65_533);
            $pad -= 65_535;
        }
        $bytes = substr($jpeg, 0, 2).$segments.substr($jpeg, 2);
        $bytes .= str_repeat("\0", 10 * 1024 * 1024 - \strlen($bytes));

        $this->newTopic([$this->upload($bytes, 'limit.jpg')]);

        self::assertSame(1, $this->em()->getRepository(Photo::class)->count([]));
    }

    public function testPixelBombIsRefusedBeforeAnythingDecodesIt(): void
    {
        $ihdr = pack('NN', 20000, 20000)."\x08\x02\x00\x00\x00";
        $bomb = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $before = memory_get_peak_usage(true);

        $page = $this->newTopic([$this->upload($bomb, 'bomb.png', 'image/png')]);

        self::assertStringContainsString('mégapixels', $page);
        self::assertLessThan(100 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'No 1.6 GB bitmap was allocated.');
        $this->assertNothingStored();
    }

    public function testScriptsSvgAndFakeImagesAreRefused(): void
    {
        foreach ([['<?php echo 1;', 'evil.jpg', 'image/jpeg'], ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'x.svg', 'image/svg+xml'], ['GIF89a<script>alert(1)</script>', 'x.gif', 'image/gif']] as [$bytes, $name, $type]) {
            $page = $this->newTopic([$this->upload($bytes, $name, $type)]);
            self::assertStringContainsString('image', $page, $name);
        }
        $this->assertNothingStored();
    }

    public function testTransparentPngIsAccepted(): void
    {
        $image = imagecreatetruecolor(50, 50);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);

        $this->newTopic([$this->upload((string) ob_get_clean(), 'logo.png', 'image/png')]);

        $photo = $this->onlyPhoto();
        self::assertSame('image/webp', $photo->getFileType());
        self::assertGreaterThan(100, (imagecolorat(imagecreatefromwebp($this->photoDir.'/'.$photo->getFilename()), 3, 3) >> 24) & 0x7F, 'Still transparent.');
    }

    public function testAnimatedGifKeepsItsAnimation(): void
    {
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00";
        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF".$frame.$frame.';';

        $this->newTopic([$this->upload($gif, 'spin.gif', 'image/gif')]);

        $photo = $this->onlyPhoto();
        self::assertSame('image/gif', $photo->getFileType());
        self::assertStringEndsWith('.gif', $photo->getFilename());
        self::assertSame($gif, file_get_contents($this->photoDir.'/'.$photo->getFilename()));
        $this->client->request('GET', "/photos/{$photo->getId()}/thumb");
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'), 'Thumbnails are WebP even for a GIF.');
    }
}
