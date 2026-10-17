<?php

namespace App\Legacy\Model;

final readonly class LegacyTopic
{
    /** @param list<LegacyPost> $posts ordered by date */
    public function __construct(
        public string $source,
        public int $id,
        /** Id of the forum/category on the legacy platform. */
        public int $forumId,
        public string $title,
        public ?int $authorId,
        public \DateTimeImmutable $createdAt,
        public int $views,
        /** Topic description (old PHP forum only), HTML. */
        public ?string $summaryHtml,
        public array $posts,
        /** Free-form hint used by the forum mapping (e.g. "album"). */
        public ?string $kind = null,
    ) {
    }

    public function ref(): string
    {
        return sprintf('%s:topic:%d', $this->source, $this->id);
    }
}
