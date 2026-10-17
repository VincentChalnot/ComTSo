<?php

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CommentPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommentPhoto::class);
    }

    /**
     * Initialises `Comment::getPhotos()` of every given message with ONE query (fetch join into the identity map),
     * so rendering a page of messages does not issue a query per message.
     *
     * @param iterable<Comment> $comments
     */
    public function preload(iterable $comments): void
    {
        $ids = [];
        foreach ($comments as $comment) {
            if ($comment->getId()) {
                $ids[$comment->getId()] = true;
            }
        }
        if (!$ids) {
            return;
        }

        $this->getEntityManager()->createQueryBuilder()
            ->select('c', 'cp', 'p')
            ->from(Comment::class, 'c')
            ->leftJoin('c.photos', 'cp')
            ->leftJoin('cp.photo', 'p')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', array_keys($ids))
            ->getQuery()
            ->getResult();
    }

    /**
     * Messages that show a photo, with their topic and forum (the "used in" list of the photo page).
     *
     * @return list<CommentPhoto>
     */
    public function findUsages(Photo $photo): array
    {
        return $this->createQueryBuilder('cp')
            ->addSelect('c', 't', 'f', 'a')
            ->innerJoin('cp.comment', 'c')
            ->innerJoin('c.topic', 't')
            ->innerJoin('t.forum', 'f')
            ->leftJoin('c.author', 'a')
            ->where('cp.photo = :photo')
            ->setParameter('photo', $photo)
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function nextOrder(Comment $comment): int
    {
        $max = $this->createQueryBuilder('cp')
            ->select('MAX(cp.order)')
            ->where('cp.comment = :comment')
            ->setParameter('comment', $comment)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max + 1;
    }
}
