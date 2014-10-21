<?php

namespace ComTSo\ForumBundle\Entity;

use ComTSo\UserBundle\Entity\User;

/**
 * MessageRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class MessageRepository extends BaseRepository
{
    public function findConversation(User $author, User $recipient)
    {
        $qb = $this->createQueryBuilder('m')
                ->where('m.author = :author AND m.recipient = :recipient OR m.author = :recipient AND m.recipient = :author')
                ->setParameter('author', $author)
                ->setParameter('recipient', $recipient)
                ->addOrderBy('m.createdAt', 'DESC');

        return $qb->getQuery()
                        ->getResult();
    }

}
