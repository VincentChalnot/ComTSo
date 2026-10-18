<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

class HeroVariantTest extends AppWebTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $root = static::getContainer()->getParameter('kernel.project_dir').'/var/test-photos';
        (new Filesystem())->remove($root);
        $this->dir = $root.'/originals';
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/test-photos');
        parent::tearDown();
    }

    private function storedPhoto(string $filename, int $width, int $height): Photo
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
        imagewebp($image, $this->dir.'/'.$filename);
        $photo = (new Photo())->setFilename($filename)->setOriginalFilename($filename)->setFileSize(filesize($this->dir.'/'.$filename))->setFileType('image/webp')->setWidth($width)->setHeight($height);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    public function testHeroVariantRequiresALogin(): void
    {
        $photo = $this->storedPhoto('wide.webp', 800, 600);

        $this->client->request('GET', "/photos/{$photo->getId()}/hero");

        self::assertResponseRedirects('/login');
    }

    public function testHeroVariantIsScaledDownToAtMost1600PixelsWide(): void
    {
        $photo = $this->storedPhoto('wide.webp', 3200, 1800);
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', "/photos/{$photo->getId()}/hero");

        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('private', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([1600, 900], \array_slice(getimagesize(static::getContainer()->getParameter('photos_cache_directory').'/hero/wide.webp'), 0, 2));
    }

    public function testHeroVariantNeverUpscalesASmallPhoto(): void
    {
        $photo = $this->storedPhoto('small.webp', 640, 480);
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', "/photos/{$photo->getId()}/hero");

        self::assertResponseIsSuccessful();
        self::assertSame([640, 480], \array_slice(getimagesize(static::getContainer()->getParameter('photos_cache_directory').'/hero/small.webp'), 0, 2));
    }
}
