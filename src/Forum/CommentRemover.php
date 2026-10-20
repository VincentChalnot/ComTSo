<?php

namespace App\Forum;

use App\Entity\Comment;
use App\Photo\PostPhotos;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes one message: the topic's message counter goes down, the photo links vanish with it (ON DELETE CASCADE) and photos
 * that nothing else references any more are released (file and row, {@see PostPhotos::releaseOrphans()}). Removing the first
 * message of a topic leaves the topic and its other messages alone: the topic itself is removed by {@see TopicRemover}.
 * Permission checks are the caller's (`CommentController::delete`: the author or an administrator; the admin panel).
 */
class CommentRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostPhotos $postPhotos,
    ) {
    }

    public function remove(Comment $comment): void
    {
        $topic = $comment->getTopic();
        $topic->setCommentCount(max(0, $topic->getCommentCount() - 1));
        $photos = $this->postPhotos->photosOf($comment);
        $this->em->remove($comment);
        $this->em->flush();
        $this->postPhotos->releaseOrphans($photos);
    }
}
