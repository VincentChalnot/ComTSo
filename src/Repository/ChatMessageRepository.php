<?php

namespace App\Repository;

use App\Entity\ChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /** @return list<ChatMessage> */
    public function search(string $term, int $limit = 30): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a')
            ->leftJoin('m.author', 'a')
            ->where('m.content LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Chat messages written by other members after the last one this member has seen. */
    public function countUnreadFor(\App\Entity\User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.id > :lastRead')
            ->andWhere('m.author IS NULL OR m.author != :user')
            ->setParameter('lastRead', $user->getChatLastReadId())
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Consistent view for the live badge: the newest message id and the unread count bounded by it, so a message
     * written meanwhile is counted by neither (its Mercure update will).
     *
     * @return array{latestId: int, unread: int}
     */
    public function unreadSnapshot(\App\Entity\User $user): array
    {
        $latestId = $this->latestId();
        $unread = (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.id > :lastRead')
            ->andWhere('m.id <= :latest')
            ->andWhere('m.author IS NULL OR m.author != :user')
            ->setParameter('lastRead', $user->getChatLastReadId())
            ->setParameter('latest', $latestId)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return ['latestId' => $latestId, 'unread' => $unread];
    }

    /**
     * One page of history, oldest first: the latest messages, or those older than $before (cursor = message id).
     *
     * @return array{messages: list<ChatMessage>, hasMore: bool} `hasMore` tells whether older messages exist
     */
    public function findBefore(?int $before, int $limit = 50): array
    {
        $qb = $this->pageQuery($limit + 1)->orderBy('m.id', 'DESC');
        if (null !== $before) {
            $qb->where('m.id < :before')->setParameter('before', $before);
        }

        /** @var list<ChatMessage> $rows */
        $rows = $qb->getQuery()->getResult();
        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return ['messages' => array_reverse($rows), 'hasMore' => $hasMore];
    }

    /**
     * Messages written after $after (catch-up after a reconnection), oldest first.
     *
     * @return array{messages: list<ChatMessage>, hasMore: bool} `hasMore` tells whether even newer messages remain
     */
    public function findAfter(int $after, int $limit = 200): array
    {
        /** @var list<ChatMessage> $rows */
        $rows = $this->pageQuery($limit + 1)
            ->where('m.id > :after')
            ->setParameter('after', $after)
            ->orderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
        $hasMore = \count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        return ['messages' => $rows, 'hasMore' => $hasMore];
    }

    public function latestId(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('MAX(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Records that the member has seen the chat up to this message. Monotonic: the pointer never moves backwards
     * (two tabs, replayed requests) and never beyond the newest message. Returns whether it moved.
     */
    public function markReadUpTo(\App\Entity\User $user, int $messageId): bool
    {
        $target = min($messageId, $this->latestId());

        $moved = $this->getEntityManager()->createQueryBuilder()
            ->update(\App\Entity\User::class, 'u')
            ->set('u.chatLastReadId', ':target')
            ->where('u.id = :id')
            ->andWhere('u.chatLastReadId < :target')
            ->setParameter('target', $target)
            ->setParameter('id', $user->getId())
            ->getQuery()
            ->execute() > 0;

        if ($moved) {
            $this->getEntityManager()->refresh($user);
        }

        return $moved;
    }

    private function pageQuery(int $limit): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a', 'av')
            ->leftJoin('m.author', 'a')
            ->leftJoin('a.avatar', 'av')
            ->setMaxResults($limit);
    }
}
