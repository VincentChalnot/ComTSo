<?php

namespace App\Forum;

use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Topic;
use App\Photo\PostPhotos;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moderation: deletes a whole topic with everything that hangs from it, in bulk (a topic can hold thousands of messages, so
 * nothing is hydrated): messages, the photo links of the topic and of its messages, favourites (join table, ON DELETE CASCADE).
 * Photo files that nothing else references any more are released through {@see PostPhotos::releaseOrphans()}.
 */
class TopicRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostPhotos $postPhotos,
    ) {
    }

    public function remove(Topic $topic): void
    {
        $topicId = $topic->getId();
        $photoIds = $this->photoIdsOf($topicId);

        $this->em->wrapInTransaction(function () use ($topicId): void {
            $this->em->createQuery('DELETE '.CommentPhoto::class.' cp WHERE cp.comment IN (SELECT c.id FROM '.Comment::class.' c WHERE c.topic = :topic)')->setParameter('topic', $topicId)->execute();
            $this->em->createQuery('DELETE '.Comment::class.' c WHERE c.topic = :topic')->setParameter('topic', $topicId)->execute();
            $this->em->createQuery('DELETE '.PhotoTopic::class.' pt WHERE pt.topic = :topic')->setParameter('topic', $topicId)->execute();
            $this->em->createQuery('DELETE '.Topic::class.' t WHERE t.id = :topic')->setParameter('topic', $topicId)->execute();
        });
        // The rows are gone, whatever the unit of work still holds about them (the topic, its messages) is stale: forget it all.
        $this->em->clear();

        $this->postPhotos->releaseOrphans($photoIds ? $this->em->getRepository(Photo::class)->findBy(['id' => $photoIds]) : []);
    }

    /** @return list<int> every photo linked to the topic itself or to one of its messages */
    private function photoIdsOf(int $topicId): array
    {
        $ids = [
            ...$this->em->createQuery('SELECT IDENTITY(cp.photo) FROM '.CommentPhoto::class.' cp JOIN cp.comment c WHERE c.topic = :topic')->setParameter('topic', $topicId)->getSingleColumnResult(),
            ...$this->em->createQuery('SELECT IDENTITY(pt.photo) FROM '.PhotoTopic::class.' pt WHERE pt.topic = :topic')->setParameter('topic', $topicId)->getSingleColumnResult(),
        ];

        return array_map('intval', array_values(array_unique($ids)));
    }
}
