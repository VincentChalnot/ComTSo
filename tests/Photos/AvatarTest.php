<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;
use App\Tests\Support\PhotoRowsTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Changing and removing your own avatar on the configuration page. */
final class AvatarTest extends AppWebTestCase
{
    use PhotoFilesTrait;
    use PhotoRowsTrait;

    private const CHANGE = 'Changer l\'avatar';
    private const REMOVE = 'Retirer mon avatar';

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

    /** Opens the configuration page of $username and submits the avatar form through the given button. */
    private function submitAvatar(?UploadedFile $file, string $button = self::CHANGE, string $username = 'alice'): void
    {
        $crawler = $this->client->request('GET', "/gens/$username/config");
        $form = $crawler->selectButton($button)->form();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), $file ? ['avatar' => ['file' => $file]] : []);
    }

    private function reload(string $username): User
    {
        $this->em()->clear();

        return $this->em()->getRepository(User::class)->findOneBy(['username' => $username]);
    }

    private function rowExists(int $photoId): bool
    {
        return null !== $this->em()->getRepository(Photo::class)->find($photoId);
    }

    /** Logs alice in and gives her an avatar through the form: returns the stored photo. */
    private function aliceWithAnAvatar(): Photo
    {
        $this->loginAs($this->createUser('alice'));
        $this->submitAvatar($this->jpegUpload('first.jpg', 400, 300));
        self::assertResponseRedirects('/gens/alice/config');

        return $this->reload('alice')->getAvatar();
    }

    public function testTheMemberChoosesTheirAvatarOnTheirConfigurationPage(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->submitAvatar($this->jpegUpload('portrait.jpg', 800, 600));

        self::assertResponseRedirects('/gens/alice/config');
        $avatar = $this->reload('alice')->getAvatar();
        self::assertNotNull($avatar);
        self::assertSame('portrait.jpg', $avatar->getOriginalFilename());
        self::assertSame('image/webp', $avatar->getFileType(), 'The upload went through the photo pipeline.');
        self::assertSame('alice', $avatar->getAuthor()->getUsername());
        self::assertSame([$avatar->getFilename()], $this->storedFiles());

        $this->client->followRedirect();
        self::assertStringContainsString('Votre avatar a été modifié', $this->client->getResponse()->getContent());
    }

    public function testTheAvatarIsShownWhereverAvatarsAreShown(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);
        $this->submitAvatar($this->jpegUpload());
        $avatar = $this->reload('alice')->getAvatar();
        $src = "/photos/{$avatar->getId()}/avatar?v={$avatar->getFileVersion()}";

        $this->client->request('GET', '/gens/alice');
        $crawler = $this->client->getCrawler();
        self::assertSame($src, $crawler->filter('.masthead-user img.avatar')->attr('src'), 'masthead');
        self::assertSame($src, $crawler->filter('.profile-avatar')->attr('src'), 'profile');

        $this->client->request('GET', '/gens');
        self::assertSame($src, $this->client->getCrawler()->filter('.person-card-avatar img')->attr('src'), 'members list');

        $this->client->request('GET', $src);
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testReplacingTheAvatarDeletesThePreviousFileAndRow(): void
    {
        $old = $this->aliceWithAnAvatar();
        $oldId = $old->getId();
        $oldFile = $old->getFilename();

        $this->submitAvatar($this->jpegUpload('second.jpg', 300, 300));

        self::assertResponseRedirects('/gens/alice/config');
        $new = $this->reload('alice')->getAvatar();
        self::assertNotSame($oldId, $new->getId());
        self::assertSame('second.jpg', $new->getOriginalFilename());
        self::assertFalse($this->rowExists($oldId), 'The previous avatar row is gone.');
        self::assertSame([$new->getFilename()], $this->storedFiles(), 'Only the new file is left.');
        self::assertNotContains($oldFile, $this->storedFiles());
    }

    public function testThePreviousAvatarIsKeptWhenAMessageStillUsesIt(): void
    {
        $old = $this->aliceWithAnAvatar();
        $oldId = $old->getId();
        $alice = $this->reload('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->attachToTopic($topic, $this->em()->find(Photo::class, $oldId), 0);

        $this->submitAvatar($this->jpegUpload('second.jpg'));

        self::assertResponseRedirects('/gens/alice/config');
        self::assertNotSame($oldId, $this->reload('alice')->getAvatar()->getId());
        self::assertTrue($this->rowExists($oldId), 'A photo used by a topic stays in the library.');
        self::assertCount(2, $this->storedFiles(), 'Its file stays too.');
    }

    public function testRemovingTheAvatarDeletesThePhoto(): void
    {
        $avatar = $this->aliceWithAnAvatar();
        $id = $avatar->getId();

        $this->submitAvatar(null, self::REMOVE);

        self::assertResponseRedirects('/gens/alice/config');
        self::assertNull($this->reload('alice')->getAvatar());
        self::assertFalse($this->rowExists($id));
        self::assertSame([], $this->storedFiles());
        $this->client->followRedirect();
        self::assertStringContainsString('Votre avatar a été retiré', $this->client->getResponse()->getContent());
        self::assertCount(0, $this->client->getCrawler()->filter('button:contains("Retirer mon avatar")'), 'Nothing left to remove.');
    }

    public function testRemovingTheAvatarKeepsAPhotoThatAMessageStillUses(): void
    {
        $avatar = $this->aliceWithAnAvatar();
        $id = $avatar->getId();
        $alice = $this->reload('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->attachToTopic($topic, $this->em()->find(Photo::class, $id), 0);

        $this->submitAvatar(null, self::REMOVE);

        self::assertNull($this->reload('alice')->getAvatar());
        self::assertTrue($this->rowExists($id));
        self::assertCount(1, $this->storedFiles());
    }

    public function testTheRemoveButtonOnlyExistsWithAnAvatar(): void
    {
        $this->loginAs($this->createUser('alice'));

        $crawler = $this->client->request('GET', '/gens/alice/config');
        self::assertCount(1, $crawler->selectButton(self::CHANGE));
        self::assertCount(0, $crawler->selectButton(self::REMOVE));
    }

    public function testAFileThatIsNotAnImageIsRefusedAndNothingChanges(): void
    {
        $avatar = $this->aliceWithAnAvatar();

        $this->submitAvatar($this->fakeImageUpload('evil.jpg'));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('pas une image', $this->client->getResponse()->getContent());
        self::assertSame($avatar->getId(), $this->reload('alice')->getAvatar()->getId());
        self::assertSame([$avatar->getFilename()], $this->storedFiles());
    }

    public function testAFileOverTenMegabytesIsRefused(): void
    {
        $this->loginAs($this->createUser('alice'));
        file_put_contents($path = sys_get_temp_dir().'/'.uniqid('big-', true).'.jpg', str_repeat('a', 10 * 1024 * 1024 + 1));

        $this->submitAvatar(new UploadedFile($path, 'big.jpg', 'image/jpeg', null, true));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('trop lourde', $this->client->getResponse()->getContent());
        self::assertStringContainsString('10 Mo au maximum', $this->client->getResponse()->getContent());
        self::assertNull($this->reload('alice')->getAvatar());
        self::assertSame([], $this->storedFiles());
    }

    public function testAnImageThatPassesTheHeaderChecksButCannotBeDecodedStoresNothing(): void
    {
        $this->loginAs($this->createUser('alice'));
        // A valid PNG signature and IHDR, then garbage: the type and size checks pass, the pipeline refuses.
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 64, 64, 8, 2, 0, 0, 0).str_repeat("\0", 4).'garbage';
        file_put_contents($path = sys_get_temp_dir().'/'.uniqid('broken-', true).'.png', $png);

        $this->submitAvatar(new UploadedFile($path, 'broken.png', 'image/png', null, true));

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->reload('alice')->getAvatar());
        self::assertSame([], $this->storedFiles());
        self::assertSame(0, $this->em()->getRepository(Photo::class)->count([]));
    }

    public function testSubmittingWithoutAFileAsksForOne(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->submitAvatar(null);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choisissez une image', $this->client->getResponse()->getContent());
    }

    public function testTheFormIsOnlyOnYourOwnConfigurationPage(): void
    {
        $this->createUser('alice');
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/gens/alice/config');

        self::assertResponseIsSuccessful('An administrator may open the page (message order)…');
        self::assertCount(0, $crawler->selectButton(self::CHANGE), '…but there is no avatar form for somebody else.');

        $crawler = $this->client->request('GET', '/gens/root/config');
        self::assertCount(1, $crawler->selectButton(self::CHANGE));
    }

    public function testNobodyElseCanChangeOrRemoveYourAvatar(): void
    {
        $avatar = $this->aliceWithAnAvatar();
        $this->createUser('mallory');
        $this->createUser('root', ['ROLE_ADMIN']);

        foreach (['mallory', 'root'] as $intruder) {
            $this->loginAs($this->reload($intruder));
            foreach ([['avatar' => ['upload' => '']], ['avatar' => ['remove' => '']]] as $values) {
                $this->client->request('POST', '/gens/alice/avatar', $values, ['avatar' => ['file' => $this->jpegUpload('hack.jpg')]]);
                self::assertResponseStatusCodeSame(403, $intruder);
            }
        }

        self::assertSame($avatar->getId(), $this->reload('alice')->getAvatar()->getId());
        self::assertSame([$avatar->getFilename()], $this->storedFiles());
    }

    public function testAnonymousVisitorsAreSentToTheLoginForm(): void
    {
        $this->createUser('alice');

        $this->client->request('POST', '/gens/alice/avatar', ['avatar' => ['upload' => '']], ['avatar' => ['file' => $this->jpegUpload()]]);

        self::assertResponseRedirects('/login');
        self::assertSame([], $this->storedFiles());
    }

    public function testAPostWithoutTheFormTokenChangesNothing(): void
    {
        $avatar = $this->aliceWithAnAvatar();

        $crawler = $this->client->request('GET', '/gens/alice/config');
        $values = $crawler->selectButton(self::REMOVE)->form()->getPhpValues();
        unset($values['avatar']['_token']);
        $this->client->request('POST', '/gens/alice/avatar', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($avatar->getId(), $this->reload('alice')->getAvatar()->getId());

        $values['avatar']['_token'] = 'forged';
        $this->client->request('POST', '/gens/alice/avatar', $values, ['avatar' => ['file' => $this->jpegUpload('csrf.jpg')]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($avatar->getId(), $this->reload('alice')->getAvatar()->getId());
        self::assertSame([$avatar->getFilename()], $this->storedFiles(), 'The file of a forged request is not even stored.');
    }
}
