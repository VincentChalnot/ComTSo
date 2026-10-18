<?php

namespace App\Photo;

use App\Service\PhotoUploader;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs a photo that is already a row of `ctso_photo` (legacy library, restored backup) through the {@see PhotoPipeline}
 * and updates the row in place: new file name, size, type, dimensions, EXIF map, shooting date and GPS. The old
 * file and its cached thumbnails are deleted once the row points to the new file. Plain DBAL, so the importers can use it too.
 */
class PhotoReprocessor
{
    public const UPDATED = 'updated';
    public const UP_TO_DATE = 'up-to-date';
    public const MISSING = 'missing';
    public const REJECTED = 'rejected';

    public function __construct(
        private readonly Connection $connection,
        private readonly PhotoPipeline $pipeline,
        private readonly PhotoUploader $uploader,
        #[Autowire('%photos_directory%')]
        private readonly string $photosDirectory,
    ) {
    }

    public function pathOf(string $filename): string
    {
        return $this->photosDirectory.'/'.$filename;
    }

    /** Whether {@see reprocess()} has something to do for this stored file (header inspection only, no decoding). */
    public function needsProcessing(string $filename, ?string $sourceDirectory = null): bool
    {
        $path = ($sourceDirectory ?? $this->photosDirectory).'/'.$filename;

        return is_file($path) && !$this->pipeline->isUpToDate($path);
    }

    /**
     * @param array{id: int|string, filename: string, exif?: ?string, taken_at?: ?string} $row
     * @param string|null $sourceDirectory where the file of the row currently is (default: the photo directory)
     * @param bool        $deleteSource    remove the source file once processed (always the case in place)
     *
     * @return self::UPDATED|self::UP_TO_DATE|self::MISSING|self::REJECTED
     */
    public function reprocess(array $row, ?string $sourceDirectory = null, bool $deleteSource = true, ?string &$error = null): string
    {
        $sourceDirectory ??= $this->photosDirectory;
        $inPlace = realpath($sourceDirectory) === realpath($this->photosDirectory);
        $source = $sourceDirectory.'/'.$row['filename'];
        if (!is_file($source)) {
            return self::MISSING;
        }
        if ($inPlace && $this->pipeline->isUpToDate($source)) {
            return self::UP_TO_DATE;
        }

        try {
            $processed = $this->pipeline->ingest($source, $this->photosDirectory);
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();

            return self::REJECTED;
        }

        $legacy = isset($row['exif']) ? json_decode($row['exif'], true) : null;
        $metadata = $processed->metadata->withDefaults(\is_array($legacy) ? $legacy : []);
        $columns = [
            'filename' => $processed->filename,
            'file_size' => $processed->fileSize,
            'file_type' => $processed->mimeType,
            'width' => $processed->width,
            'height' => $processed->height,
            'exif' => $metadata->exif ? json_encode($metadata->exif, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null,
        ];
        // The shooting date and the position only ever come from EXIF: keep what the row had when the file has none.
        if (null !== $metadata->takenAt) {
            $columns['taken_at'] = $metadata->takenAt->format('Y-m-d H:i:s');
        }
        if (null !== $metadata->latitude && null !== $metadata->longitude) {
            $columns += ['gps_latitude' => $metadata->latitude, 'gps_longitude' => $metadata->longitude, 'gps_altitude' => $metadata->altitude];
        }

        try {
            $this->connection->update('ctso_photo', $columns, ['id' => $row['id']]);
        } catch (\Throwable $e) {
            $this->uploader->deleteFiles($processed->filename);
            throw $e;
        }

        if ($deleteSource) {
            if ($inPlace) {
                $this->uploader->deleteFiles($row['filename']);
            } else {
                @unlink($source);
            }
        }

        return self::UPDATED;
    }
}
