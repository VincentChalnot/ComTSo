<?php

namespace App\Repository;

use App\Entity\PhotoTopic;
use App\Entity\Topic;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PhotoTopicRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PhotoTopic::class);
    }

    /**
     * Photos attached to a topic, in the order chosen by the members.
     *
     * @return list<PhotoTopic>
     */
    public function findForTopic(Topic $topic): array
    {
        return $this->createQueryBuilder('pt')
            ->addSelect('p')
            ->innerJoin('pt.photo', 'p')
            ->where('pt.topic = :topic')
            ->setParameter('topic', $topic)
            ->orderBy('pt.order', 'ASC')
            ->addOrderBy('pt.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Photos attached to a topic since a date.
     *
     * @return list<PhotoTopic>
     */
    public function findSince(Topic $topic, \DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('pt')
            ->addSelect('p')
            ->innerJoin('pt.photo', 'p')
            ->where('pt.topic = :topic')
            ->andWhere('pt.createdAt >= :since')
            ->setParameter('topic', $topic)
            ->setParameter('since', $since)
            ->orderBy('pt.order', 'ASC')
            ->addOrderBy('pt.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function nextOrder(Topic $topic): int
    {
        $max = $this->createQueryBuilder('pt')
            ->select('MAX(pt.order)')
            ->where('pt.topic = :topic')
            ->setParameter('topic', $topic)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
