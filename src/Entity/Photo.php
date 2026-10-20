<?php

namespace App\Entity;

use App\Repository\PhotoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: PhotoRepository::class)]
#[ORM\Table(name: 'ctso_photo')]
#[ORM\Index(name: 'idx_photo_taken_at', columns: ['taken_at'])]
#[ORM\Index(name: 'idx_photo_gps', columns: ['gps_latitude', 'gps_longitude'])]
class Photo implements \JsonSerializable
{
    use LegacyRefTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 128, unique: true)]
    private ?string $filename = null;

    #[ORM\Column(name: 'original_filename', type: Types::STRING, length: 128)]
    private ?string $originalFilename = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $fileSize = null;

    #[ORM\Column(type: Types::STRING, length: 128)]
    private ?string $fileType = null;

    #[ORM\Column(name: 'file_modified_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $fileModifiedAt = null;

    #[ORM\Column(name: 'taken_at', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $takenAt = null;

    /** Decimal degrees, negative south. Only shown to the photo's owner and administrators (home locations leak). */
    #[ORM\Column(name: 'gps_latitude', type: Types::DECIMAL, precision: 9, scale: 6, nullable: true)]
    private ?string $gpsLatitude = null;

    #[ORM\Column(name: 'gps_longitude', type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $gpsLongitude = null;

    /** Metres above sea level. */
    #[ORM\Column(name: 'gps_altitude', type: Types::DECIMAL, precision: 8, scale: 2, nullable: true)]
    private ?string $gpsAltitude = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $width = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $height = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $exif = null;

    /** Clockwise quarter turns a member asked for, in degrees (0, 90, 180 or 270). The stored file is never rewritten: variants are generated turned. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $rotation = 0;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    #[Gedmo\Timestampable(on: 'update')]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: PhotoTopic::class, mappedBy: 'photo', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $topics;

    public function __construct()
    {
        $this->topics = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): self
    {
        $this->filename = $filename;
        return $this;
    }

    /**
     * Short token that changes whenever what the variants show changes (the file is replaced or turned). Image URLs carry it
     * (`?v=`, see App\Photo\PhotoUrls): the responses are cached by browsers for a year, so the URL must change with the pixels.
     */
    public function getFileVersion(): string
    {
        return hash('crc32b', $this->filename.'|'.$this->rotation);
    }

    public function getRotation(): int
    {
        return $this->rotation;
    }

    /** Turns the displayed picture by a quarter turn, cumulatively: `true` = clockwise (right), `false` = counter-clockwise (left). */
    public function rotate(bool $clockwise): self
    {
        $this->rotation = ($this->rotation + ($clockwise ? 90 : 270)) % 360;

        return $this;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getFileType(): ?string
    {
        return $this->fileType;
    }

    public function setFileType(string $fileType): self
    {
        $this->fileType = $fileType;
        return $this;
    }

    public function getFileModifiedAt(): ?\DateTimeInterface
    {
        return $this->fileModifiedAt;
    }

    public function setFileModifiedAt(?\DateTimeInterface $fileModifiedAt): self
    {
        $this->fileModifiedAt = $fileModifiedAt;
        return $this;
    }

    public function getTakenAt(): ?\DateTimeInterface
    {
        return $this->takenAt;
    }

    public function setTakenAt(?\DateTimeInterface $takenAt): self
    {
        $this->takenAt = $takenAt;
        return $this;
    }

    public function getGpsLatitude(): ?float
    {
        return null === $this->gpsLatitude ? null : (float) $this->gpsLatitude;
    }

    public function setGpsLatitude(?float $gpsLatitude): self
    {
        $this->gpsLatitude = null === $gpsLatitude ? null : number_format($gpsLatitude, 6, '.', '');
        return $this;
    }

    public function getGpsLongitude(): ?float
    {
        return null === $this->gpsLongitude ? null : (float) $this->gpsLongitude;
    }

    public function setGpsLongitude(?float $gpsLongitude): self
    {
        $this->gpsLongitude = null === $gpsLongitude ? null : number_format($gpsLongitude, 6, '.', '');
        return $this;
    }

    public function getGpsAltitude(): ?float
    {
        return null === $this->gpsAltitude ? null : (float) $this->gpsAltitude;
    }

    public function setGpsAltitude(?float $gpsAltitude): self
    {
        $this->gpsAltitude = null === $gpsAltitude ? null : number_format($gpsAltitude, 2, '.', '');
        return $this;
    }

    public function hasGps(): bool
    {
        return null !== $this->gpsLatitude && null !== $this->gpsLongitude;
    }

    public function getWidth(): ?int
    {
        return $this->width;
    }

    public function setWidth(?int $width): self
    {
        $this->width = $width;
        return $this;
    }

    public function getHeight(): ?int
    {
        return $this->height;
    }

    public function setHeight(?int $height): self
    {
        $this->height = $height;
        return $this;
    }

    public function getExif(): ?array
    {
        return $this->exif;
    }

    public function setExif(?array $exif): self
    {
        $this->exif = $exif;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): self
    {
        $this->author = $author;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, PhotoTopic>
     */
    public function getTopics(): Collection
    {
        return $this->topics;
    }

    public function addTopic(PhotoTopic $topic): self
    {
        if (!$this->topics->contains($topic)) {
            $this->topics->add($topic);
            $topic->setPhoto($this);
        }
        return $this;
    }

    public function removeTopic(PhotoTopic $topic): self
    {
        if ($this->topics->removeElement($topic)) {
            if ($topic->getPhoto() === $this) {
                $topic->setPhoto(null);
            }
        }
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'originalFilename' => $this->originalFilename,
            'fileSize' => $this->fileSize,
            'fileType' => $this->fileType,
            'width' => $this->width,
            'height' => $this->height,
            'title' => $this->title,
            'content' => $this->content,
            'takenAt' => $this->takenAt?->format('Y-m-d H:i:s'),
        ];
    }

    public function __toString(): string
    {
        return $this->title ?? $this->filename ?? 'Photo #' . ($this->id ?? 'new');
    }
}
