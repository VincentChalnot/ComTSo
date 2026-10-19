<?php

namespace App\Repository;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    /** @return list<Photo> one page of the photos whose title, description or file name contains the term, newest first */
    public function search(string $term, int $limit, int $offset = 0): array
    {
        return $this->searchBuilder($term)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** How many photos {@see self::search()} finds, whatever the page. */
    public function countSearch(string $term): int
    {
        return (int) $this->searchBuilder($term)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
    }

    private function searchBuilder(string $term): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->where('p.title LIKE :q OR p.content LIKE :q OR p.originalFilename LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%');
    }

    /** Whether a topic, a message or a member's avatar still uses the photo (so its file must be kept). */
    public function isReferenced(Photo $photo): bool
    {
        $em = $this->getEntityManager();
        foreach ([PhotoTopic::class => 'photo', CommentPhoto::class => 'photo', User::class => 'avatar'] as $class => $field) {
            $count = (int) $em->createQuery("SELECT COUNT(x.id) FROM $class x WHERE x.$field = :photo")->setParameter('photo', $photo)->getSingleScalarResult();
            if ($count > 0) {
                return true;
            }
        }

        return false;
    }
}
