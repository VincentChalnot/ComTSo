<?php

namespace App\Legacy\Model;

/** A file attached to a phpBB 3 post (`phpbb3_attachments`; the file sits in `phpBB3/files/<physicalFilename>`). */
final readonly class LegacyAttachment
{
    public function __construct(
        public int $id,
        /** phpBB post id, 0 for an orphan (uploaded but never posted). */
        public int $postId,
        public int $posterId,
        public string $physicalFilename,
        public string $realFilename,
        public string $comment,
        public string $mimeType,
        /** Size recorded by phpBB when the file was uploaded. */
        public int $size,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    public function ref(): string
    {
        return sprintf('phpbb3:attachment:%d', $this->id);
    }
}
