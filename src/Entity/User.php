<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\LegacyPasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'fos_user')]
#[ORM\UniqueConstraint(name: 'UNIQ_username', columns: ['username'])]
#[ORM\UniqueConstraint(name: 'UNIQ_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface, LegacyPasswordAuthenticatedUserInterface, \JsonSerializable
{
    use LegacyRefTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Highest chat message id the user has seen, used to compute the unread badge. */
    #[ORM\Column(name: 'chat_last_read_id', type: Types::INTEGER, options: ['default' => 0])]
    private int $chatLastReadId = 0;

    public function getChatLastReadId(): int
    {
        return $this->chatLastReadId;
    }

    public function setChatLastReadId(int $chatLastReadId): static
    {
        $this->chatLastReadId = $chatLastReadId;

        return $this;
    }

    #[ORM\Column(type: Types::STRING, length: 180)]
    private ?string $username = null;

    #[ORM\Column(type: Types::STRING, length: 180)]
    private ?string $email = null;

    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column(type: Types::STRING)]
    private ?string $password = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $salt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $surname = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $birthday = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: Types::STRING, length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $activities = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(type: Types::STRING, length: 128, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(name: 'registered_at', type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $registeredAt = null;

    #[ORM\Column(name: 'previous_login', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $previousLogin = null;

    #[ORM\Column(name: 'last_activity', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastActivity = null;

    #[ORM\Column(name: 'last_login', type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    #[ORM\ManyToOne(targetEntity: Photo::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Photo $avatar = null;

    #[ORM\ManyToMany(targetEntity: Topic::class, inversedBy: 'starredBy')]
    #[ORM\JoinTable(name: 'ctso_starred_topic')]
    private Collection $starredTopics;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $config = null;

    public function __construct()
    {
        $this->starredTopics = new ArrayCollection();
        $this->registeredAt = new \DateTime();
        $this->roles = ['ROLE_USER'];
        $this->config = ['theme' => 'light'];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return (string) $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Salt of the legacy FOSUser sha512 hash. Cleared once the password has been
     * transparently re-hashed with the modern algorithm at first login.
     */
    public function getSalt(): ?string
    {
        return $this->salt;
    }

    public function setSalt(?string $salt): self
    {
        $this->salt = $salt;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSurname(): ?string
    {
        return $this->surname;
    }

    public function setSurname(?string $surname): self
    {
        $this->surname = $surname;
        return $this;
    }

    public function getBirthday(): ?\DateTimeInterface
    {
        return $this->birthday;
    }

    public function setBirthday(?\DateTimeInterface $birthday): self
    {
        $this->birthday = $birthday;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getActivities(): ?string
    {
        return $this->activities;
    }

    public function setActivities(?string $activities): self
    {
        $this->activities = $activities;
        return $this;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
        return $this;
    }

    public function getRegisteredAt(): ?\DateTimeInterface
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(\DateTimeInterface $registeredAt): self
    {
        $this->registeredAt = $registeredAt;
        return $this;
    }

    public function getPreviousLogin(): ?\DateTimeInterface
    {
        return $this->previousLogin;
    }

    public function setPreviousLogin(?\DateTimeInterface $previousLogin): self
    {
        $this->previousLogin = $previousLogin;
        return $this;
    }

    public function getLastActivity(): ?\DateTimeInterface
    {
        return $this->lastActivity;
    }

    public function setLastActivity(?\DateTimeInterface $lastActivity): self
    {
        $this->lastActivity = $lastActivity;
        return $this;
    }

    public function getLastLogin(): ?\DateTimeInterface
    {
        return $this->lastLogin;
    }

    public function setLastLogin(?\DateTimeInterface $lastLogin): self
    {
        $this->lastLogin = $lastLogin;
        return $this;
    }

    public function getAvatar(): ?Photo
    {
        return $this->avatar;
    }

    public function setAvatar(?Photo $avatar): self
    {
        $this->avatar = $avatar;
        return $this;
    }

    /**
     * @return Collection<int, Topic>
     */
    public function getStarredTopics(): Collection
    {
        return $this->starredTopics;
    }

    public function addStarredTopic(Topic $topic): self
    {
        if (!$this->starredTopics->contains($topic)) {
            $this->starredTopics->add($topic);
        }
        return $this;
    }

    public function removeStarredTopic(Topic $topic): self
    {
        $this->starredTopics->removeElement($topic);
        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config ?? ['theme' => 'light'];
    }

    public function setConfig(?array $config): self
    {
        $this->config = $config;
        return $this;
    }

    /** Bootswatch themes of the legacy forum that were dark: users who picked one keep a dark interface. */
    private const LEGACY_DARK_THEMES = ['slate', 'darkly', 'cyborg', 'superhero'];

    public function getTheme(): string
    {
        $theme = $this->config['theme'] ?? null;
        if (\in_array($theme, ['light', 'dark'], true)) {
            return $theme;
        }

        return \in_array($this->config['bootstrap_theme'] ?? null, self::LEGACY_DARK_THEMES, true) ? 'dark' : 'light';
    }

    public function setTheme(string $theme): self
    {
        $config = $this->config ?? [];
        $config['theme'] = $theme;
        $this->config = $config;
        return $this;
    }

    /** "asc": oldest message first (newest last), "desc": newest first. Legacy configs stored "ASC"/"DESC". */
    public function getMessageOrder(): string
    {
        return 'desc' === strtolower((string) ($this->config['message_order'] ?? 'asc')) ? 'desc' : 'asc';
    }

    public function setMessageOrder(string $order): self
    {
        $config = $this->config ?? [];
        $config['message_order'] = 'desc' === strtolower($order) ? 'desc' : 'asc';
        $this->config = $config;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'surname' => $this->surname,
        ];
    }
}
