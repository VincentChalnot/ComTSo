<?php

namespace App\Tests\Forum;

use App\Entity\Photo;
use App\Entity\Topic;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;
use App\Tests\Support\PhotoRowsTrait;

/** Administrators can delete a whole topic; nobody else can, not even its author. */
class TopicModerationTest extends AppWebTestCase
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

    /** A photo row with a real file behind it. */
    private function storedPhoto(): Photo
    {
        $photo = $this->photoRow();
        file_put_contents($this->photoDir.'/'.$photo->getFilename(), 'not really a jpeg');

        return $photo;
    }

    private function deleteTopic(Topic $topic, ?string $token = null, bool $fromPage = true): void
    {
        $url = '/forum/general/'.$topic->getId();
        if ($fromPage) {
            $crawler = $this->client->request('GET', $url);
            $form = $crawler->filter('form[action$="/delete"]')->form();
            $this->client->request('POST', $form->getUri(), $form->getPhpValues());

            return;
        }
        $this->client->request('POST', $url.'/delete', null === $token ? [] : ['_token' => $token]);
    }

    private function rows(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    public function testAnAdministratorDeletesATopicWithEverythingInIt(): void
    {
        $admin = $this->createUser('root', ['ROLE_ADMIN']);
        $alice = $this->createUser('alice');
        $forum = $this->createForum();
        $topic = $this->createTopic($forum, $alice, 'À supprimer');
        $other = $this->createTopic($forum, $alice, 'À garder');
        $one = $this->createComment($topic, $alice);
        $this->createComment($topic, $admin);
        $kept = $this->createComment($other, $alice);

        $onlyHere = $this->storedPhoto();
        $this->attachToTopic($topic, $onlyHere);
        $onlyInMessage = $this->storedPhoto();
        $this->attachToComment($one, $onlyInMessage);
        $shared = $this->storedPhoto();
        $this->attachToComment($one, $shared);
        $this->attachToTopic($other, $shared);
        $avatar = $this->storedPhoto();
        $this->attachToTopic($topic, $avatar);
        $alice->setAvatar($avatar);
        $alice->addStarredTopic($topic);
        $alice->addStarredTopic($other);
        $this->em()->flush();
        $this->loginAs($admin);

        $this->deleteTopic($topic);

        self::assertResponseRedirects('/forums/'.$forum->getId());
        $this->em()->clear();
        self::assertNull($this->em()->find(Topic::class, $topic->getId()));
        self::assertNotNull($this->em()->find(Topic::class, $other->getId()), 'the other topic is untouched');
        self::assertSame(1, $this->rows('ctso_comment'), 'only the message of the other topic is left');
        self::assertSame($kept->getId(), (int) $this->em()->getConnection()->fetchOne('SELECT id FROM ctso_comment'));
        self::assertSame(1, $this->rows('ctso_photo_topic'), 'the links of the deleted topic are gone, the other topic keeps its photo');
        self::assertSame(0, $this->rows('ctso_comment_photo'), 'the message photo links are gone');
        self::assertSame(1, $this->rows('ctso_starred_topic'), 'favourites of the deleted topic are gone');

        self::assertNull($this->em()->find(Photo::class, $onlyHere->getId()), 'a photo used nowhere else goes away...');
        self::assertFileDoesNotExist($this->photoDir.'/'.$onlyHere->getFilename(), '...with its file');
        self::assertNull($this->em()->find(Photo::class, $onlyInMessage->getId()));
        self::assertFileDoesNotExist($this->photoDir.'/'.$onlyInMessage->getFilename());
        self::assertNotNull($this->em()->find(Photo::class, $shared->getId()), 'a photo still used by another topic stays');
        self::assertFileExists($this->photoDir.'/'.$shared->getFilename());
        self::assertNotNull($this->em()->find(Photo::class, $avatar->getId()), 'an avatar is never deleted');
        self::assertFileExists($this->photoDir.'/'.$avatar->getFilename());
    }

    public function testTheAuthorOfATopicCannotDeleteIt(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->createComment($topic, $alice);
        $this->loginAs($alice);

        $this->deleteTopic($topic, 'anything', fromPage: false);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->rows('ctso_topic'));
        self::assertSame(1, $this->rows('ctso_comment'));
    }

    public function testAnotherMemberCannotDeleteIt(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->loginAs($this->createUser('mallory'));

        $this->deleteTopic($topic, 'anything', fromPage: false);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->rows('ctso_topic'));
    }

    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $topic = $this->createTopic($this->createForum(), $this->createUser('alice'));

        $this->deleteTopic($topic, 'anything', fromPage: false);

        self::assertResponseRedirects('/login');
        self::assertSame(1, $this->rows('ctso_topic'));
    }

    public function testTheDeleteButtonIsOnlyOfferedToAdministrators(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);

        $this->loginAs($alice);
        $crawler = $this->client->request('GET', '/forum/general/'.$topic->getId());
        self::assertCount(0, $crawler->filter('form[action$="/delete"]'));

        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/forum/general/'.$topic->getId());
        self::assertCount(1, $crawler->filter('form[action$="/delete"][data-confirm]'), 'one button, with a confirmation');
    }
}
