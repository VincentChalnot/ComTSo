<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Photo\PhotoReprocessor;
use App\Service\ImageProcessingService;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\ExifJpeg;
use App\Tests\Support\PhotoFilesTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/** A restored legacy library (JPEGs, raw sizes, thin EXIF) is brought up to the pipeline in place, safely and repeatedly. */
final class PhotosReprocessCommandTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    /** A photo as the old forum left it: a JPEG with its orientation in EXIF, a thin EXIF map with the raw ExifTool dump. */
    private function legacyPhoto(string $name, int $width = 400, int $height = 300): Photo
    {
        file_put_contents($this->photoDir.'/'.$name, ExifJpeg::build($width, $height, ['Orientation' => 6, 'Make' => 'ZTE', 'Model' => 'Blade A54', 'DateTimeOriginal' => '2024:09:07 11:08:48', 'GPSLatitudeRef' => 'N', 'GPSLatitude' => [[48, 1], [51, 1], [0, 1]], 'GPSLongitudeRef' => 'E', 'GPSLongitude' => [[2, 1], [21, 1], [0, 1]]]));
        $photo = (new Photo())->setFilename($name)->setOriginalFilename('1.jpg')->setFileSize(filesize($this->photoDir.'/'.$name))->setFileType('image/jpeg')
            ->setWidth($width)->setHeight($height)->setTakenAt(new \DateTime('2024-09-18 14:26:59'))
            ->setExif(['Make' => 'ZTE', 'ISOSpeedRatings' => '88', 'Nickname' => 'kept', 'ExifTool' => ['System:Directory' => '/var/www/html/app/data/uploads']]);
        $this->em()->persist($photo);
        $this->em()->flush();
        static::getContainer()->get(ImageProcessingService::class)->thumbnailPath($name, 'thumb');

        return $photo;
    }

    private function reprocess(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:photos:reprocess'));
        $tester->execute($input);

        return $tester;
    }

    private function reload(Photo $photo): Photo
    {
        $this->em()->clear();

        return $this->em()->find(Photo::class, $photo->getId());
    }

    public function testLegacyJpegIsConvertedInPlaceWithItsExifPersisted(): void
    {
        $photo = $this->legacyPhoto('legacy.jpeg');

        $this->reprocess();

        $photo = $this->reload($photo);
        self::assertStringEndsWith('.webp', $photo->getFilename());
        self::assertSame('image/webp', $photo->getFileType());
        self::assertSame([300, 400], [$photo->getWidth(), $photo->getHeight()], 'Orientation applied.');
        self::assertSame(filesize($this->photoDir.'/'.$photo->getFilename()), $photo->getFileSize());
        self::assertSame('2024-09-07 11:08:48', $photo->getTakenAt()->format('Y-m-d H:i:s'), 'The shooting date comes from EXIF, not from the file date.');
        self::assertEqualsWithDelta(48.85, $photo->getGpsLatitude(), 0.001);
        $exif = $photo->getExif();
        self::assertSame('Blade A54', $exif['Model']);
        self::assertSame('88', $exif['ISOSpeedRatings'], 'What only the old map knew is kept.');
        self::assertSame('kept', $exif['Nickname']);
        self::assertArrayNotHasKey('ExifTool', $exif, 'The raw dump with server paths is dropped.');
        self::assertFileDoesNotExist($this->photoDir.'/legacy.jpeg');
        self::assertSame([$photo->getFilename()], $this->storedFiles());
        self::assertSame([], glob(static::getContainer()->getParameter('photos_cache_directory').'/thumb/*') ?: [], 'Stale thumbnails go away.');
        $this->loginAs($this->createUser('alice'));
        $this->client->request('GET', "/photos/{$photo->getId()}/thumb");
        self::assertResponseIsSuccessful();
    }

    public function testRunningItAgainChangesNothing(): void
    {
        $photo = $this->legacyPhoto('legacy.jpeg');
        $this->reprocess();
        $first = $this->reload($photo);
        $files = $this->storedFiles();
        $mtime = filemtime($this->photoDir.'/'.$first->getFilename());

        $second = $this->reprocess();

        $again = $this->reload($photo);
        self::assertSame($first->getFilename(), $again->getFilename());
        self::assertSame($files, $this->storedFiles());
        self::assertSame($mtime, filemtime($this->photoDir.'/'.$again->getFilename()));
        self::assertMatchesRegularExpression('~Processed\s+0~', $second->getDisplay());
        self::assertMatchesRegularExpression('~Already up to date\s+1~', $second->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $photo = $this->legacyPhoto('legacy.jpeg');

        $tester = $this->reprocess(['--dry-run' => true]);

        self::assertMatchesRegularExpression('~Would be processed\s+1~', $tester->getDisplay());
        self::assertSame('legacy.jpeg', $this->reload($photo)->getFilename());
        self::assertSame(['legacy.jpeg'], $this->storedFiles());
    }

    public function testLimitStopsAfterTheGivenNumberOfProcessedPhotos(): void
    {
        $a = $this->legacyPhoto('a.jpeg');
        $b = $this->legacyPhoto('b.jpeg');

        $this->reprocess(['--limit' => 1]);

        $this->em()->clear();
        $names = array_map(fn (int $id) => $this->em()->find(Photo::class, $id)->getFilename(), [$a->getId(), $b->getId()]);
        self::assertStringEndsWith('.webp', $names[0]);
        self::assertSame('b.jpeg', $names[1]);
    }

    public function testUnreadableOrMissingFilesAreReportedNotFatal(): void
    {
        $missing = (new Photo())->setFilename('gone.jpeg')->setOriginalFilename('2.jpg')->setFileSize(1)->setFileType('image/jpeg');
        $this->em()->persist($missing);
        $this->em()->flush();
        file_put_contents($this->photoDir.'/junk.jpeg', 'not an image');
        $junk = (new Photo())->setFilename('junk.jpeg')->setOriginalFilename('3.jpg')->setFileSize(12)->setFileType('image/jpeg');
        $this->em()->persist($junk);
        $this->em()->flush();

        $tester = $this->reprocess();

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('~File missing\s+1~', $tester->getDisplay());
        self::assertSame('junk.jpeg', $this->reload($junk)->getFilename());
    }

    public function testItCopiesFromAnotherDirectoryThroughTheSamePipeline(): void
    {
        // Reprocessing from another directory: the old file stays where it is (or is removed, per option), the stored copy is a processed WebP.
        $legacyDir = sys_get_temp_dir().'/legacy-'.bin2hex(random_bytes(4));
        mkdir($legacyDir);
        file_put_contents($legacyDir.'/61a.jpeg', ExifJpeg::build(400, 300, ['Orientation' => 6, 'Model' => 'Old phone', 'DateTimeOriginal' => '2012:05:01 09:00:00']));
        $photo = (new Photo())->setFilename('61a.jpeg')->setOriginalFilename('61.jpg')->setFileSize(1)->setFileType('image/jpeg')->setTakenAt(new \DateTime('2020-01-01'));
        $this->em()->persist($photo);
        $this->em()->flush();
        $reprocessor = static::getContainer()->get(PhotoReprocessor::class);

        $status = $reprocessor->reprocess(['id' => $photo->getId(), 'filename' => '61a.jpeg', 'exif' => null], $legacyDir, deleteSource: false);

        self::assertSame(PhotoReprocessor::UPDATED, $status);
        $photo = $this->reload($photo);
        self::assertFileExists($legacyDir.'/61a.jpeg', 'The legacy tree is left alone.');
        self::assertFileExists($this->photoDir.'/'.$photo->getFilename());
        self::assertSame('image/webp', $photo->getFileType());
        self::assertSame([300, 400], [$photo->getWidth(), $photo->getHeight()]);
        self::assertSame('Old phone', $photo->getExif()['Model']);
        self::assertSame('2012-05-01 09:00:00', $photo->getTakenAt()->format('Y-m-d H:i:s'));
        self::assertSame(PhotoReprocessor::MISSING, $reprocessor->reprocess(['id' => $photo->getId(), 'filename' => 'nope.jpeg'], $legacyDir));
        (new Filesystem())->remove($legacyDir);
    }
}
