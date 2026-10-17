<?php

namespace App\Repository;

use App\Entity\Forum;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class TopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Topic::class);
    }

    /** @return list<Topic> */
    public function findFavoritesOf(User $user, int $limit = 12): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('f')
            ->innerJoin('t.forum', 'f')
            ->innerJoin('t.starredBy', 'u', 'WITH', 'u.id = :user')
            ->setParameter('user', $user->getId())
            ->orderBy('t.lastActivityAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countInForum(Forum $forum): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.forum = :forum')
            ->setParameter('forum', $forum)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Topics of a forum, most recently active first.
     *
     * @return list<Topic>
     */
    public function findPageInForum(Forum $forum, int $offset, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('a')
            ->leftJoin('t.author', 'a')
            ->where('t.forum = :forum')
            ->setParameter('forum', $forum)
            ->orderBy('t.lastActivityAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countActiveSince(\DateTimeInterface $since): int
    {
        return (int) $this->activeSince($since)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
    }

    /**
     * Topics that had activity (new message, new photo, creation) since a date, most recently active first.
     *
     * @return list<Topic>
     */
    public function findActiveSince(\DateTimeInterface $since, int $offset, int $limit): array
    {
        return $this->activeSince($since)
            ->addSelect('f', 'a')
            ->innerJoin('t.forum', 'f')
            ->leftJoin('t.author', 'a')
            ->orderBy('t.lastActivityAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    private function activeSince(\DateTimeInterface $since): QueryBuilder
    {
        return $this->createQueryBuilder('t')
            ->where('t.lastActivityAt >= :since')
            ->setParameter('since', $since);
    }

    /**
     * Case-insensitive search in topic titles and descriptions.
     *
     * @return list<Topic>
     */
    public function search(string $term, int $limit = 30): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('f')
            ->innerJoin('t.forum', 'f')
            ->where('t.title LIKE :q OR t.content LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('t.lastActivityAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
