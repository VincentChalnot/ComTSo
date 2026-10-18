<?php

namespace App\Photo;

/** Result of {@see PhotoPipeline::ingest()}: the stored file and everything known about it. */
final readonly class ProcessedPhoto
{
    public function __construct(
        /** File name inside the private photo directory. */
        public string $filename,
        public string $mimeType,
        public int $fileSize,
        public int $width,
        public int $height,
        public PhotoMetadata $metadata,
    ) {
    }

    /** Extension of the stored file ("webp", or "gif" for animated GIFs). */
    public function extension(): string
    {
        return pathinfo($this->filename, \PATHINFO_EXTENSION);
    }
}
