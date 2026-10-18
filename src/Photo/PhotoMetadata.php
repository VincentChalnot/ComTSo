<?php

namespace App\Photo;

/** What is persisted about a photo besides its pixels: shooting date, GPS position and a curated flat EXIF map. */
final readonly class PhotoMetadata
{
    /**
     * @param array<string, scalar> $exif curated flat map (see {@see ExifReader}); never holds binary data
     */
    public function __construct(
        public ?\DateTimeImmutable $takenAt = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?float $altitude = null,
        public array $exif = [],
        /** EXIF orientation 1-8 (1 = nothing to do). */
        public int $orientation = 1,
    ) {
    }

    public function withExif(array $extra): self
    {
        return new self($this->takenAt, $this->latitude, $this->longitude, $this->altitude, $extra + $this->exif, $this->orientation);
    }

    /** Same metadata, plus keys of an older EXIF map that this extraction does not know (its own values win). */
    public function withDefaults(array $older): self
    {
        unset($older['ExifTool']); // the Symfony 2 forum kept the raw ExifTool dump (server paths included): not carried over
        return new self($this->takenAt, $this->latitude, $this->longitude, $this->altitude, $this->exif + $older, $this->orientation);
    }

    /** Columns of `ctso_photo` (DBAL inserts of the importers). @return array<string, mixed> */
    public function toColumns(): array
    {
        return [
            'taken_at' => $this->takenAt?->format('Y-m-d H:i:s'),
            'gps_latitude' => $this->latitude,
            'gps_longitude' => $this->longitude,
            'gps_altitude' => $this->altitude,
            'exif' => $this->exif ? json_encode($this->exif, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES) : null,
        ];
    }
}
