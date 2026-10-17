<?php

namespace App\Photo;

use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Topic;
use App\Entity\User;
use App\Repository\CommentPhotoRepository;
use App\Repository\PhotoRepository;
use App\Repository\PhotoTopicRepository;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photos attached to messages (and, when a topic is created, to the topic itself): storing the uploads,
 * linking them and cleaning up files that nothing references any more.
 */
class PostPhotos
{
    /** Same limit client-side (AttachPhotos.vue) and server-side (PhotoUploadType). */
    public const MAX_PER_POST = 10;

    public function __construct(
        private readonly PhotoUploader $uploader,
        private readonly EntityManagerInterface $em,
        private readonly PhotoRepository $photos,
        private readonly CommentPhotoRepository $commentPhotos,
        private readonly PhotoTopicRepository $photoTopics,
    ) {
    }

    /**
     * Stores every uploaded file, all or nothing: when one of them is refused, the files already stored are deleted again.
     *
     * @param array<mixed> $files
     *
     * @return list<Photo> new, not yet persisted photos
     *
     * @throws \InvalidArgumentException
     */
    public function store(array $files, User $author): array
    {
        $photos = [];
        try {
            foreach ($files as $file) {
                if ($file instanceof UploadedFile) {
                    $photos[] = $this->uploader->store($file, $author);
                }
            }
        } catch (\InvalidArgumentException $e) {
            foreach ($photos as $photo) {
                $this->uploader->delete($photo);
            }

            throw $e;
        }

        return $photos;
    }

    /** @param list<Photo> $photos */
    public function attachToComment(Comment $comment, array $photos, User $author): void
    {
        $order = $comment->getId() ? $this->commentPhotos->nextOrder($comment) : 0;
        foreach ($photos as $photo) {
            $link = (new CommentPhoto())->setComment($comment)->setPhoto($photo)->setAuthor($author)->setOrder($order++);
            $comment->getPhotos()->add($link);
            $this->em->persist($photo);
            $this->em->persist($link);
        }
        if ($photos && $comment->getId()) {
            // A photo added to an existing message is activity, like an upload in the topic photo manager.
            $comment->getTopic()->setLastActivityAt(new \DateTime());
        }
    }

    /** @param list<Photo> $photos */
    public function attachToTopic(Topic $topic, array $photos, User $author): void
    {
        $order = $topic->getId() ? $this->photoTopics->nextOrder($topic) : 0;
        foreach ($photos as $photo) {
            $link = (new PhotoTopic())->setTopic($topic)->setPhoto($photo)->setAuthor($author)->setOrder($order++);
            $this->em->persist($photo);
            $this->em->persist($link);
        }
    }

    /**
     * Detaches photos from a message. Call {@see releaseOrphans()} with the result once the change is flushed.
     *
     * @param iterable<CommentPhoto> $links
     *
     * @return list<Photo>
     */
    public function detach(Comment $comment, iterable $links): array
    {
        $photos = [];
        foreach ($links as $link) {
            if ($link->getComment() !== $comment) {
                continue;
            }
            $photos[] = $link->getPhoto();
            $comment->getPhotos()->removeElement($link);
            $this->em->remove($link);
        }

        return $photos;
    }

    /** @return list<Photo> the photos currently attached to a message */
    public function photosOf(Comment $comment): array
    {
        return array_map(static fn (CommentPhoto $link): Photo => $link->getPhoto(), $comment->getPhotos()->toArray());
    }

    /**
     * Deletes the photos (database row and files) that nothing references any more: no topic, no message, no avatar.
     * Must run after the removal of the links has been flushed.
     *
     * @param iterable<Photo> $photos
     */
    public function releaseOrphans(iterable $photos): void
    {
        $removed = false;
        foreach ($photos as $photo) {
            if (!$this->photos->isReferenced($photo)) {
                $this->em->remove($photo);
                $this->uploader->delete($photo);
                $removed = true;
            }
        }
        if ($removed) {
            $this->em->flush();
        }
    }
}
