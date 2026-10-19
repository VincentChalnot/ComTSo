<?php

namespace App\Repository;

use App\Entity\Quote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /** @return list<Quote> one page of the quotes whose text or original author contains the term */
    public function search(string $term, int $limit, int $offset = 0): array
    {
        return $this->searchBuilder($term)
            ->orderBy('q.id')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** How many quotes {@see self::search()} finds, whatever the page. */
    public function countSearch(string $term): int
    {
        return (int) $this->searchBuilder($term)->select('COUNT(q.id)')->getQuery()->getSingleScalarResult();
    }

    private function searchBuilder(string $term): QueryBuilder
    {
        return $this->createQueryBuilder('q')
            ->where('q.content LIKE :t OR q.originalAuthor LIKE :t')
            ->setParameter('t', '%'.addcslashes($term, '%_\\').'%');
    }
}
