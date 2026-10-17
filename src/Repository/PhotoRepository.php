<?php

namespace App\Repository;

use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\User;
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
