<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Photo\PhotoDetails;
use App\Tests\Support\AppWebTestCase;

final class PhotoDetailsTest extends AppWebTestCase
{
    private function phonePhoto(?\App\Entity\User $author = null): Photo
    {
        $photo = (new Photo())->setFilename('d-'.bin2hex(random_bytes(3)).'.webp')->setOriginalFilename('IMG_0001.jpg')->setFileSize(266 * 1024)->setFileType('image/webp')
            ->setWidth(1600)->setHeight(1200)->setTakenAt(new \DateTime('2018-10-20 13:22:00'))->setAuthor($author)
            ->setGpsLatitude(48.856614)->setGpsLongitude(2.352222)->setGpsAltitude(35.5)
            // The thinner map the Symfony 2 forum stored: strings, the exposure as a decimal number.
            ->setExif(['Make' => 'samsung', 'Model' => 'SM-A310F', 'ExposureTime' => '0.0303030303', 'FNumber' => '1.9', 'ISOSpeedRatings' => '160', 'FocalLength' => '3.7']);

        return $photo;
    }

    /** @param list<array{label: string, value: string}> $rows @return array<string, string> */
    private static function map(array $rows): array
    {
        return array_column($rows, 'value', 'label');
    }

    public function testLegacyStyleExifGivesTheExpectedRows(): void
    {
        $rows = self::map((new PhotoDetails())->rows($this->phonePhoto()));

        self::assertSame('20/10/2018 à 13:22', $rows['Date de prise']);
        self::assertSame('266 Ko', $rows['Taille']);
        self::assertSame('1600 × 1200 px', $rows['Dimensions']);
        self::assertSame('samsung SM-A310F', $rows['Appareil']);
        self::assertSame('1/33 s', $rows['Exposition']);
        self::assertSame('f/1.9', $rows['Ouverture']);
        self::assertSame('160', $rows['ISO']);
        self::assertSame('3.7 mm', $rows['Focale']);
        self::assertArrayNotHasKey('Position GPS', $rows, 'GPS is opt-in.');
    }

    public function testRichExifAndOnlyExistingRows(): void
    {
        $photo = (new Photo())->setFileSize(2_500_000)->setWidth(2000)->setHeight(1500)->setExif([
            'Make' => 'Canon', 'Model' => 'Canon EOS 90D', 'LensModel' => 'EF 50mm', 'ExposureTime' => 2.5, 'ExposureTimeFraction' => '2.5', 'FocalLength' => 50.0, 'FocalLengthIn35mmFilm' => 80,
            'Flash' => 1, 'ExposureBiasValue' => -0.7, 'WhiteBalance' => 1, 'OriginalWidth' => 6000, 'OriginalHeight' => 4000,
        ]);

        $rows = self::map((new PhotoDetails())->rows($photo));

        self::assertSame('Canon EOS 90D', $rows['Appareil'], 'The make is not repeated when the model starts with it.');
        self::assertSame('EF 50mm', $rows['Objectif']);
        self::assertSame('2.5 s', $rows['Exposition']);
        self::assertSame('50 mm (équivalent 35 mm : 80 mm)', $rows['Focale']);
        self::assertSame('Déclenché', $rows['Flash']);
        self::assertSame('-0.7 IL', $rows['Compensation d\'exposition']);
        self::assertSame('Manuelle', $rows['Balance des blancs']);
        self::assertSame('2,4 Mo', $rows['Taille']);
        self::assertSame('2000 × 1500 px (original : 6000 × 4000)', $rows['Dimensions']);
        self::assertArrayNotHasKey('ISO', $rows);
        self::assertArrayNotHasKey('Date de prise', $rows);
        self::assertSame([], (new PhotoDetails())->rows(new Photo()));
    }

    public function testGpsIsShownToTheOwnerAndAdministratorsOnly(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->phonePhoto($alice);
        $this->em()->persist($photo);
        $this->em()->flush();
        $url = "/photos/{$photo->getId()}";

        $this->loginAs($alice);
        self::assertStringContainsString('48.85661, 2.35222', $this->client->request('GET', $url)->filter('dl.dl')->text());
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));
        self::assertStringContainsString('Position GPS', $this->client->request('GET', $url)->filter('dl.dl')->text());

        $this->loginAs($this->createUser('mallory'));
        $details = $this->client->request('GET', $url)->filter('dl.dl')->text();
        self::assertStringNotContainsString('GPS', $details);
        self::assertStringNotContainsString('48.85', $details);
        self::assertStringNotContainsString('48.85', $this->client->getResponse()->getContent(), 'Not hidden by CSS: absent from the page.');
        self::assertStringContainsString('SM-A310F', $details, 'The rest of the details are for every member.');
    }
}
