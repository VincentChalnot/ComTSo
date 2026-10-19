<?php

namespace App\Photo;

use App\Entity\Photo;
use App\Service\ImageProcessingService;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns a stored photo a quarter turn (the owner's and the administrators' "Pivoter" buttons).
 *
 * The turned picture is written by {@see PhotoPipeline::rotate()} under a NEW file name (like `app:photos:reprocess` does),
 * the row is switched to it, and only then the old file and every cached thumbnail of it (`cache/{thumb,medium,hero,avatar}`)
 * are deleted: a failure at any point leaves the photo as it was. The new name also changes {@see Photo::getFileVersion()},
 * hence every image URL, so browsers that kept the old picture for a year fetch the new one.
 */
class PhotoRotator
{
    public function __construct(
        private readonly PhotoPipeline $pipeline,
        private readonly PhotoUploader $uploader,
        private readonly ImageProcessingService $images,
        private readonly EntityManagerInterface $em,
        #[Autowire('%photos_directory%')]
        private readonly string $photosDirectory,
    ) {
    }

    /**
     * @throws \InvalidArgumentException French message when the photo cannot be turned (animated GIF, missing or unreadable file)
     */
    public function rotate(Photo $photo, bool $clockwise): void
    {
        $previous = (string) $photo->getFilename();
        $source = $this->images->originalPath($previous);
        if ('' === $previous || !is_file($source)) {
            throw new \InvalidArgumentException('Le fichier de cette photo est introuvable.');
        }

        $processed = $this->pipeline->rotate($source, $this->photosDirectory, $clockwise);

        $exif = $photo->getExif();
        if (\is_array($exif) && isset($exif['Orientation'])) {
            // The stored picture is upright as it is displayed: no orientation is left to apply.
            $exif['Orientation'] = 1;
        }
        $photo->setFilename($processed->filename)
            ->setFileType($processed->mimeType)
            ->setFileSize($processed->fileSize)
            ->setWidth($processed->width)
            ->setHeight($processed->height)
            ->setFileModifiedAt(new \DateTime())
            ->setExif($exif);

        try {
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->uploader->deleteFiles($processed->filename);

            throw $e;
        }

        $this->uploader->deleteFiles($previous);
    }
}
