<?php

namespace App\Tests\Forum;

use App\Entity\Photo;
use App\Entity\PhotoLike;
use App\Entity\PhotoTopic;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

class HomeHeroTest extends AppWebTestCase
{
    private function photo(string $title, ?User $author = null): Photo
    {
        $photo = (new Photo())
            ->setFilename(uniqid('hero-test-').'.webp')
            ->setOriginalFilename('hero-test.jpg')
            ->setFileSize(1000)
            ->setFileType('image/webp')
            ->setWidth(3200)
            ->setHeight(1800)
            ->setTitle($title)
            ->setAuthor($author);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    private function like(Photo $photo, User ...$users): void
    {
        foreach ($users as $user) {
            $this->em()->persist(new PhotoLike($photo, $user));
        }
        $this->em()->flush();
    }

    public function testHomeFeaturesALikedPhotoInTheHero(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $photo = $this->photo('Grand large', $alice);
        $this->like($photo, $alice, $bob);
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $hero = $crawler->filter('section.hero');
        self::assertCount(1, $hero);
        self::assertSame('hero-title', $hero->attr('aria-labelledby'));
        self::assertSame('Coup de cœur des membres', trim($hero->filter('.kicker')->text()));
        self::assertSame('Grand large', trim($hero->filter('#hero-title')->text()));
        self::assertStringContainsString('alice', $hero->filter('.byline')->text());
        self::assertStringContainsString('2 j\'aime', $hero->filter('.byline')->text());
        self::assertSame("/photos/{$photo->getId()}", $hero->filter('a.btn-primary')->attr('href'));
        $media = $hero->filter('a.hero-media');
        self::assertSame('hero', $media->attr('data-lightbox'), 'The picture opens the lightbox…');
        self::assertSame((string) $photo->getId(), $media->attr('data-photo-id'), '…whose heart needs the photo id.');
        $image = $hero->filter('img.hero-image');
        self::assertSame("/photos/{$photo->getId()}/hero?v={$photo->getFileVersion()}", $image->attr('src'));
        self::assertSame('Grand large', $image->attr('alt'));
        self::assertSame(['1600', '900'], [$image->attr('width'), $image->attr('height')]);
        self::assertSame('high', $image->attr('fetchpriority'));
    }

    public function testNothingLikedMeansNoHeroWhateverTheLibraryHolds(): void
    {
        $alice = $this->createUser('alice');
        $this->photo('Recent but nobody liked it', $alice);
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('section.hero'));
        self::assertSelectorTextContains('h1', 'Activités récentes');
    }

    public function testHeroLinksToTheTopicWhereThePhotoAppears(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Sortie photo');
        $photo = $this->photo('Grand large', $alice);
        $this->em()->persist((new PhotoTopic())->setPhoto($photo)->setTopic($topic));
        $this->like($photo, $alice);
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/');

        $link = $crawler->filter('section.hero .byline a')->reduce(static fn ($node) => 'Sortie photo' === trim($node->text()));
        self::assertCount(1, $link);
        self::assertSame("/forum/general/{$topic->getId()}", $link->attr('href'));
    }

    public function testHeroPageIsNeverStoredByACache(): void
    {
        $alice = $this->createUser('alice');
        $this->like($this->photo('Grand large', $alice), $alice);
        $this->loginAs($alice);

        $this->client->request('GET', '/');

        $cache = $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
    }

    public function testAnonymousVisitorsAreStillRedirectedToTheLogin(): void
    {
        $this->like($this->photo('Grand large'), $this->createUser('alice'));

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/login');
    }
}
