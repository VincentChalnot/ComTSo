<?php

namespace App\Photo;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chooses the photo of the home page hero.
 *
 * - Candidates: photos uploaded in the last {@see self::WEEK_DAYS} days ({@see FeaturedRule::Week}); else in the last
 *   {@see self::MONTH_DAYS} days ({@see FeaturedRule::Month}); else the whole library ({@see FeaturedRule::Library}).
 *   Photos used as a member's avatar are never candidates.
 * - Within the candidates of the first non-empty period, high-resolution landscape photos are preferred, then any landscape
 *   photo, then any photo. Photos without known dimensions are never landscape.
 * - The pick is deterministic: it only depends on the ISO year+week of `$now`, so every member sees the same photo for the
 *   whole week. It uses highest-random-weight hashing (each photo gets a stable pseudo-random weight per week, the heaviest
 *   wins) so that a new upload or a photo leaving the period does not reshuffle the choice, unless it was the winner or the
 *   newcomer outweighs it.
 */
final class FeaturedPhotoProvider
{
    public const WEEK_DAYS = 7;
    public const MONTH_DAYS = 30;
    /** Minimum width (px) of a landscape photo to be considered good enough for a hero. */
    public const MIN_LANDSCAPE_WIDTH = 1200;

    private const RANK_ANY = 0;
    private const RANK_LANDSCAPE = 1;
    private const RANK_HERO = 2;

    private const NOT_AN_AVATAR = 'NOT EXISTS (SELECT u.id FROM App\Entity\User u WHERE u.avatar = p)';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function select(?\DateTimeImmutable $now = null): ?FeaturedPhoto
    {
        $now ??= new \DateTimeImmutable();
        $seed = $now->format('o-\WW');

        [$id, $rule] = $this->selectFromRecent($now, $seed) ?? [$this->selectFromLibrary($seed), FeaturedRule::Library];
        if (null === $id) {
            return null;
        }

        $photo = $this->em->createQuery('SELECT p, a FROM '.Photo::class.' p LEFT JOIN p.author a WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getOneOrNullResult();
        if (!$photo instanceof Photo) {
            return null;
        }

        return $this->withContext($photo, $rule);
    }

    /** One query for the last 30 days (a small set), the 7-day subset is resolved in PHP. @return array{int, FeaturedRule}|null */
    private function selectFromRecent(\DateTimeImmutable $now, string $seed): ?array
    {
        $weekStart = $now->modify('-'.self::WEEK_DAYS.' days');
        $rows = $this->em->createQuery(
            'SELECT p.id, p.width, p.height, p.createdAt FROM '.Photo::class.' p
             WHERE p.createdAt >= :from AND p.createdAt <= :now AND '.self::NOT_AN_AVATAR.'
             ORDER BY p.id'
        )
            ->setParameter('from', $now->modify('-'.self::MONTH_DAYS.' days'))
            ->setParameter('now', $now)
            ->getResult();

        $week = [];
        foreach ($rows as $row) {
            $createdAt = $row['createdAt'] instanceof \DateTimeInterface ? $row['createdAt'] : new \DateTimeImmutable($row['createdAt']);
            if ($createdAt >= $weekStart) {
                $week[] = $row;
            }
        }

        foreach ([[$week, FeaturedRule::Week], [$rows, FeaturedRule::Month]] as [$pool, $rule]) {
            if ($id = $this->pickPreferred($pool, $seed)) {
                return [$id, $rule];
            }
        }

        return null;
    }

    /** Scalar ids only (never hydrates the library): the best non-empty preference tier wins. */
    private function selectFromLibrary(string $seed): ?int
    {
        foreach (['p.width > p.height AND p.width >= :min', 'p.width > p.height', 'p.id > 0'] as $condition) {
            $query = $this->em->createQuery('SELECT p.id FROM '.Photo::class.' p WHERE '.$condition.' AND '.self::NOT_AN_AVATAR.' ORDER BY p.id');
            if (str_contains($condition, ':min')) {
                $query->setParameter('min', self::MIN_LANDSCAPE_WIDTH);
            }
            if ($ids = array_map('intval', $query->getSingleColumnResult())) {
                return $this->pickWeightiest($ids, $seed);
            }
        }

        return null;
    }

    /** @param list<array{id: int, width: int|null, height: int|null}> $rows */
    private function pickPreferred(array $rows, string $seed): ?int
    {
        $byRank = [];
        foreach ($rows as $row) {
            $byRank[self::rank($row['width'], $row['height'])][] = (int) $row['id'];
        }

        foreach ([self::RANK_HERO, self::RANK_LANDSCAPE, self::RANK_ANY] as $rank) {
            if (!empty($byRank[$rank])) {
                return $this->pickWeightiest($byRank[$rank], $seed);
            }
        }

        return null;
    }

    private static function rank(?int $width, ?int $height): int
    {
        if (!$width || !$height || $width <= $height) {
            return self::RANK_ANY;
        }

        return $width >= self::MIN_LANDSCAPE_WIDTH ? self::RANK_HERO : self::RANK_LANDSCAPE;
    }

    /**
     * @param non-empty-list<int> $ids ascending: on equal weights the lowest id wins
     */
    private function pickWeightiest(array $ids, string $seed): int
    {
        $best = $ids[0];
        $bestWeight = -1;
        foreach ($ids as $id) {
            $weight = crc32($seed.'#'.$id);
            if ($weight > $bestWeight) {
                $best = $id;
                $bestWeight = $weight;
            }
        }

        return $best;
    }

    /** The newest place the photo appears (topic attachment or message attachment): at most two small queries. */
    private function withContext(Photo $photo, FeaturedRule $rule): FeaturedPhoto
    {
        $topicLink = $this->em->createQuery('SELECT pt, t FROM '.PhotoTopic::class.' pt JOIN pt.topic t WHERE pt.photo = :photo ORDER BY pt.createdAt DESC, pt.id DESC')
            ->setParameter('photo', $photo)
            ->setMaxResults(1)
            ->getOneOrNullResult();
        $commentLink = $this->em->createQuery('SELECT cp, c, t FROM '.CommentPhoto::class.' cp JOIN cp.comment c JOIN c.topic t WHERE cp.photo = :photo ORDER BY cp.createdAt DESC, cp.id DESC')
            ->setParameter('photo', $photo)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if ($commentLink instanceof CommentPhoto
            && (!$topicLink instanceof PhotoTopic || ($commentLink->getCreatedAt()?->getTimestamp() ?? 0) >= ($topicLink->getCreatedAt()?->getTimestamp() ?? 0))) {
            return new FeaturedPhoto($photo, $rule, $commentLink->getComment()->getTopic(), $commentLink->getComment());
        }

        return new FeaturedPhoto($photo, $rule, $topicLink instanceof PhotoTopic ? $topicLink->getTopic() : null);
    }
}
