<?php

namespace App\Tests\Forum;

use App\Entity\Photo;
use App\Tests\Support\AppWebTestCase;

class HomeHeroTest extends AppWebTestCase
{
    private function photo(string $title, ?\App\Entity\User $author = null): Photo
    {
        $photo = (new Photo())
            ->setFilename('hero-test.jpeg')
            ->setOriginalFilename('hero-test.jpg')
            ->setFileSize(1000)
            ->setFileType('image/jpeg')
            ->setWidth(3200)
            ->setHeight(1800)
            ->setTitle($title)
            ->setAuthor($author);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    public function testHomeFeaturesARecentPhotoInTheHero(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->photo('Grand large', $alice);
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $hero = $crawler->filter('section.hero');
        self::assertCount(1, $hero);
        self::assertSame('hero-title', $hero->attr('aria-labelledby'));
        self::assertSame('Photo de la semaine', trim($hero->filter('.kicker')->text()));
        self::assertSame('Grand large', trim($hero->filter('#hero-title')->text()));
        self::assertStringContainsString('alice', $hero->filter('.byline')->text());
        self::assertSame("/photos/{$photo->getId()}", $hero->filter('a.btn-primary')->attr('href'));
        self::assertSame("/photos/{$photo->getId()}", $hero->filter('a.hero-media')->attr('href'));
        $image = $hero->filter('img.hero-image');
        self::assertSame("/photos/{$photo->getId()}/hero", $image->attr('src'));
        self::assertSame('Grand large', $image->attr('alt'));
        self::assertSame(['1600', '900'], [$image->attr('width'), $image->attr('height')]);
        self::assertSame('high', $image->attr('fetchpriority'));
    }

    public function testHeroLinksToTheTopicWhereThePhotoAppears(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Sortie photo');
        $photo = $this->photo('Grand large', $alice);
        $this->em()->persist((new \App\Entity\PhotoTopic())->setPhoto($photo)->setTopic($topic));
        $this->em()->flush();
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/');

        $link = $crawler->filter('section.hero .byline a')->reduce(static fn ($node) => 'Sortie photo' === trim($node->text()));
        self::assertCount(1, $link);
        self::assertSame("/forum/general/{$topic->getId()}", $link->attr('href'));
    }

    public function testHomeRendersWithoutHeroWhenThereIsNoPhoto(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('section.hero'));
        self::assertSelectorTextContains('h1', 'Activités récentes');
    }

    public function testAnonymousVisitorsAreStillRedirectedToTheLogin(): void
    {
        $this->photo('Grand large');

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }
}
