<?php

namespace App\Photo;

use App\Entity\Comment;
use App\Entity\Photo;
use App\Entity\Topic;
use App\Service\ImageProcessingService;

/** The photo featured in the home page hero, with the rule that selected it and where it appears on the forum. */
final readonly class FeaturedPhoto
{
    public function __construct(
        public Photo $photo,
        public FeaturedRule $rule,
        /** Topic where the photo was most recently shown (attached to the topic, or to one of its messages). */
        public ?Topic $topic = null,
        /** Set when the most recent appearance is a message (null when attached to the topic itself). */
        public ?Comment $comment = null,
    ) {
    }

    public function kicker(): string
    {
        return $this->rule->kicker();
    }

    /** The photo's own title, else the title of the topic it appears in, else null. */
    public function title(): ?string
    {
        return $this->photo->getTitle() ?: $this->topic?->getTitle() ?: null;
    }

    /**
     * Pixel size of the served `hero` variant (never upscaled), or null when the photo's dimensions are unknown.
     *
     * @return array{width: int, height: int}|null
     */
    public function imageSize(): ?array
    {
        $width = $this->photo->getWidth();
        $height = $this->photo->getHeight();
        if (!$width || !$height) {
            return null;
        }

        [$maxWidth, $maxHeight] = ImageProcessingService::VARIANTS['hero'];
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);

        return ['width' => max(1, (int) round($width * $scale)), 'height' => max(1, (int) round($height * $scale))];
    }
}
