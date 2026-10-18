<?php

namespace App\Photo;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Repository\PhotoLikeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;

/**
 * Chooses the photo of the home page hero: a RANDOM one (fresh pick on every call) among the {@see self::POOL} photos
 * most recently liked by at least one member (one entry per photo, ordered by its latest like; photos used as an avatar
 * are ignored). No liked photo, no hero. The random source is injected so tests can make the pick deterministic.
 */
final class FeaturedPhotoProvider
{
    public const POOL = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoLikeRepository $likes,
        private readonly Randomizer $random,
    ) {
    }

    public function select(): ?FeaturedPhoto
    {
        $ids = $this->likes->recentlyLikedPhotoIds(self::POOL);
        if (!$ids) {
            return null;
        }

        $photo = $this->em->createQuery('SELECT p, a FROM '.Photo::class.' p LEFT JOIN p.author a WHERE p.id = :id')
            ->setParameter('id', $ids[$this->random->getInt(0, \count($ids) - 1)])
            ->getOneOrNullResult();

        return $photo instanceof Photo ? $this->withContext($photo) : null;
    }

    /** The newest place the photo appears (topic attachment or message attachment): at most two small queries. */
    private function withContext(Photo $photo): FeaturedPhoto
    {
        $count = $this->likes->countFor($photo);
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
            return new FeaturedPhoto($photo, $count, $commentLink->getComment()->getTopic(), $commentLink->getComment());
        }

        return new FeaturedPhoto($photo, $count, $topicLink instanceof PhotoTopic ? $topicLink->getTopic() : null);
    }
}
