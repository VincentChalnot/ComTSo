<?php

namespace App\Legacy\Model;

/** A user of one of the legacy platforms. `$id` is the platform's own user id. */
final readonly class LegacyUser
{
    public function __construct(
        public string $source,
        public int $id,
        public string $username,
        public ?string $email,
        public ?\DateTimeImmutable $registeredAt,
    ) {
    }

    public function ref(): string
    {
        return sprintf('%s:user:%d', $this->source, $this->id);
    }
}
