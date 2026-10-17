<?php

namespace App\Entity;

use App\Repository\TopicRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: TopicRepository::class)]
#[ORM\Table(name: 'ctso_topic')]
#[ORM\Index(name: 'idx_topic_last_activity', columns: ['last_activity_at'])]
#[ORM\HasLifecycleCallbacks]
class Topic
{
    use LegacyRefTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Last time a message was posted (or the topic created): drives the ordering of forum feeds. */
    #[ORM\Column(name: 'last_activity_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $lastActivityAt = null;

    #[ORM\PrePersist]
    public function initializeLastActivity(): void
    {
        $this->lastActivityAt ??= new \DateTime();
    }

    public function getLastActivityAt(): ?\DateTimeInterface
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(\DateTimeInterface $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;

        return $this;
    }

    #[ORM\Column(type: Types::STRING, length: 128)]
    private ?string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\ManyToOne(targetEntity: Forum::class, inversedBy: 'topics')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Forum $forum = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $author = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    #[Gedmo\Timestampable(on: 'update')]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'topic', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $comments;

    #[ORM\OneToMany(targetEntity: PhotoTopic::class, mappedBy: 'topic', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['order' => 'ASC'])]
    private Collection $photos;

    #[ORM\Column(type: Types::INTEGER)]
    private int $views = 0;

    #[ORM\Column(name: 'comment_count', type: Types::INTEGER)]
    private int $commentCount = 0;

    /** Inverse side of User::$starredTopics (favourites). Read-only: modify the owning side. */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'starredTopics')]
    private Collection $starredBy;

    public function __construct()
    {
        $this->comments = new ArrayCollection();
        $this->photos = new ArrayCollection();
        $this->starredBy = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
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

    public function getForum(): ?Forum
    {
        return $this->forum;
    }

    public function setForum(?Forum $forum): self
    {
        $this->forum = $forum;
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
     * @return Collection<int, Comment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(Comment $comment): self
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setTopic($this);
        }
        return $this;
    }

    public function removeComment(Comment $comment): self
    {
        if ($this->comments->removeElement($comment)) {
            if ($comment->getTopic() === $this) {
                $comment->setTopic(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PhotoTopic>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(PhotoTopic $photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setTopic($this);
        }
        return $this;
    }

    public function removePhoto(PhotoTopic $photo): self
    {
        if ($this->photos->removeElement($photo)) {
            if ($photo->getTopic() === $this) {
                $photo->setTopic(null);
            }
        }
        return $this;
    }

    public function getViews(): int
    {
        return $this->views;
    }

    public function setViews(int $views): self
    {
        $this->views = $views;
        return $this;
    }

    public function incrementViews(): self
    {
        $this->views++;
        return $this;
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function setCommentCount(int $commentCount): self
    {
        $this->commentCount = $commentCount;
        return $this;
    }

    /** Bookkeeping when a message is posted: bumps the counter and moves the topic up in the feeds. */
    public function registerNewComment(\DateTimeInterface $at): static
    {
        ++$this->commentCount;
        $this->lastActivityAt = $at;

        return $this;
    }

    /** @return Collection<int, User> members who marked this topic as a favourite */
    public function getStarredBy(): Collection
    {
        return $this->starredBy;
    }

    public function __toString(): string
    {
        return $this->title ?? '';
    }
}
