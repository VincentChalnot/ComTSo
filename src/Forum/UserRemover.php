<?php

namespace App\Forum;

use App\Entity\ChatMessage;
use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Entity\Forum;
use App\Entity\Message;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Quote;
use App\Entity\Topic;
use App\Entity\User;
use App\Photo\PostPhotos;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Erases a member and what they wrote (used for test accounts at the cutover, `app:cutover:prepare --delete-user`):
 * their topics (with the messages of others in them, see {@see TopicRemover}), their messages elsewhere, private
 * messages in both directions and chat messages. Photos nobody uses any more lose their file; what stays referenced
 * (e.g. a forum, a quote) is kept and simply loses its author. Safe to run again: a member already gone is a no-op.
 */
class UserRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TopicRemover $topics,
        private readonly PostPhotos $postPhotos,
    ) {
    }

    /** @return array{topics: int, comments: int, privateMessages: int, chatMessages: int} what {@see remove()} would delete */
    public function impact(User $user): array
    {
        $count = fn (string $dql): int => (int) $this->em->createQuery($dql)->setParameter('user', $user)->getSingleScalarResult();

        return [
            'topics' => $count('SELECT COUNT(t.id) FROM '.Topic::class.' t WHERE t.author = :user'),
            'comments' => $count('SELECT COUNT(c.id) FROM '.Comment::class.' c WHERE c.author = :user'),
            'privateMessages' => $count('SELECT COUNT(m.id) FROM '.Message::class.' m WHERE m.author = :user OR m.recipient = :user'),
            'chatMessages' => $count('SELECT COUNT(cm.id) FROM '.ChatMessage::class.' cm WHERE cm.author = :user'),
        ];
    }

    /** @return array{topics: int, comments: int, privateMessages: int, chatMessages: int} what has been deleted */
    public function remove(User $user): array
    {
        $impact = $this->impact($user);
        $userId = $user->getId();
        $avatarId = $user->getAvatar()?->getId();

        // Removing a topic empties the unit of work: from here on everything is addressed by id.
        foreach ($this->em->createQuery('SELECT t.id FROM '.Topic::class.' t WHERE t.author = :user')->setParameter('user', $userId)->getSingleColumnResult() as $topicId) {
            $this->topics->remove($this->em->getReference(Topic::class, $topicId));
        }

        $photoIds = $this->removeComments($userId);

        $this->em->createQuery('DELETE '.Message::class.' m WHERE m.author = :user OR m.recipient = :user')->setParameter('user', $userId)->execute();
        $this->em->createQuery('DELETE '.ChatMessage::class.' cm WHERE cm.author = :user')->setParameter('user', $userId)->execute();
        foreach ([Forum::class, Photo::class, PhotoTopic::class, Quote::class] as $class) {
            $this->em->createQuery('UPDATE '.$class.' x SET x.author = NULL WHERE x.author = :user')->setParameter('user', $userId)->execute();
        }

        // The avatar is the one reference from the member to a photo; starred topics go with the row (ON DELETE CASCADE).
        $user = $this->em->find(User::class, $userId);
        $user->setAvatar(null);
        $this->em->flush();
        $this->em->remove($user);
        $this->em->flush();

        $photoIds = array_values(array_unique([...$photoIds, ...(null === $avatarId ? [] : [$avatarId])]));
        $this->postPhotos->releaseOrphans($photoIds ? $this->em->getRepository(Photo::class)->findBy(['id' => $photoIds]) : []);

        return $impact;
    }

    /** @return list<int> the photos that were attached to the removed messages */
    private function removeComments(int $userId): array
    {
        $photoIds = $this->em->createQuery('SELECT IDENTITY(cp.photo) FROM '.CommentPhoto::class.' cp JOIN cp.comment c WHERE c.author = :user')->setParameter('user', $userId)->getSingleColumnResult();
        $topicIds = $this->em->createQuery('SELECT DISTINCT IDENTITY(c.topic) FROM '.Comment::class.' c WHERE c.author = :user')->setParameter('user', $userId)->getSingleColumnResult();

        $this->em->wrapInTransaction(function () use ($userId, $topicIds): void {
            $this->em->createQuery('DELETE '.CommentPhoto::class.' cp WHERE cp.comment IN (SELECT c.id FROM '.Comment::class.' c WHERE c.author = :user)')->setParameter('user', $userId)->execute();
            $this->em->createQuery('DELETE '.Comment::class.' c WHERE c.author = :user')->setParameter('user', $userId)->execute();
            foreach ($topicIds as $topicId) {
                $remaining = (int) $this->em->createQuery('SELECT COUNT(c.id) FROM '.Comment::class.' c WHERE c.topic = :topic')->setParameter('topic', (int) $topicId)->getSingleScalarResult();
                $this->em->createQuery('UPDATE '.Topic::class.' t SET t.commentCount = :n WHERE t.id = :topic')->setParameter('n', $remaining)->setParameter('topic', (int) $topicId)->execute();
            }
        });
        $this->em->clear();

        return array_map('intval', array_values(array_unique($photoIds)));
    }
}
