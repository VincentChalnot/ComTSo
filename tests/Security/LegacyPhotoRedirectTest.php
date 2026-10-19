<?php

namespace App\Tests\Security;

use App\Entity\Photo;
use App\Tests\Support\AppWebTestCase;

/**
 * 68 imported messages link to the old forum's photo routes `/photos/preview/N.jpg` (a picture opened in a new tab) and
 * `/photos/thumbnail/N.jpg`; N is the old forum's photo number, stored as `original_filename` "N.jpg" by the one-off data load.
 */
class LegacyPhotoRedirectTest extends AppWebTestCase
{
    private function photo(string $originalFilename): Photo
    {
        $photo = (new Photo())->setFilename(bin2hex(random_bytes(6)).'.jpeg')->setOriginalFilename($originalFilename)->setFileSize(10)->setFileType('image/jpeg');
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    public function testAPreviewLinkGoesToTheMediumPictureOfThePhotoWhoseOldNumberItIs(): void
    {
        $this->photo('72.jpg');
        $photo = $this->photo('73.jpg');
        $this->loginAs($this->createUser());

        $this->client->request('GET', '/photos/preview/73.jpg');

        self::assertResponseRedirects('/photos/'.$photo->getId().'/medium', 301);
    }

    public function testAThumbnailLinkGoesToTheThumbnailOfThePhoto(): void
    {
        $photo = $this->photo('73.jpg');
        $this->loginAs($this->createUser());

        $this->client->request('GET', '/photos/thumbnail/73.jpg');

        self::assertResponseRedirects('/photos/'.$photo->getId().'/thumb', 301);
    }

    /** A camera file called 73.jpg uploaded years later must not steal the old links. */
    public function testTheImportedPhotoWinsOverALaterUploadWithTheSameName(): void
    {
        $imported = $this->photo('73.jpg');
        $this->photo('73.jpg');
        $this->loginAs($this->createUser());

        $this->client->request('GET', '/photos/preview/73.jpg');

        self::assertResponseRedirects('/photos/'.$imported->getId().'/medium', 301);
    }

    public function testAnUnknownOldNumberIsNotFound(): void
    {
        $this->loginAs($this->createUser());

        $this->client->request('GET', '/photos/preview/99999.jpg');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousVisitorsStillGoToTheLoginFirst(): void
    {
        $this->photo('73.jpg');

        $this->client->request('GET', '/photos/preview/73.jpg');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/photos/thumbnail/73.jpg');
        self::assertResponseRedirects('/login');
    }

    public function testTheCurrentPhotoRoutesAreUntouched(): void
    {
        $photo = $this->photo('73.jpg');
        $this->loginAs($this->createUser());

        $this->client->request('GET', '/photos/'.$photo->getId());

        self::assertResponseIsSuccessful();
    }
}
