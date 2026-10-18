<?php

namespace App\Repository;

use App\Entity\Photo;
use App\Entity\PhotoLike;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PhotoLikeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoLike::class);
    }

    /**
     * Likes the photo when the member did not, takes the like back otherwise. Plain SQL on purpose: atomic, and two
     * concurrent likes of the same member cannot fail on the unique key (INSERT IGNORE).
     *
     * @return bool true when the photo is liked afterwards
     */
    public function toggle(Photo $photo, User $user): bool
    {
        $connection = $this->getEntityManager()->getConnection();
        $key = ['photo_id' => $photo->getId(), 'user_id' => $user->getId()];
        if ($connection->delete('ctso_photo_like', $key) > 0) {
            return false;
        }
        $connection->executeStatement('INSERT IGNORE INTO ctso_photo_like (photo_id, user_id, created_at) VALUES (:photo_id, :user_id, :at)', $key + ['at' => date('Y-m-d H:i:s')]);

        return true;
    }

    public function countFor(Photo $photo): int
    {
        return (int) $this->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.photo = :photo')->setParameter('photo', $photo)->getQuery()->getSingleScalarResult();
    }

    public function isLikedBy(Photo $photo, User $user): bool
    {
        return null !== $this->findOneBy(['photo' => $photo, 'user' => $user]);
    }

    /**
     * Members who liked a photo, most recent like first.
     *
     * @return list<User>
     */
    public function likers(Photo $photo, int $limit = 50): array
    {
        return array_map(
            static fn (PhotoLike $like): User => $like->getUser(),
            $this->createQueryBuilder('l')->addSelect('u')->join('l.user', 'u')->where('l.photo = :photo')->setParameter('photo', $photo)
                ->orderBy('l.createdAt', 'DESC')->addOrderBy('l.id', 'DESC')->setMaxResults($limit)->getQuery()->getResult(),
        );
    }

    /**
     * The photos most recently liked (one entry per photo, whatever the number of likes), newest like first.
     * Photos used as a member's avatar never count.
     *
     * @return list<int> photo ids
     */
    public function recentlyLikedPhotoIds(int $limit): array
    {
        $rows = $this->getEntityManager()->createQuery(
            'SELECT p.id AS id, MAX(l.createdAt) AS latest FROM '.PhotoLike::class.' l JOIN l.photo p
             WHERE NOT EXISTS (SELECT u.id FROM '.User::class.' u WHERE u.avatar = p)
             GROUP BY p.id ORDER BY latest DESC, p.id DESC'
        )->setMaxResults($limit)->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }
}
