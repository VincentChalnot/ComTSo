<?php

namespace App\Repository;

use App\Entity\Quote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class QuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quote::class);
    }

    public function findRandom(): ?Quote
    {
        $count = (int) $this->createQueryBuilder('q')->select('COUNT(q.id)')->getQuery()->getSingleScalarResult();
        if (0 === $count) {
            return null;
        }

        return $this->createQueryBuilder('q')
            ->setFirstResult(random_int(0, $count - 1))
            ->setMaxResults(1)
            ->orderBy('q.id')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Quote> */
    public function search(string $term, int $limit = 30): array
    {
        return $this->createQueryBuilder('q')
            ->where('q.content LIKE :t OR q.originalAuthor LIKE :t')
            ->setParameter('t', '%'.addcslashes($term, '%_\\').'%')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
