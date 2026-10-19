<?php

namespace App\Tests\Photos;

use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Topic;
use App\Entity\User;
use App\Photo\PostPhotos;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Photos attached to a message (reply form, edit page) and to a new topic. */
class PostPhotosTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    private User $alice;
    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
        $this->alice = $this->createUser('alice');
        $this->topic = $this->createTopic($this->createForum(), $this->alice, 'Album', new \DateTime('2020-01-01'));
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    /** @param list<UploadedFile> $files */
    private function reply(string $html, array $files): void
    {
        $crawler = $this->client->request('GET', "/forum/general/{$this->topic->getId()}");
        $form = $crawler->selectButton('Envoyer')->form();
        $values = $form->getPhpValues();
        $values['comment']['content'] = $html;
        $this->client->request('POST', $form->getUri(), $values, ['comment' => ['photos' => $files]]);
    }

    private function lastComment(): ?Comment
    {
        $this->em()->clear();

        return $this->em()->getRepository(Comment::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function attachedComment(int $photos = 2): Comment
    {
        $this->loginAs($this->alice);
        $this->reply('<p>Regardez</p>', array_map(fn (int $i) => $this->jpegUpload("p$i.jpg"), range(1, $photos)));

        return $this->lastComment();
    }

    public function testReplyWithPhotosAttachesThemToTheMessage(): void
    {
        $this->loginAs($this->alice);

        $this->reply('<p>Regardez</p>', [$this->jpegUpload('a.jpg'), $this->jpegUpload('b.jpg')]);

        self::assertResponseRedirects();
        $comment = $this->lastComment();
        self::assertCount(2, $comment->getPhotos());
        self::assertSame(['a.jpg', 'b.jpg'], array_map(fn (CommentPhoto $l) => $l->getPhoto()->getOriginalFilename(), $comment->getPhotos()->toArray()));
        foreach ($comment->getPhotos() as $link) {
            self::assertSame('alice', $link->getPhoto()->getAuthor()->getUsername(), 'The uploader owns the photo.');
            self::assertSame([800, 600], [$link->getPhoto()->getWidth(), $link->getPhoto()->getHeight()]);
            self::assertFileExists($this->photoDir.'/'.$link->getPhoto()->getFilename());
        }

        $crawler = $this->client->request('GET', "/forum/general/{$this->topic->getId()}");
        self::assertCount(2, $crawler->filter("#post-{$comment->getId()} ul.post-photos li.post-photo a[data-lightbox]"));
        self::assertCount(1, $crawler->filter('#post-'.$comment->getId().' a[data-lightbox="post-'.$comment->getId().'"][data-src*="/medium?v="]')->slice(0, 1), 'The lightbox group is the message.');
    }

    public function testPhotosAlsoAppearInTheGlobalLibrary(): void
    {
        $this->attachedComment(1);

        $crawler = $this->client->request('GET', '/photos');

        self::assertCount(1, $crawler->filter('a[data-lightbox]'));
    }

    public function testAPhotoOnlyMessageIsAllowedButAnEmptyOneIsNot(): void
    {
        $this->loginAs($this->alice);

        $this->reply('', [$this->jpegUpload()]);
        self::assertCount(1, $this->lastComment()->getPhotos());

        $before = $this->em()->getRepository(Comment::class)->count([]);
        $this->reply('', []);
        self::assertSame($before, $this->em()->getRepository(Comment::class)->count([]), 'Neither text nor photo: nothing is posted.');
    }

    public function testPostingPhotosBumpsTheTopic(): void
    {
        $this->attachedComment(1);

        $this->em()->clear();
        self::assertGreaterThan(new \DateTime('-1 minute'), $this->em()->find(Topic::class, $this->topic->getId())->getLastActivityAt());
    }

    public function testNonImagesAreRejectedAndNothingIsStored(): void
    {
        $this->loginAs($this->alice);

        $this->reply('<p>Piège</p>', [$this->jpegUpload(), $this->fakeImageUpload()]);

        self::assertResponseStatusCodeSame(422, 'The page is shown again, with the reason, instead of redirecting.');
        self::assertNull($this->lastComment(), 'The message is not posted when one file is refused.');
        self::assertSame([], $this->storedFiles());
        self::assertSame(0, $this->em()->getRepository(Photo::class)->count([]));
    }

    public function testTooManyPhotosAreRejected(): void
    {
        $this->loginAs($this->alice);

        $this->reply('<p>Trop</p>', array_map(fn (int $i) => $this->jpegUpload("p$i.jpg", 40, 30), range(1, PostPhotos::MAX_PER_POST + 1)));

        self::assertNull($this->lastComment());
        self::assertSame([], $this->storedFiles());
    }

    public function testExactlyTheLimitIsAccepted(): void
    {
        $this->loginAs($this->alice);

        $this->reply('<p>Beaucoup</p>', array_map(fn (int $i) => $this->jpegUpload("p$i.jpg", 40, 30), range(1, PostPhotos::MAX_PER_POST)));

        self::assertCount(PostPhotos::MAX_PER_POST, $this->lastComment()->getPhotos());
    }

    public function testTooLargePhotoIsRejected(): void
    {
        $this->loginAs($this->alice);
        $path = sys_get_temp_dir().'/huge-'.uniqid().'.jpg';
        $handle = fopen($path, 'w');
        fwrite($handle, "\xFF\xD8\xFF\xE0");
        ftruncate($handle, 11 * 1024 * 1024);
        fclose($handle);

        $this->reply('<p>Énorme</p>', [new UploadedFile($path, 'huge.jpg', 'image/jpeg', null, true)]);

        self::assertNull($this->lastComment());
        self::assertSame([], $this->storedFiles());
        unlink($path);
    }

    public function testAuthorCanAddAndRemovePhotosFromTheEditPage(): void
    {
        $comment = $this->attachedComment(2);
        [$keep, $drop] = $comment->getPhotos()->toArray();
        $droppedFile = $drop->getPhoto()->getFilename();

        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}/edit");
        self::assertCount(2, $crawler->filter('input[type=checkbox][name="comment[remove_photos][]"]'));
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['comment']['remove_photos'] = [(string) $drop->getId()];
        $this->client->request('POST', $form->getUri(), $values, ['comment' => ['photos' => [$this->jpegUpload('new.jpg')]]]);

        self::assertResponseRedirects();
        $this->em()->clear();
        $names = array_map(fn (CommentPhoto $l) => $l->getPhoto()->getOriginalFilename(), $this->em()->find(Comment::class, $comment->getId())->getPhotos()->toArray());
        self::assertSame(['p1.jpg', 'new.jpg'], $names);
        self::assertFileDoesNotExist($this->photoDir.'/'.$droppedFile, 'A photo used nowhere else loses its file.');
        self::assertNull($this->em()->find(Photo::class, $drop->getPhoto()->getId()));
        self::assertCount(2, $this->storedFiles());
        self::assertNotNull($this->em()->find(Photo::class, $keep->getPhoto()->getId()));
    }

    public function testDetachedPhotoStillUsedElsewhereKeepsItsFile(): void
    {
        $comment = $this->attachedComment(1);
        $photo = $comment->getPhotos()->first()->getPhoto();
        $this->em()->persist((new PhotoTopic())->setTopic($this->em()->find(Topic::class, $this->topic->getId()))->setPhoto($photo)->setOrder(0));
        $this->em()->flush();

        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}/edit");
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['comment']['remove_photos'] = [(string) $comment->getPhotos()->first()->getId()];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertCount(0, $this->em()->find(Comment::class, $comment->getId())->getPhotos());
        self::assertNotNull($this->em()->find(Photo::class, $photo->getId()));
        self::assertFileExists($this->photoDir.'/'.$photo->getFilename());
    }

    public function testEditingCannotExceedTheLimit(): void
    {
        $comment = $this->attachedComment(PostPhotos::MAX_PER_POST);

        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}/edit");
        $form = $crawler->selectButton('Enregistrer')->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), ['comment' => ['photos' => [$this->jpegUpload()]]]);

        self::assertResponseStatusCodeSame(422, 'The form is shown again with the error.');
        self::assertStringContainsString('plus de '.PostPhotos::MAX_PER_POST.' photos', $this->client->getResponse()->getContent());
        $this->em()->clear();
        self::assertCount(PostPhotos::MAX_PER_POST, $this->em()->find(Comment::class, $comment->getId())->getPhotos());
    }

    public function testAnotherMemberCannotAttachOrDetachPhotos(): void
    {
        $comment = $this->attachedComment(1);
        $link = $comment->getPhotos()->first();
        $this->loginAs($this->createUser('mallory'));

        $this->client->request('GET', "/comment/{$comment->getId()}/edit");
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', "/comment/{$comment->getId()}/edit", ['comment' => ['content' => '<p>x</p>', 'remove_photos' => [(string) $link->getId()]]], ['comment' => ['photos' => [$this->jpegUpload()]]]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertCount(1, $this->em()->find(Comment::class, $comment->getId())->getPhotos());
        self::assertCount(1, $this->storedFiles());
    }

    public function testAdministratorCanRemoveAMemberPhoto(): void
    {
        $comment = $this->attachedComment(1);
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}/edit");
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['comment']['remove_photos'] = [(string) $comment->getPhotos()->first()->getId()];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        self::assertSame([], $this->storedFiles());
    }

    public function testRemovedPhotoIdOfAnotherMessageIsRefused(): void
    {
        $mine = $this->attachedComment(1);
        $this->loginAs($this->alice);
        $this->reply('<p>Deux</p>', [$this->jpegUpload('c.jpg')]);
        $foreign = $this->lastComment()->getPhotos()->first();

        $crawler = $this->client->request('GET', "/comment/{$mine->getId()}/edit");
        $form = $crawler->selectButton('Enregistrer')->form();
        $values = $form->getPhpValues();
        $values['comment']['remove_photos'] = [(string) $foreign->getId()];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422, 'A link of another message is not one of the choices.');
        $this->em()->clear();
        self::assertSame(2, $this->em()->getRepository(CommentPhoto::class)->count([]));
    }

    public function testDeletingAMessageRemovesItsLinksAndOrphanFiles(): void
    {
        $comment = $this->attachedComment(2);
        $shared = $comment->getPhotos()->first()->getPhoto();
        $this->em()->persist((new PhotoTopic())->setTopic($this->em()->find(Topic::class, $this->topic->getId()))->setPhoto($shared)->setOrder(0));
        $this->em()->flush();

        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}");
        $token = $crawler->filter('form[action$="/delete"] input[name=_token]')->attr('value');
        $this->client->request('POST', "/comment/{$comment->getId()}/delete", ['_token' => $token]);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertSame(0, $this->em()->getRepository(CommentPhoto::class)->count([]));
        self::assertSame([$shared->getFilename()], $this->storedFiles(), 'Only the photo still used by the topic keeps its file.');
        self::assertSame(1, $this->em()->getRepository(Photo::class)->count([]));
    }

    public function testAvatarPhotoIsNeverDeletedWithTheMessage(): void
    {
        $comment = $this->attachedComment(1);
        $photo = $this->em()->find(Photo::class, $comment->getPhotos()->first()->getPhoto()->getId());
        $this->em()->find(User::class, $this->alice->getId())->setAvatar($photo);
        $this->em()->flush();

        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}");
        $token = $crawler->filter('form[action$="/delete"] input[name=_token]')->attr('value');
        $this->client->request('POST', "/comment/{$comment->getId()}/delete", ['_token' => $token]);

        self::assertCount(1, $this->storedFiles());
    }

    public function testNewTopicWithPhotosAttachesThemToTheTopic(): void
    {
        $this->loginAs($this->alice);

        $crawler = $this->client->request('GET', '/forums/general/nouveau');
        self::assertCount(1, $crawler->filter('.attach input[type=file][multiple][name="topic[photos][]"]'));
        $form = $crawler->selectButton('Créer le sujet')->form();
        $values = $form->getPhpValues();
        $values['topic']['title'] = 'Vacances';
        $this->client->request('POST', $form->getUri(), $values, ['topic' => ['photos' => [$this->jpegUpload('1.jpg'), $this->jpegUpload('2.jpg')]]]);

        self::assertResponseRedirects();
        $this->em()->clear();
        $topic = $this->em()->getRepository(Topic::class)->findOneBy(['title' => 'Vacances']);
        $links = $this->em()->getRepository(PhotoTopic::class)->findBy(['topic' => $topic], ['order' => 'ASC']);
        self::assertSame(['1.jpg', '2.jpg'], array_map(fn (PhotoTopic $l) => $l->getPhoto()->getOriginalFilename(), $links));
        self::assertSame('alice', $links[0]->getPhoto()->getAuthor()->getUsername());
        self::assertCount(2, $this->storedFiles());
    }

    public function testNewTopicWithARefusedFileIsNotCreated(): void
    {
        $this->loginAs($this->alice);

        $crawler = $this->client->request('GET', '/forums/general/nouveau');
        $form = $crawler->selectButton('Créer le sujet')->form();
        $values = $form->getPhpValues();
        $values['topic']['title'] = 'Piège';
        $this->client->request('POST', $form->getUri(), $values, ['topic' => ['photos' => [$this->fakeImageUpload()]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'image acceptée');
        self::assertNull($this->em()->getRepository(Topic::class)->findOneBy(['title' => 'Piège']));
        self::assertSame([], $this->storedFiles());
    }

    public function testPhotoPageListsTheTopicsAndMessagesUsingIt(): void
    {
        $comment = $this->attachedComment(1);
        $photo = $comment->getPhotos()->first()->getPhoto();
        $this->em()->persist((new PhotoTopic())->setTopic($this->em()->find(Topic::class, $this->topic->getId()))->setPhoto($photo)->setOrder(0));
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/photos/'.$photo->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/comment/'.$comment->getId().'"]'), 'The message is listed.');
        self::assertCount(2, $crawler->filter('a[href="/forum/general/'.$this->topic->getId().'"]'), 'The topic is linked from the topic usage and the message usage.');
    }

    public function testOnlyAuthenticatedMembersSeeMessagePhotoFiles(): void
    {
        $comment = $this->attachedComment(1);
        $id = $comment->getPhotos()->first()->getPhoto()->getId();
        $this->client->request('GET', '/logout');
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', "/photos/source/$id");

        self::assertResponseRedirects('/login');
    }
}
