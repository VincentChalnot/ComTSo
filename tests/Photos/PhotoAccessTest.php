<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Service\ImageProcessingService;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PhotoAccessTest extends AppWebTestCase
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

    private function jpeg(string $path, int $width = 600, int $height = 400): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));
        imagejpeg($image, $path);
    }

    /** CSRF token of the photo manager page (the Vue widget sends it with every upload). */
    private function managerToken(int $topicId): string
    {
        $crawler = $this->client->request('GET', "/forum/general/$topicId/photos");

        return json_decode($crawler->filter('[data-vue-component=PhotoManager]')->attr('data-props'), true)['token'];
    }

    private function storedPhoto(string $filename = 'abc123.jpeg'): Photo
    {
        $this->jpeg($this->dir.'/'.$filename);
        $photo = (new Photo())->setFilename($filename)->setOriginalFilename('holiday.jpg')->setFileSize(filesize($this->dir.'/'.$filename))->setFileType('image/jpeg')->setTitle('Holiday');
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    public function testPhotosAreNotPubliclyServed(): void
    {
        $photo = $this->storedPhoto();

        foreach (["/photos/source/{$photo->getId()}", "/photos/{$photo->getId()}/thumb", "/photos/{$photo->getId()}/medium", "/photos/{$photo->getId()}"] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/login', null, "Anonymous access to $url");
        }
    }

    public function testMemberGetsTheOriginalAndGeneratedThumbnails(): void
    {
        $photo = $this->storedPhoto();
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', "/photos/source/{$photo->getId()}");
        self::assertResponseIsSuccessful();
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('private', $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->request('GET', "/photos/{$photo->getId()}/thumb");
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        [$width, $height] = getimagesize(static::getContainer()->getParameter('photos_cache_directory').'/thumb/abc123.webp');
        self::assertSame([320, 320], [$width, $height], 'Thumbnails are cropped to a square.');

        $this->client->request('GET', "/photos/{$photo->getId()}/medium");
        [$width] = getimagesize(static::getContainer()->getParameter('photos_cache_directory').'/medium/abc123.webp');
        self::assertSame(600, $width, 'Medium never upscales a small photo.');
    }

    public function testDownloadForcesAnAttachment(): void
    {
        $photo = $this->storedPhoto();
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', "/photos/source/{$photo->getId()}?download=1");

        self::assertStringContainsString('attachment', $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testUnknownVariantAndMissingFilesAreNotFound(): void
    {
        $photo = $this->storedPhoto();
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', "/photos/{$photo->getId()}/huge");
        self::assertResponseStatusCodeSame(404);

        unlink($this->dir.'/abc123.jpeg');
        $this->client->request('GET', "/photos/{$photo->getId()}/thumb");
        self::assertResponseStatusCodeSame(404);
    }

    public function testThumbnailGeneratorRefusesPathTraversal(): void
    {
        $images = static::getContainer()->get(ImageProcessingService::class);

        self::assertNull($images->thumbnailPath('../originals/abc123.jpeg', 'thumb'));
        self::assertNull($images->thumbnailPath('/etc/passwd', 'thumb'));
    }

    public function testAuthorCanAttachAPhotoToTheirTopic(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Album', new \DateTime('2020-01-01'));
        $this->loginAs($alice);
        $upload = sys_get_temp_dir().'/upload-test.jpg';
        $this->jpeg($upload, 800, 600);

        $this->client->request('POST', "/forum/general/{$topic->getId()}/photos/upload", ['_token' => $this->managerToken($topic->getId())], ['file' => new UploadedFile($upload, 'beach.jpg', 'image/jpeg', null, true)]);

        self::assertResponseStatusCodeSame(201);
        $this->em()->clear();
        $links = $this->em()->getRepository(PhotoTopic::class)->findAll();
        self::assertCount(1, $links);
        $photo = $links[0]->getPhoto();
        self::assertSame('beach.jpg', $photo->getOriginalFilename());
        self::assertSame([800, 600], [$photo->getWidth(), $photo->getHeight()]);
        self::assertSame('image/webp', $photo->getFileType(), 'Stored through the pipeline.');
        self::assertFileExists($this->dir.'/'.$photo->getFilename());
        self::assertGreaterThan(new \DateTime('-1 minute'), $this->em()->find($topic::class, $topic->getId())->getLastActivityAt(), 'New photos move the topic up in the feeds.');
    }

    public function testNonImagesAndStrangersAreRejected(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $text = sys_get_temp_dir().'/not-an-image.jpg';
        file_put_contents($text, '<?php echo "pwned";');

        $this->loginAs($alice);
        $token = $this->managerToken($topic->getId());
        $this->client->request('POST', "/forum/general/{$topic->getId()}/photos/upload", ['_token' => $token], ['file' => new UploadedFile($text, 'evil.jpg', 'image/jpeg', null, true)]);
        self::assertResponseStatusCodeSame(422);

        $this->loginAs($this->createUser('mallory'));
        $this->jpeg($ok = sys_get_temp_dir().'/ok.jpg');
        $this->client->request('POST', "/forum/general/{$topic->getId()}/photos/upload", ['_token' => $token], ['file' => new UploadedFile($ok, 'ok.jpg', 'image/jpeg', null, true)]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame([], glob($this->dir.'/*') ?: []);
    }
}
