<?php

namespace App\Photo;

use App\Entity\User;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A member's avatar is a photo of the library: a new one goes through the photo pipeline like every upload, and the
 * previous one is released through {@see PostPhotos::releaseOrphans()} (file and row deleted only when nothing else
 * uses it: some legacy avatars also illustrate a topic or a message).
 */
class AvatarManager
{
    public function __construct(
        private readonly PhotoUploader $uploader,
        private readonly PostPhotos $postPhotos,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @throws \InvalidArgumentException when the file is not an acceptable image (nothing is stored then) */
    public function change(User $user, UploadedFile $file): void
    {
        $photo = $this->uploader->store($file, $user);
        $previous = $user->getAvatar();

        try {
            $this->em->persist($photo);
            $user->setAvatar($photo);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->uploader->delete($photo);

            throw $e;
        }

        if (null !== $previous) {
            $this->postPhotos->releaseOrphans([$previous]);
        }
    }

    public function remove(User $user): void
    {
        $previous = $user->getAvatar();
        if (null === $previous) {
            return;
        }

        $user->setAvatar(null);
        $this->em->flush();
        $this->postPhotos->releaseOrphans([$previous]);
    }
}
