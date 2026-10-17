<?php

namespace App\Tests\Support;

use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Topic;

/**
 * Photo database rows without any file on disk, for tests that only need the links
 * (topic photos, message photos) and the generated URLs. Needs `em()` from {@see AppWebTestCase}.
 */
trait PhotoRowsTrait
{
    private int $photoRowCounter = 0;

    protected function photoRow(?string $title = null): Photo
    {
        $name = 'row-'.++$this->photoRowCounter.'-'.bin2hex(random_bytes(3)).'.jpeg';
        $photo = (new Photo())->setFilename($name)->setOriginalFilename($name)->setFileSize(1000)->setFileType('image/jpeg')->setTitle($title);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    protected function attachToTopic(Topic $topic, Photo $photo, ?int $order = null): PhotoTopic
    {
        $link = (new PhotoTopic())->setTopic($topic)->setPhoto($photo)->setOrder($order);
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    protected function attachToComment(Comment $comment, Photo $photo, ?int $order = null): CommentPhoto
    {
        $link = (new CommentPhoto())->setComment($comment)->setPhoto($photo)->setOrder($order);
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }
}
