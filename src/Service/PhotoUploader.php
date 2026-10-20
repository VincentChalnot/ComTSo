<?php

namespace App\Service;

use App\Entity\Photo;
use App\Entity\User;
use App\Photo\PhotoMetadata;
use App\Photo\PhotoPipeline;
use App\Photo\ProcessedPhoto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Validates an uploaded image, runs it through the photo pipeline (EXIF, orientation, 10 MP WebP) and builds the Photo entity. */
class PhotoUploader
{
    /** Largest file a member may send (the pixel limits are in {@see PhotoPipeline}). */
    public const MAX_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly PhotoPipeline $pipeline,
        private readonly ImageProcessingService $images,
        #[Autowire('%photos_directory%')]
        private readonly string $photosDirectory,
    ) {
    }

    /** @throws \InvalidArgumentException when the file is not an acceptable image */
    public function store(UploadedFile $file, User $author): Photo
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Le fichier n\'a pas pu être envoyé.');
        }
        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException('La photo dépasse 10 Mo.');
        }

        $processed = $this->pipeline->ingest($file->getPathname(), $this->photosDirectory);

        return self::build($processed, mb_substr($file->getClientOriginalName(), 0, 128))
            ->setFileModifiedAt(new \DateTime())
            ->setAuthor($author);
    }

    /** A new Photo for a file the pipeline just stored (the display name is the name the member knows the file by). */
    public static function build(ProcessedPhoto $processed, string $originalFilename): Photo
    {
        $photo = (new Photo())
            ->setFilename($processed->filename)
            ->setOriginalFilename($originalFilename)
            ->setFileSize($processed->fileSize)
            ->setFileType($processed->mimeType)
            ->setWidth($processed->width)
            ->setHeight($processed->height);
        self::applyMetadata($photo, $processed->metadata);

        return $photo;
    }

    public static function applyMetadata(Photo $photo, PhotoMetadata $metadata): void
    {
        $photo->setTakenAt($metadata->takenAt ? \DateTime::createFromImmutable($metadata->takenAt) : null)
            ->setGpsLatitude($metadata->latitude)
            ->setGpsLongitude($metadata->longitude)
            ->setGpsAltitude($metadata->altitude)
            ->setExif($metadata->exif ?: null);
    }

    public function delete(Photo $photo): void
    {
        $this->deleteFiles((string) $photo->getFilename());
    }

    /** Removes the stored file and every cached thumbnail of a stored photo. */
    public function deleteFiles(string $filename): void
    {
        $path = $this->images->originalPath($filename);
        if ('' !== $filename && is_file($path)) {
            unlink($path);
        }
        $this->images->purgeThumbnails($filename);
    }
}
