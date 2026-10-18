<?php

namespace App\Tests\Photos;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoLike;
use App\Entity\PhotoTopic;
use App\Entity\User;
use App\Service\PhotoUploader;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;

final class PhotoDeleteTest extends AppWebTestCase
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

    private function storedPhoto(User $author): Photo
    {
        $photo = static::getContainer()->get(PhotoUploader::class)->store($this->jpegUpload('mine.jpg'), $this->em()->getReference(User::class, $author->getId()));
        $this->em()->persist($photo);
        $this->em()->flush();
        static::getContainer()->get(\App\Service\ImageProcessingService::class)->thumbnailPath($photo->getFilename(), 'thumb');

        return $photo;
    }

    private function delete(Photo $photo, ?string $token = null): void
    {
        $token ??= $this->client->getCrawler()->filter('form[action$="/delete"] input[name=_token]')->attr('value');
        $this->client->request('POST', "/photos/{$photo->getId()}/delete", ['_token' => $token]);
    }

    private function open(Photo $photo): void
    {
        $this->client->request('GET', "/photos/{$photo->getId()}");
    }

    private function exists(Photo $photo): bool
    {
        return (bool) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_photo WHERE id = ?', [$photo->getId()]);
    }

    public function testOwnerDeletesAnUnusedPhotoWithItsFilesAndThumbnails(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $filename = $photo->getFilename();
        self::assertCount(1, glob(static::getContainer()->getParameter('photos_cache_directory').'/thumb/*'));
        $this->loginAs($alice);
        $this->open($photo);

        $this->delete($photo);

        self::assertResponseRedirects('/photos');
        self::assertFalse($this->exists($photo));
        self::assertFileDoesNotExist($this->photoDir.'/'.$filename);
        self::assertSame([], glob(static::getContainer()->getParameter('photos_cache_directory').'/thumb/*') ?: []);
    }

    public function testOwnerCannotDeleteAPhotoStillUsedByATopicAMessageOrAnAvatar(): void
    {
        $alice = $this->createUser('alice');
        $topicId = $this->createTopic($this->createForum(), $alice)->getId();
        $commentId = $this->createComment($this->em()->find(\App\Entity\Topic::class, $topicId), $alice)->getId();
        $this->loginAs($alice);

        foreach (['topic', 'message', 'avatar'] as $use) {
            $photo = $this->storedPhoto($alice);
            match ($use) {
                'topic' => $this->em()->persist((new PhotoTopic())->setTopic($this->em()->find(\App\Entity\Topic::class, $topicId))->setPhoto($photo)->setOrder(0)),
                'message' => $this->em()->persist((new CommentPhoto())->setComment($this->em()->find(\App\Entity\Comment::class, $commentId))->setPhoto($photo)->setOrder(0)),
                'avatar' => $this->em()->find(User::class, $alice->getId())->setAvatar($photo),
            };
            $this->em()->flush();
            $this->open($photo);

            $this->delete($photo);

            self::assertResponseRedirects("/photos/{$photo->getId()}");
            self::assertTrue($this->exists($photo), "Still used as $use: kept.");
            self::assertFileExists($this->photoDir.'/'.$photo->getFilename());
        }
    }

    public function testAnotherMemberCannotDelete(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);
        $this->open($photo);
        $token = $this->client->getCrawler()->filter('form[action$="/delete"] input[name=_token]')->attr('value');

        $this->loginAs($this->createUser('mallory'));
        $this->open($photo);
        self::assertCount(0, $this->client->getCrawler()->filter('form[action$="/delete"]'), 'No delete button for strangers.');
        $this->delete($photo, $token);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->exists($photo));
    }

    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $photo = $this->storedPhoto($this->createUser('alice'));

        $this->client->request('POST', "/photos/{$photo->getId()}/delete");

        self::assertResponseRedirects('/login');
        self::assertTrue($this->exists($photo));
    }

    public function testAMissingCsrfTokenIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);

        $this->client->request('POST', "/photos/{$photo->getId()}/delete");

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->exists($photo));
    }

    public function testAdministratorDeletesAnyPhotoEverywhere(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $topic = $this->createTopic($this->createForum(), $alice);
        $comment = $this->createComment($topic, $alice);
        $photo = $this->storedPhoto($alice);
        $this->em()->persist((new PhotoTopic())->setTopic($topic)->setPhoto($photo)->setOrder(0));
        $this->em()->persist((new CommentPhoto())->setComment($comment)->setPhoto($photo)->setOrder(0));
        $this->em()->persist(new PhotoLike($photo, $bob));
        $this->em()->find(User::class, $bob->getId())->setAvatar($photo);
        $this->em()->flush();
        $filename = $photo->getFilename();
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));
        $this->open($photo);

        $this->delete($photo);

        self::assertResponseRedirects('/photos');
        $connection = $this->em()->getConnection();
        self::assertFalse($this->exists($photo));
        foreach (['ctso_photo_topic', 'ctso_comment_photo', 'ctso_photo_like'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM $table"), "$table link removed");
        }
        self::assertNull($connection->fetchOne('SELECT avatar_id FROM fos_user WHERE id = ?', [$bob->getId()]), 'He keeps his account, without avatar.');
        self::assertFileDoesNotExist($this->photoDir.'/'.$filename);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ctso_comment WHERE id = ?', [$comment->getId()]), 'The message itself stays.');
    }
}
