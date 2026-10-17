<?php

namespace App\Legacy\Model;

/** A forum message of a legacy platform, already converted to HTML (not yet sanitized). */
final readonly class LegacyPost
{
    public function __construct(
        public string $source,
        public int $id,
        public int $topicId,
        /** Platform user id, or null for a guest. */
        public ?int $authorId,
        /** Name typed by a guest, when the platform recorded one. */
        public ?string $guestName,
        public \DateTimeImmutable $createdAt,
        public string $html,
        /** Why the post must not be imported whatever happens (e.g. unapproved), or null. */
        public ?string $skipReason = null,
    ) {
    }

    public function ref(): string
    {
        return sprintf('%s:post:%d', $this->source, $this->id);
    }

    public function withHtml(string $html): self
    {
        return new self($this->source, $this->id, $this->topicId, $this->authorId, $this->guestName, $this->createdAt, $html, $this->skipReason);
    }
}
