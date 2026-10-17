<?php

namespace App\Legacy\Model;

/** A private message between two members (one row per recipient). */
final readonly class LegacyMessage
{
    public function __construct(
        public string $source,
        /** Unique reference suffix on the platform, e.g. "12" or "12:54" when a message has several recipients. */
        public string $key,
        public ?int $authorId,
        public int $recipientId,
        public \DateTimeImmutable $createdAt,
        public string $html,
        /** Subject line, kept as a bold first line when stored (ctso_message has no subject column). */
        public ?string $subject = null,
        /** Why the message must never be imported (e.g. a mirror copy), or null. */
        public ?string $skipReason = null,
    ) {
    }

    public function ref(): string
    {
        return sprintf('%s:message:%s', $this->source, $this->key);
    }
}
