<?php

namespace App\Forum;

use App\Entity\Photo;
use App\Entity\Topic;
use App\Entity\User;

/** Read model of one topic in a forum listing (see {@see ThreadPreviewBuilder}). */
final readonly class ThreadPreview
{
    public function __construct(
        public Topic $topic,
        /** Topic photo first, else the first photo of the latest messages. */
        public ?Photo $coverPhoto,
        /** Plain text (escape on output) from the description, or the first message; '' when there is nothing to show. */
        public string $excerpt,
        public ?LastReply $lastReply,
        /** @var list<User> up to {@see ThreadPreviewBuilder::MAX_PARTICIPANTS} authors, most active first */
        public array $participants,
        /** Participants that do not fit in {@see $participants}. */
        public int $moreParticipants,
        public int $messageCount,
        public int $viewCount,
        /** Photos attached to the topic plus photos attached to its messages. */
        public int $photoCount,
        /** Messages posted since the viewer's previous login. */
        public int $newCount,
    ) {
    }

    public function isNew(): bool
    {
        return $this->newCount > 0;
    }
}
