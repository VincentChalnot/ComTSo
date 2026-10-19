<?php

namespace App\Tests\Admin;

use App\Entity\Photo;
use App\Entity\User;
use App\Photo\PhotoRemover;
use App\Service\ImageProcessingService;
use App\Service\PhotoUploader;
use App\Tests\Support\PhotoFilesTrait;
use App\Tests\Support\PhotoRowsTrait;
use Symfony\Component\DomCrawler\Crawler;

class PhotoAdminTest extends AdminWebTestCase
{
    use PhotoFilesTrait;
    use PhotoRowsTrait;

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

    private function storedPhoto(User $author, string $name = 'beach.jpg'): Photo
    {
        $photo = static::getContainer()->get(PhotoUploader::class)->store($this->jpegUpload($name), $this->em()->getReference(User::class, $author->getId()));
        $this->em()->persist($photo);
        $this->em()->flush();
        static::getContainer()->get(ImageProcessingService::class)->thumbnailPath($photo->getFilename(), 'thumb');

        return $photo;
    }

    public function testTheListShowsSizeDimensionsAndUsage(): void
    {
        $admin = $this->loginAdmin();
        $used = $this->photoRow('Utilisée');
        $this->photoRow('Libre');
        $topic = $this->createTopic($this->createForum(), $admin);
        $this->attachToTopic($topic, $used);
        $this->attachToComment($this->createComment($topic, $admin), $used);
        $used->setWidth(800)->setHeight(600)->setAuthor($admin);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/photos/');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.table tbody tr')->each(fn (Crawler $tr) => preg_replace('/\s+/', ' ', trim($tr->text())));
        self::assertCount(2, $rows);
        $usedRow = current(array_filter($rows, fn (string $row) => str_contains($row, 'Utilisée')));
        self::assertStringContainsString('800 × 600', $usedRow);
        self::assertStringContainsString('1 sujet', $usedRow);
        self::assertStringContainsString('1 message', $usedRow);
        self::assertStringContainsString('root', $usedRow);
        $freeRow = current(array_filter($rows, fn (string $row) => str_contains($row, 'Libre')));
        self::assertStringContainsString('Inutilisée', $freeRow);
        $this->assertNoEmailAddress();
    }

    public function testFiltersOnNameAndAuthor(): void
    {
        $admin = $this->loginAdmin();
        $mine = $this->photoRow('Plage');
        $mine->setAuthor($admin);
        $this->photoRow('Montagne');
        $this->em()->flush();

        $names = fn (Crawler $crawler) => $crawler->filter('table.table tbody tr td:nth-child(2) a')->each(fn (Crawler $a) => trim($a->text()));
        self::assertSame(['Plage'], $names($this->client->request('GET', '/admin/photos/', ['form' => ['filters' => ['name' => 'plag']]])));
        self::assertSame(['Plage'], $names($this->client->request('GET', '/admin/photos/', ['form' => ['filters' => ['author' => 'roo']]])));
    }

    public function testAdminDeletionRemovesTheRowTheFilesAndTheAvatarLinkOfAUsedPhoto(): void
    {
        $this->loginAdmin();
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $id = $photo->getId();
        $filename = $photo->getFilename();
        $this->em()->find(User::class, $alice->getId())->setAvatar($photo);
        $this->em()->flush();
        self::assertCount(1, glob(static::getContainer()->getParameter('photos_cache_directory').'/thumb/*'));

        $crawler = $this->client->request('GET', '/admin/photos/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('utilisée', $crawler->filter('.alert-body')->text());
        self::assertFileExists($this->photoDir.'/'.$filename);

        $this->client->request('POST', '/admin/photos/'.$id.'/delete');
        self::assertFileExists($this->photoDir.'/'.$filename, 'no token, no removal');

        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());

        self::assertResponseRedirects('/admin/photos/');
        $this->em()->clear();
        self::assertNull($this->em()->find(Photo::class, $id));
        self::assertNull($this->em()->find(User::class, $alice->getId())->getAvatar(), 'the avatar link went with it');
        self::assertFileDoesNotExist($this->photoDir.'/'.$filename);
        self::assertSame([], glob(static::getContainer()->getParameter('photos_cache_directory').'/thumb/*') ?: []);
    }

    public function testThePhotoPageDeletionUsesTheSameRemover(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $filename = $photo->getFilename();

        static::getContainer()->get(PhotoRemover::class)->remove($photo);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_photo'));
        self::assertFileDoesNotExist($this->photoDir.'/'.$filename);
    }
}
