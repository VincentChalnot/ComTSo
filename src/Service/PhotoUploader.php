<?php

namespace App\Service;

use App\Entity\Photo;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Validates an uploaded image, stores it in the private photo directory and builds the Photo entity. */
class PhotoUploader
{
    public const MAX_SIZE = 25 * 1024 * 1024;

    private const EXTENSIONS = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function __construct(
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
            throw new \InvalidArgumentException('La photo dépasse 25 Mo.');
        }

        $mimeType = $file->getMimeType();
        $extension = self::EXTENSIONS[$mimeType] ?? null;
        if (null === $extension) {
            throw new \InvalidArgumentException('Seules les images JPEG, PNG, GIF et WebP sont acceptées.');
        }

        // Read everything we need before moving: the temporary file disappears afterwards.
        $size = $file->getSize();
        $originalName = mb_substr($file->getClientOriginalName(), 0, 128);
        $filename = uniqid().'.'.$extension;

        if (!is_dir($this->photosDirectory)) {
            mkdir($this->photosDirectory, 0775, true);
        }
        try {
            $file->move($this->photosDirectory, $filename);
        } catch (FileException $e) {
            throw new \InvalidArgumentException('La photo n\'a pas pu être enregistrée.', 0, $e);
        }
        $path = $this->images->originalPath($filename);

        $photo = (new Photo())
            ->setFilename($filename)
            ->setOriginalFilename($originalName)
            ->setFileSize($size)
            ->setFileType($mimeType)
            ->setFileModifiedAt(new \DateTime())
            ->setAuthor($author);

        if ($dimensions = $this->images->getImageDimensions($path)) {
            $photo->setWidth($dimensions['width'])->setHeight($dimensions['height']);
        }
        if ($exif = $this->images->extractExif($path)) {
            $photo->setExif($exif);
            if ($taken = $this->images->extractTakenDate($exif)) {
                $photo->setTakenAt($taken);
            }
        }

        return $photo;
    }

    public function delete(Photo $photo): void
    {
        $path = $this->images->originalPath($photo->getFilename());
        if (is_file($path)) {
            unlink($path);
        }
        foreach (array_keys(ImageProcessingService::VARIANTS) as $variant) {
            foreach (glob($this->images->cacheDirectory().'/'.$variant.'/'.pathinfo($photo->getFilename(), PATHINFO_FILENAME).'.*') ?: [] as $cached) {
                unlink($cached);
            }
        }
    }
}
