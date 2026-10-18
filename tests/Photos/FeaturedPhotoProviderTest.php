<?php

namespace App\Tests\Photos;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoLike;
use App\Entity\PhotoTopic;
use App\Entity\User;
use App\Photo\FeaturedPhoto;
use App\Photo\FeaturedPhotoProvider;
use App\Repository\PhotoLikeRepository;
use App\Tests\Support\AppWebTestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/** The hero shows a random one of the 10 photos most recently liked by the members. */
final class FeaturedPhotoProviderTest extends AppWebTestCase
{
    private int $counter = 0;

    private function provider(string $seed = 'comtso'): FeaturedPhotoProvider
    {
        return new FeaturedPhotoProvider($this->em(), static::getContainer()->get(PhotoLikeRepository::class), new Randomizer(new Xoshiro256StarStar(hash('sha256', $seed, true))));
    }

    private function photo(?string $title = null): Photo
    {
        $name = 'liked-'.++$this->counter.'.webp';
        $photo = (new Photo())->setFilename($name)->setOriginalFilename($name)->setFileSize(1000)->setFileType('image/webp')->setWidth(1600)->setHeight(900)->setTitle($title);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    /** @param list<User> $likers */
    private function like(Photo $photo, array $likers, string $at): void
    {
        foreach ($likers as $user) {
            $this->em()->persist(new PhotoLike($photo, $user, new \DateTime($at)));
        }
        $this->em()->flush();
    }

    /** @return array<int, int> photo id => times picked */
    private function picks(FeaturedPhotoProvider $provider, int $draws): array
    {
        $picked = [];
        for ($i = 0; $i < $draws; ++$i) {
            $id = $provider->select()?->photo->getId();
            $picked[$id] = ($picked[$id] ?? 0) + 1;
        }

        return $picked;
    }

    public function testNoLikedPhotoMeansNoHero(): void
    {
        $this->photo('Never liked');

        self::assertNull($this->provider()->select());
    }

    public function testPicksAmongTheLastTenDistinctLikedPhotosAndNeverTheEleventhOldest(): void
    {
        $alice = $this->createUser('alice');
        $photos = [];
        for ($i = 1; $i <= 11; ++$i) {
            $photos[$i] = $this->photo("Photo $i");
            $this->like($photos[$i], [$alice], sprintf('2026-01-%02d 10:00:00', $i)); // 1 is the oldest like
        }

        $picked = $this->picks($this->provider(), 400);

        self::assertArrayNotHasKey($photos[1]->getId(), $picked, 'The 11th most recent liked photo is out of the pool.');
        self::assertCount(10, $picked, 'Every photo of the pool comes up sometime: the pick is random on each call.');
    }

    public function testAPhotoLikedByThreeMembersCountsOnce(): void
    {
        $members = [$this->createUser('alice'), $this->createUser('bob'), $this->createUser('carol')];
        $popular = $this->photo('Popular');
        $this->like($popular, $members, '2026-02-01 10:00:00');
        for ($i = 1; $i <= 9; ++$i) {
            $this->like($this->photo("Single $i"), [$members[0]], sprintf('2026-01-%02d 10:00:00', $i));
        }
        // 10 distinct photos exist: with 3 likes the popular one would push another one out if likes were counted.
        $picked = $this->picks($this->provider(), 400);

        self::assertCount(10, $picked);
        self::assertSame(3, $this->providerPick($popular)?->likeCount, 'It is one photo of the pool, shown with its 3 likes.');
    }

    private function providerPick(Photo $wanted): ?FeaturedPhoto
    {
        $provider = $this->provider('find');
        for ($i = 0; $i < 200; ++$i) {
            $featured = $provider->select();
            if ($featured?->photo->getId() === $wanted->getId()) {
                return $featured;
            }
        }

        return null;
    }

    public function testRecentLikeRefreshesAnOlderPhotoIntoThePool(): void
    {
        $alice = $this->createUser('alice');
        $old = $this->photo('Old favourite');
        $this->like($old, [$alice], '2025-01-01 10:00:00');
        for ($i = 1; $i <= 10; ++$i) {
            $this->like($this->photo("Newer $i"), [$alice], sprintf('2026-01-%02d 10:00:00', $i));
        }
        self::assertArrayNotHasKey($old->getId(), $this->picks($this->provider(), 300));

        $this->like($old, [$this->createUser('bob')], '2026-03-01 10:00:00');

        self::assertArrayHasKey($old->getId(), $this->picks($this->provider(), 300), 'A fresh like puts the photo back in the pool.');
    }

    public function testAvatarsAreNeverPicked(): void
    {
        $alice = $this->createUser('alice');
        $avatar = $this->photo('Avatar');
        $alice->setAvatar($avatar);
        $this->em()->flush();
        $this->like($avatar, [$alice], '2026-03-01 10:00:00');

        self::assertNull($this->provider()->select());

        $real = $this->photo('Real');
        $this->like($real, [$alice], '2026-03-02 10:00:00');
        self::assertSame([$real->getId()], array_keys($this->picks($this->provider(), 30)));
    }

    public function testTheRandomSourceIsInjected(): void
    {
        $alice = $this->createUser('alice');
        for ($i = 1; $i <= 10; ++$i) {
            $this->like($this->photo("Photo $i"), [$alice], sprintf('2026-01-%02d 10:00:00', $i));
        }

        $sequence = static fn (FeaturedPhotoProvider $provider): array => array_map(static fn () => $provider->select()->photo->getId(), range(1, 20));

        self::assertSame($sequence($this->provider('seed A')), $sequence($this->provider('seed A')));
        self::assertNotSame($sequence($this->provider('seed A')), $sequence($this->provider('seed B')));
        $one = $this->provider('seed A');
        self::assertGreaterThan(1, \count(array_unique($sequence($one))), 'Consecutive calls of one provider do not repeat the same photo.');
    }

    public function testTitleAndTopicComeFromTheNewestAppearance(): void
    {
        $alice = $this->createUser('alice');
        $forum = $this->createForum();
        $older = $this->createTopic($forum, $alice, 'Older topic');
        $newer = $this->createTopic($forum, $alice, 'Newer topic');
        $comment = $this->createComment($newer, $alice);
        $photo = $this->photo();
        $this->em()->persist((new PhotoTopic())->setTopic($older)->setPhoto($photo)->setCreatedAt(new \DateTime('2026-01-01')));
        $this->em()->persist((new CommentPhoto())->setComment($comment)->setPhoto($photo)->setCreatedAt(new \DateTime('2026-02-01')));
        $this->like($photo, [$alice], '2026-03-01 10:00:00');
        $this->em()->flush();

        $featured = $this->provider()->select();

        self::assertSame('Newer topic', $featured->topic->getTitle());
        self::assertSame($comment->getId(), $featured->comment->getId());
        self::assertSame('Newer topic', $featured->title(), 'No photo title: the topic title is used.');
        self::assertSame(1, $featured->likeCount);
    }

    public function testImageSizeIsTheHeroVariantSize(): void
    {
        $wide = new FeaturedPhoto((new Photo())->setWidth(3200)->setHeight(1800), 1);
        $small = new FeaturedPhoto((new Photo())->setWidth(640)->setHeight(480), 1);

        self::assertSame(['width' => 1600, 'height' => 900], $wide->imageSize());
        self::assertSame(['width' => 640, 'height' => 480], $small->imageSize(), 'Never upscaled.');
        self::assertNull((new FeaturedPhoto(new Photo(), 1))->imageSize());
    }
}
