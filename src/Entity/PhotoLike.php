<?php

namespace App\Entity;

use App\Repository\PhotoLikeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A member likes a photo ("J'aime"). At most one per member and photo; vanishes with either of them. */
#[ORM\Entity(repositoryClass: PhotoLikeRepository::class)]
#[ORM\Table(name: 'ctso_photo_like')]
#[ORM\UniqueConstraint(name: 'uniq_photo_like', columns: ['user_id', 'photo_id'])]
#[ORM\Index(name: 'idx_photo_like_created', columns: ['created_at'])]
class PhotoLike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Photo::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Photo $photo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    public function __construct(?Photo $photo = null, ?User $user = null, ?\DateTimeInterface $createdAt = null)
    {
        $this->photo = $photo;
        $this->user = $user;
        $this->createdAt = $createdAt ?? new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPhoto(): ?Photo
    {
        return $this->photo;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }
}
