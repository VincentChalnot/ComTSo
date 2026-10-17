<?php

namespace App\Repository;

use App\Entity\Photo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /** @return list<Photo> */
    public function search(string $term, int $limit = 30): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.title LIKE :q OR p.content LIKE :q OR p.originalFilename LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
