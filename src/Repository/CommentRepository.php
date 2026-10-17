<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    public function countInTopic(Topic $topic): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * One page of a topic's messages. $order is "asc" (oldest first) or "desc" (newest first).
     *
     * @return list<Comment>
     */
    public function findPage(Topic $topic, string $order, int $offset, int $limit): array
    {
        $direction = 'desc' === $order ? 'DESC' : 'ASC';

        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.author', 'a')
            ->where('c.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('c.createdAt', $direction)
            ->addOrderBy('c.id', $direction)
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Zero-based position of a message inside its topic, in the given display order. */
    public function positionOf(Comment $comment, string $order): int
    {
        $before = 'desc' === $order ? '>' : '<';

        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.topic = :topic')
            ->andWhere("c.createdAt $before :at OR (c.createdAt = :at AND c.id $before :id)")
            ->setParameter('topic', $comment->getTopic())
            ->setParameter('at', $comment->getCreatedAt())
            ->setParameter('id', $comment->getId())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countSince(Topic $topic, \DateTimeInterface $since): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.topic = :topic')
            ->andWhere('c.createdAt >= :since')
            ->setParameter('topic', $topic)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The newest $limit messages posted since a date, returned in the requested display order.
     *
     * @return list<Comment>
     */
    public function findLatestSince(Topic $topic, \DateTimeInterface $since, string $order, int $limit): array
    {
        $comments = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.author', 'a')
            ->where('c.topic = :topic')
            ->andWhere('c.createdAt >= :since')
            ->setParameter('topic', $topic)
            ->setParameter('since', $since)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return 'desc' === $order ? $comments : array_reverse($comments);
    }

    /**
     * The newest messages of a topic, newest first.
     *
     * @return list<Comment>
     */
    public function findLatest(Topic $topic, int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->leftJoin('c.author', 'a')
            ->where('c.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('c.createdAt', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Messages containing a term (case/accent-insensitive thanks to the column collation), newest first.
     *
     * @return list<Comment>
     */
    public function search(string $term, int $limit = 30): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('a', 't', 'f')
            ->leftJoin('c.author', 'a')
            ->innerJoin('c.topic', 't')
            ->innerJoin('t.forum', 'f')
            // Stored HTML escapes quotes and ampersands, and escapers disagree on the spelling of the
            // apostrophe (&#39; in the legacy data, &#039; from PHP): look for every spelling.
            ->where('c.content LIKE :q OR c.content LIKE :qe OR c.content LIKE :qe2')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->setParameter('qe', '%'.addcslashes(htmlspecialchars($term, ENT_QUOTES), '%_\\').'%')
            ->setParameter('qe2', '%'.addcslashes(str_replace('&#039;', '&#39;', htmlspecialchars($term, ENT_QUOTES)), '%_\\').'%')
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
