<?php

namespace App\Legacy\Model;

final readonly class LegacyPhoto
{
    public function __construct(
        public string $source,
        public int $id,
        public int $authorId,
        public int $albumId,
        public string $title,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function ref(): string
    {
        return sprintf('%s:photo:%d', $this->source, $this->id);
    }

    /** File name of the original in the old forum's photo directory. */
    public function legacyFilename(): string
    {
        return $this->id.'.jpg';
    }
}
