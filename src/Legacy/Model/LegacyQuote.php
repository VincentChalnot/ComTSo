<?php

namespace App\Legacy\Model;

final readonly class LegacyQuote
{
    public function __construct(
        public string $source,
        public int $id,
        public string $html,
        public string $originalAuthor,
        public ?int $submitterId,
    ) {
    }

    public function ref(): string
    {
        return sprintf('%s:quote:%d', $this->source, $this->id);
    }
}
