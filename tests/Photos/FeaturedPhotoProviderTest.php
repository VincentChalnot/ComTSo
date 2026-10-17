<?php

namespace App\Tests\Photos;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Photo\FeaturedPhoto;
use App\Photo\FeaturedPhotoProvider;
use App\Photo\FeaturedRule;
use App\Tests\Support\AppWebTestCase;

class FeaturedPhotoProviderTest extends AppWebTestCase
{
    /** Wednesday of ISO week 2026-W42. */
    private const NOW = '2026-10-14 12:00:00';

    private int $sequence = 0;

    private function provider(): FeaturedPhotoProvider
    {
        return static::getContainer()->get(FeaturedPhotoProvider::class);
    }

    private function now(string $modifier = ''): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::NOW))->modify($modifier ?: 'now');
    }

    private function photo(\DateTimeInterface $createdAt, ?int $width = 1600, ?int $height = 900, ?string $title = null): Photo
    {
        $name = 'featured-'.++$this->sequence.'.jpeg';
        $photo = (new Photo())
            ->setFilename($name)
            ->setOriginalFilename($name)
            ->setFileSize(1000)
            ->setFileType('image/jpeg')
            ->setWidth($width)
            ->setHeight($height)
            ->setTitle($title)
            ->setCreatedAt($createdAt);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    private function ago(string $interval): \DateTime
    {
        return \DateTime::createFromImmutable($this->now('-'.$interval));
    }

    public function testNoPhotoMeansNoHero(): void
    {
        self::assertNull($this->provider()->select($this->now()));
    }

    public function testPhotoUploadedThisWeekIsThePhotoOfTheWeek(): void
    {
        $recent = $this->photo($this->ago('2 days'));
        $this->photo($this->ago('20 days'));
        $this->photo($this->ago('200 days'));

        $featured = $this->provider()->select($this->now());

        self::assertInstanceOf(FeaturedPhoto::class, $featured);
        self::assertSame($recent->getId(), $featured->photo->getId());
        self::assertSame(FeaturedRule::Week, $featured->rule);
        self::assertSame('Photo de la semaine', $featured->kicker());
    }

    public function testFallsBackToThePhotoOfTheMonth(): void
    {
        $month = $this->photo($this->ago('20 days'));
        $this->photo($this->ago('200 days'));

        $featured = $this->provider()->select($this->now());

        self::assertSame($month->getId(), $featured->photo->getId());
        self::assertSame(FeaturedRule::Month, $featured->rule);
        self::assertSame('Photo du mois', $featured->kicker());
    }

    public function testFallsBackToTheLibrary(): void
    {
        $old = $this->photo($this->ago('200 days'));

        $featured = $this->provider()->select($this->now());

        self::assertSame($old->getId(), $featured->photo->getId());
        self::assertSame(FeaturedRule::Library, $featured->rule);
        self::assertSame('Photo à redécouvrir', $featured->kicker());
    }

    public function testPhotosFromTheFutureOfTheGivenInstantAreIgnored(): void
    {
        $this->photo(\DateTime::createFromImmutable($this->now('+2 days')));

        self::assertSame(FeaturedRule::Library, $this->provider()->select($this->now())?->rule);
    }

    public function testAvatarsAreNeverFeatured(): void
    {
        $alice = $this->createUser('alice');
        $alice->setAvatar($this->photo($this->ago('1 day'), 1600, 900));
        $this->em()->flush();

        self::assertNull($this->provider()->select($this->now()));

        $other = $this->photo($this->ago('1 day'), 600, 800);
        self::assertSame($other->getId(), $this->provider()->select($this->now())->photo->getId());
    }

    public function testThePickIsStableForTheWholeIsoWeekAndAcrossCalls(): void
    {
        // Uploaded Monday morning of W42: inside the 7-day window at every instant of the week.
        $created = new \DateTime('2026-10-12 08:00:00');
        for ($i = 0; $i < 6; ++$i) {
            $this->photo($created);
        }

        $instants = ['2026-10-12 09:00:00', '2026-10-13 23:59:59', '2026-10-14 12:00:00', '2026-10-17 06:30:00', '2026-10-18 07:59:00'];
        $picks = [];
        foreach ($instants as $instant) {
            $picks[] = $this->provider()->select(new \DateTimeImmutable($instant))->photo->getId();
            $picks[] = $this->provider()->select(new \DateTimeImmutable($instant))->photo->getId();
        }

        self::assertCount(1, array_unique($picks));
    }

    public function testThePickChangesFromWeekToWeek(): void
    {
        $created = new \DateTime('2020-01-01');
        for ($i = 0; $i < 8; ++$i) {
            $this->photo($created);
        }

        $picks = [];
        for ($week = 0; $week < 10; ++$week) {
            $featured = $this->provider()->select($this->now('+'.$week.' weeks'));
            self::assertSame(FeaturedRule::Library, $featured->rule);
            $picks[$featured->photo->getId()] = true;
        }

        self::assertGreaterThanOrEqual(2, \count($picks), 'Different weeks do not always feature the same photo.');
    }

    public function testAnUnrelatedNewUploadDoesNotReshuffleTheChoice(): void
    {
        for ($i = 0; $i < 6; ++$i) {
            $this->photo($this->ago('1 day'));
        }
        $before = $this->provider()->select($this->now())->photo->getId();

        // The newcomer is a portrait: it can never outrank the landscape candidates.
        $this->photo($this->ago('1 hour'), 800, 1200);

        self::assertSame($before, $this->provider()->select($this->now())->photo->getId());
    }

    public function testHighResolutionLandscapeIsPreferredInTheWindow(): void
    {
        for ($week = 0; $week < 6; ++$week) {
            $now = $this->now('+'.$week.' weeks');
            $created = \DateTime::createFromImmutable($now->modify('-1 day'));
            $this->photo($created, 900, 1400); // portrait
            $this->photo($created, 800, 600); // landscape but too small
            $this->photo($created, null, null); // legacy import, unknown size
            $this->photo($created, FeaturedPhotoProvider::MIN_LANDSCAPE_WIDTH - 1, 400); // just under the bar
            $best = $this->photo($created, FeaturedPhotoProvider::MIN_LANDSCAPE_WIDTH, 800);

            self::assertSame($best->getId(), $this->provider()->select($now)->photo->getId(), "Week +$week");
        }
    }

    public function testAnyLandscapeBeatsPortraitAndUnknownSizeWhenNothingIsHighResolution(): void
    {
        for ($week = 0; $week < 6; ++$week) {
            $now = $this->now('+'.$week.' weeks');
            $created = \DateTime::createFromImmutable($now->modify('-1 day'));
            $this->photo($created, 900, 1400);
            $this->photo($created, null, null);
            $this->photo($created, 500, 500); // square is not landscape
            $landscape = $this->photo($created, 640, 480);

            self::assertSame($landscape->getId(), $this->provider()->select($now)->photo->getId(), "Week +$week");
        }
    }

    public function testPhotosWithoutDimensionsStillQualifyWhenNothingElseDoes(): void
    {
        $unknown = $this->photo($this->ago('1 day'), null, null);

        $featured = $this->provider()->select($this->now());

        self::assertSame($unknown->getId(), $featured->photo->getId());
        self::assertSame(FeaturedRule::Week, $featured->rule);
    }

    public function testLibraryAlsoPrefersLandscapeAndNeverTreatsUnknownSizeAsLandscape(): void
    {
        $old = new \DateTime('2020-01-01');
        for ($i = 0; $i < 5; ++$i) {
            $this->photo($old, null, null);
            $this->photo($old, 700, 1000);
        }
        $landscape = $this->photo($old, 800, 600);

        for ($week = 0; $week < 6; ++$week) {
            self::assertSame($landscape->getId(), $this->provider()->select($this->now('+'.$week.' weeks'))->photo->getId(), "Week +$week");
        }
    }

    public function testTitleAndLocationComeFromTheNewestAppearance(): void
    {
        $alice = $this->createUser('alice');
        $forum = $this->createForum();
        $album = $this->createTopic($forum, $alice, 'Album de vacances');
        $discussion = $this->createTopic($forum, $alice, 'Sortie du dimanche');
        $comment = $this->createComment($discussion, $alice);
        $photo = $this->photo($this->ago('1 day'));

        self::assertNull($this->provider()->select($this->now())->topic, 'Not attached anywhere yet.');
        self::assertNull($this->provider()->select($this->now())->title());

        $this->em()->persist((new PhotoTopic())->setPhoto($photo)->setTopic($album)->setCreatedAt(new \DateTime('2026-10-10'))->setUpdatedAt(new \DateTime('2026-10-10')));
        $this->em()->flush();
        $featured = $this->provider()->select($this->now());
        self::assertSame($album->getId(), $featured->topic->getId());
        self::assertNull($featured->comment);
        self::assertSame('Album de vacances', $featured->title());

        $this->em()->persist((new CommentPhoto())->setPhoto($photo)->setComment($comment)->setCreatedAt(new \DateTime('2026-10-13')));
        $this->em()->flush();
        $featured = $this->provider()->select($this->now());
        self::assertSame($discussion->getId(), $featured->topic->getId());
        self::assertSame($comment->getId(), $featured->comment->getId());

        $photo->setTitle('Coucher de soleil');
        $this->em()->flush();
        self::assertSame('Coucher de soleil', $this->provider()->select($this->now())->title(), 'The photo title wins over the topic title.');
    }
}
