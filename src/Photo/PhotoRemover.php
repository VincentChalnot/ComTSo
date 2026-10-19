<?php

namespace App\Photo;

use App\Entity\Photo;
use App\Entity\User;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a photo whatever uses it: whoever had it as an avatar loses it, links to topics and messages and likes go with the row
 * (ON DELETE CASCADE), and the stored file with its cached thumbnails is removed. Permission checks are the caller's
 * (`PhotoController::delete`: the author only when nothing uses the photo, an administrator always; the admin panel).
 */
class PhotoRemover
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PhotoUploader $uploader,
    ) {
    }

    public function remove(Photo $photo): void
    {
        $filename = (string) $photo->getFilename();
        $this->em->createQuery('UPDATE '.User::class.' u SET u.avatar = NULL WHERE u.avatar = :photo')->setParameter('photo', $photo)->execute();
        $this->em->createQuery('DELETE FROM '.Photo::class.' p WHERE p.id = :id')->setParameter('id', $photo->getId())->execute();
        $this->uploader->deleteFiles($filename);
    }
}
