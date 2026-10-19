<?php

namespace App\Forum;

use App\Entity\Forum;
use App\Entity\Topic;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a forum, but only an EMPTY one: topics (with their messages and photos) are never taken along, that is
 * {@see TopicRemover}'s job, one topic at a time. Used by the admin panel.
 */
class ForumRemover
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function topicCount(Forum $forum): int
    {
        return (int) $this->em->createQuery('SELECT COUNT(t.id) FROM '.Topic::class.' t WHERE t.forum = :forum')->setParameter('forum', $forum)->getSingleScalarResult();
    }

    /** @throws \DomainException when the forum still holds topics */
    public function remove(Forum $forum): void
    {
        if ($this->topicCount($forum) > 0) {
            throw new \DomainException(sprintf('Forum "%s" still has topics.', $forum->getId()));
        }

        $this->em->remove($forum);
        $this->em->flush();
    }
}
