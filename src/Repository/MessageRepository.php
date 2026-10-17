<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Private messages exchanged between two members (or the memos a member wrote to themselves).
     *
     * @return list<Message>
     */
    public function findConversation(User $a, User $b, string $order = 'asc', int $limit = 200): array
    {
        $direction = 'desc' === $order ? 'DESC' : 'ASC';
        $messages = $this->createQueryBuilder('m')
            ->where('(m.author = :a AND m.recipient = :b) OR (m.author = :b AND m.recipient = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        // Keep the most recent $limit messages, then present them in the requested order.
        return 'ASC' === $direction ? array_reverse($messages) : $messages;
    }

    /**
     * Unread private messages of a member, per sender (state NULL = never opened). Memos to oneself never count.
     *
     * @return array<int, int> sender id => number of unread messages
     */
    public function unreadCountsBySender(User $recipient): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.author) AS sender', 'COUNT(m.id) AS unread')
            ->where('m.recipient = :me')
            ->andWhere('m.state IS NULL')
            ->andWhere('m.author IS NOT NULL AND m.author != :me')
            ->groupBy('sender')
            ->setParameter('me', $recipient)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'unread', 'sender');
    }

    /** Marks the messages a member received from somebody as read. */
    public function markConversationRead(User $recipient, User $sender): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.state', 1)
            ->where('m.recipient = :me AND m.author = :sender AND m.state IS NULL')
            ->setParameter('me', $recipient)
            ->setParameter('sender', $sender)
            ->getQuery()
            ->execute();
    }
}
