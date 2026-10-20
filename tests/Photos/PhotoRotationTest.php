<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Entity\User;
use App\Service\ImageProcessingService;
use App\Service\PhotoUploader;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Quarter turns of a photo for its owner and the administrators. A turn is a stored value (`Photo::getRotation()`): the stored
 * file is never rewritten (byte for byte), the generated variants are turned, URLs change, permissions hold.
 */
final class PhotoRotationTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    private const WIDTH = 800;
    private const HEIGHT = 600;

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

    /** A blue 800 × 600 picture with a red block in its top-left corner: where the block ends up tells the direction of a turn. */
    private function markedJpeg(): UploadedFile
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, 199, 149, imagecolorallocate($image, 255, 0, 0));
        imagejpeg($image, $path = sys_get_temp_dir().'/'.uniqid('marked-', true).'.jpg', 95);

        return new UploadedFile($path, 'marked.jpg', 'image/jpeg', null, true);
    }

    private function storedPhoto(User $author, ?UploadedFile $file = null): Photo
    {
        $photo = static::getContainer()->get(PhotoUploader::class)->store($file ?? $this->markedJpeg(), $this->em()->getReference(User::class, $author->getId()));
        $photo->setTitle('Marmotte')->setTakenAt(new \DateTime('2020-05-17 10:00:00'));
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    /** The rotation form of the given direction on the photo page. */
    private function rotationForm(Crawler $page, string $direction): \Symfony\Component\DomCrawler\Form
    {
        return $page->filter('form[action$="/rotate"]')
            ->reduce(static fn (Crawler $form): bool => 1 === $form->filter(sprintf('input[name=direction][value=%s]', $direction))->count())
            ->form();
    }

    /** Opens the photo page and posts the rotation form of the given direction. */
    private function rotate(Photo $photo, string $direction): void
    {
        $form = $this->rotationForm($this->client->request('GET', "/photos/{$photo->getId()}"), $direction);

        $this->client->request('POST', $form->getUri(), $form->getPhpValues());
    }

    private function reload(Photo $photo): Photo
    {
        $this->em()->clear();

        return $this->em()->find(Photo::class, $photo->getId());
    }

    /** @return array{int, int, string} width and height of the variant, and the colour ("red", "blue") at the given point */
    private function inspectVariant(Photo $photo, string $variant, int $x, int $y): array
    {
        $this->client->request('GET', "/photos/{$photo->getId()}/$variant?v={$photo->getFileVersion()}");
        self::assertResponseIsSuccessful();
        $image = imagecreatefromstring($this->client->getInternalResponse()->getContent());
        $rgb = imagecolorat($image, $x, $y);
        [$red, $blue] = [($rgb >> 16) & 0xFF, $rgb & 0xFF];

        return [imagesx($image), imagesy($image), $red > 150 && $blue < 100 ? 'red' : ($blue > 150 && $red < 100 ? 'blue' : "other($red,$blue)")];
    }

    private function storedBytes(Photo $photo): string
    {
        return (string) file_get_contents($this->photoDir.'/'.$photo->getFilename());
    }

    public function testOwnerTurnsThePhotoRightAndOnlyTheVariantsFollow(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $bytes = $this->storedBytes($photo);
        $filename = $photo->getFilename();
        $this->loginAs($alice);

        $this->rotate($photo, 'right');

        self::assertResponseRedirects("/photos/{$photo->getId()}");
        $photo = $this->reload($photo);
        self::assertSame(90, $photo->getRotation());
        self::assertSame($filename, $photo->getFilename(), 'The stored file keeps its name…');
        self::assertSame($bytes, $this->storedBytes($photo), '…and its bytes.');
        self::assertSame([self::WIDTH, self::HEIGHT], [$photo->getWidth(), $photo->getHeight()], 'The row describes the stored file.');
        self::assertSame([$filename], $this->storedFiles());
        // Clockwise: the top-left block goes to the top-right corner of the 600 x 800 variant.
        self::assertSame([self::HEIGHT, self::WIDTH, 'red'], $this->inspectVariant($photo, 'medium', 520, 100));
        self::assertSame('blue', $this->inspectVariant($photo, 'medium', 80, 100)[2]);

        $this->client->request('GET', "/photos/{$photo->getId()}");
        self::assertStringContainsString('pivotée vers la droite', $this->client->getResponse()->getContent());
    }

    public function testOwnerTurnsThePhotoLeft(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);

        $this->rotate($photo, 'left');

        $photo = $this->reload($photo);
        self::assertSame(270, $photo->getRotation());
        // Counter-clockwise: the top-left block goes to the bottom-left corner.
        self::assertSame([self::HEIGHT, self::WIDTH, 'red'], $this->inspectVariant($photo, 'medium', 70, 700));
        self::assertSame('blue', $this->inspectVariant($photo, 'medium', 520, 100)[2]);
    }

    public function testTurnsAccumulateAndTheStoredFileStaysByteForByteTheSame(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $bytes = $this->storedBytes($photo);
        $this->loginAs($alice);

        $expected = [90, 180, 270, 0, 270, 0];
        foreach (['right', 'right', 'right', 'right', 'left', 'right'] as $i => $direction) {
            $this->rotate($photo, $direction);
            self::assertSame($expected[$i], $this->reload($photo)->getRotation(), "after turn $i ($direction)");
            self::assertSame($bytes, $this->storedBytes($photo), "the file is untouched after turn $i");
        }

        $photo = $this->reload($photo);
        self::assertSame([self::WIDTH, self::HEIGHT, 'red'], $this->inspectVariant($photo, 'medium', 100, 70), 'Four right turns (and a left then a right) are back to the upright picture.');
        self::assertSame([$photo->getFilename()], $this->storedFiles());
    }

    public function testAHalfTurnTurnsThePictureUpsideDown(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);

        $this->rotate($photo, 'right');
        $this->rotate($photo, 'right');

        $photo = $this->reload($photo);
        self::assertSame([self::WIDTH, self::HEIGHT, 'red'], $this->inspectVariant($photo, 'medium', 700, 550));
        self::assertSame('blue', $this->inspectVariant($photo, 'medium', 100, 70)[2]);
    }

    public function testEverythingElseAboutThePhotoIsKept(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $photo->setExif(['Make' => 'Cam', 'Orientation' => 6, 'OriginalWidth' => 4000, 'OriginalHeight' => 3000])->setGpsLatitude(48.85)->setGpsLongitude(2.35);
        $this->em()->flush();
        $id = $photo->getId();
        $this->loginAs($alice);

        $this->rotate($photo, 'right');

        $photo = $this->reload($photo);
        self::assertSame($id, $photo->getId());
        self::assertSame('Marmotte', $photo->getTitle());
        self::assertSame('alice', $photo->getAuthor()->getUsername());
        self::assertEqualsWithDelta(48.85, $photo->getGpsLatitude(), 0.0001);
        self::assertSame(['Make' => 'Cam', 'Orientation' => 6, 'OriginalWidth' => 4000, 'OriginalHeight' => 3000], $photo->getExif(), 'The metadata is not rewritten by a turn.');
    }

    public function testEveryVariantIsGeneratedTurnedAndTheCachedOnesAreDroppedByATurn(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $images = static::getContainer()->get(ImageProcessingService::class);
        $stem = pathinfo($photo->getFilename(), \PATHINFO_FILENAME);
        foreach (array_keys(ImageProcessingService::VARIANTS) as $variant) {
            self::assertNotNull($images->thumbnailPath($photo->getFilename(), $variant));
            self::assertFileExists($images->cacheDirectory()."/$variant/$stem.webp");
        }
        $this->loginAs($alice);

        $this->rotate($photo, 'right');

        foreach (array_keys(ImageProcessingService::VARIANTS) as $variant) {
            self::assertFileDoesNotExist($images->cacheDirectory()."/$variant/$stem.webp", "The unturned $variant must not be served any more.");
        }
        $photo = $this->reload($photo);
        self::assertSame([self::HEIGHT, self::WIDTH], \array_slice($this->inspectVariant($photo, 'medium', 0, 0), 0, 2));
        self::assertSame('red', $this->inspectVariant($photo, 'hero', 590, 5)[2], 'hero is turned too');
        self::assertSame('red', $this->inspectVariant($photo, 'thumb', 315, 2)[2], 'the cover thumbnail is turned too');
        self::assertSame('red', $this->inspectVariant($photo, 'avatar', 93, 2)[2], 'the avatar is turned too');
    }

    public function testImageUrlsChangeWithTheRotationSoBrowsersDoNotKeepTheOldPicture(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);
        $before = $this->client->request('GET', "/photos/{$photo->getId()}")->filter('figure img')->attr('src');

        $this->rotate($photo, 'right');
        $after = $this->client->request('GET', "/photos/{$photo->getId()}")->filter('figure img')->attr('src');

        self::assertNotSame($before, $after);
        self::assertStringStartsWith("/photos/{$photo->getId()}/medium?v=", $after);
        $this->client->request('GET', $after);
        self::assertStringContainsString('immutable', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheOriginalDownloadIsTheUntouchedFile(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $bytes = $this->storedBytes($photo);
        $this->loginAs($alice);
        $this->rotate($photo, 'right');
        $photo = $this->reload($photo);

        $this->client->request('GET', "/photos/source/{$photo->getId()}?download=1&v={$photo->getFileVersion()}");

        self::assertResponseIsSuccessful();
        self::assertSame($bytes, file_get_contents($this->client->getResponse()->getFile()->getPathname()));
    }

    public function testThePageOffersIconOnlyButtonsWithAccessibleNames(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', "/photos/{$photo->getId()}");

        foreach (['left' => 'Pivoter à gauche', 'right' => 'Pivoter à droite'] as $direction => $label) {
            $button = $crawler->filterXPath("//form[contains(@action, '/rotate')][.//input[@name='direction'][@value='$direction']]//button");
            self::assertCount(1, $button);
            self::assertSame($label, $button->attr('aria-label'));
            self::assertSame($label, $button->attr('title'));
            self::assertSame('', trim($button->text()), 'icon only, no visible label');
        }
    }

    public function testAnAdministratorMayTurnAnotherMembersPhoto(): void
    {
        $photo = $this->storedPhoto($this->createUser('alice'));
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));

        $this->rotate($photo, 'left');

        self::assertResponseRedirects("/photos/{$photo->getId()}");
        self::assertSame(270, $this->reload($photo)->getRotation());
    }

    public function testAnotherMemberHasNoButtonAndIsRefused(): void
    {
        $photo = $this->storedPhoto($this->createUser('alice'));
        $mallory = $this->createUser('mallory');
        $this->loginAs($mallory);

        $crawler = $this->client->request('GET', "/photos/{$photo->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/rotate"]'));

        foreach (['right', 'left'] as $direction) {
            $this->client->request('POST', "/photos/{$photo->getId()}/rotate", ['direction' => $direction, '_token' => 'whatever']);
            self::assertResponseStatusCodeSame(403);
        }
        self::assertSame(0, $this->reload($photo)->getRotation());
    }

    public function testAnonymousVisitorsAreSentToTheLoginForm(): void
    {
        $photo = $this->storedPhoto($this->createUser('alice'));

        $this->client->request('POST', "/photos/{$photo->getId()}/rotate", ['direction' => 'right']);

        self::assertResponseRedirects('/login');
    }

    public function testAWrongTokenOrDirectionChangesNothing(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);
        $crawler = $this->client->request('GET', "/photos/{$photo->getId()}");
        $token = $crawler->filter('form[action$="/rotate"] input[name=_token]')->attr('value');

        $this->client->request('POST', "/photos/{$photo->getId()}/rotate", ['direction' => 'right', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', "/photos/{$photo->getId()}/rotate", ['direction' => 'right']);
        self::assertResponseStatusCodeSame(403);
        foreach (['up', '', '180', 'RIGHT'] as $direction) {
            $this->client->request('POST', "/photos/{$photo->getId()}/rotate", ['direction' => $direction, '_token' => $token]);
            self::assertResponseStatusCodeSame(400, "direction=$direction");
        }

        self::assertSame(0, $this->reload($photo)->getRotation());
    }

    public function testATurnCannotBeTriggeredByAGetRequest(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->storedPhoto($alice);
        $this->loginAs($alice);

        $this->client->request('GET', "/photos/{$photo->getId()}/rotate", ['direction' => 'right']);

        self::assertResponseStatusCodeSame(404, 'Like every POST-only route: there is nothing to GET.');
        self::assertSame(0, $this->reload($photo)->getRotation());
    }

    public function testAnAnimatedGifIsRefusedAndStaysAsItWas(): void
    {
        $alice = $this->createUser('alice');
        $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00\x2C\x00\x00\x00\x00".pack('vv', 4, 3)."\x00\x02\x02D\x01\x00";
        file_put_contents($path = sys_get_temp_dir().'/'.uniqid('anim-', true).'.gif', 'GIF89a'.pack('vv', 4, 3)."\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF".$frame.$frame.';');
        $photo = $this->storedPhoto($alice, new UploadedFile($path, 'anim.gif', 'image/gif', null, true));
        self::assertSame('image/gif', $photo->getFileType(), 'The pipeline keeps animated GIFs as GIF.');
        $this->loginAs($alice);

        // The page offers no turn for a GIF…
        $crawler = $this->client->request('GET', "/photos/{$photo->getId()}");
        self::assertCount(0, $crawler->filter('form[action$="/rotate"]'));

        // …and the server refuses a forged POST (valid token, taken from the page while the photo was mislabelled).
        $this->reload($photo)->setFileType('image/webp');
        $this->em()->flush();
        $form = $this->rotationForm($this->client->request('GET', "/photos/{$photo->getId()}"), 'right');
        $this->reload($photo)->setFileType('image/gif');
        $this->em()->flush();
        $this->client->request('POST', $form->getUri(), $form->getPhpValues());

        self::assertResponseRedirects("/photos/{$photo->getId()}");
        $this->client->followRedirect();
        self::assertStringContainsString('GIF animé', $this->client->getResponse()->getContent());
        self::assertSame(0, $this->reload($photo)->getRotation());
    }
}
