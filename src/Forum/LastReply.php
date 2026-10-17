<?php

namespace App\Forum;

use App\Entity\Comment;
use App\Entity\User;

/** Teaser of the newest message of a topic. */
final readonly class LastReply
{
    public function __construct(
        public Comment $comment,
        public ?User $author,
        /** Plain text (escape on output), may be empty when the message is only a photo. */
        public string $excerpt,
        public bool $hasPhoto,
    ) {
    }
}
