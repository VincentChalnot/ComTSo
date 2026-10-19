<?php

namespace App\Twig;

use App\Entity\Photo;
use App\Photo\PhotoDetails;
use App\Photo\PhotoUrls;
use Twig\Attribute\AsTwigFunction;

class PhotoExtension
{
    public function __construct(
        private readonly PhotoDetails $details,
        private readonly PhotoUrls $urls,
    ) {
    }

    /** @return list<array{label: string, value: string, url?: string}> */
    #[AsTwigFunction('photo_details')]
    public function details(Photo $photo, bool $withGps = false): array
    {
        return $this->details->rows($photo, $withGps);
    }

    /** `{{ photo_url(photo, 'thumb') }}`: thumb, medium, hero, avatar, source (the original) or download. Always use it for image files. */
    #[AsTwigFunction('photo_url')]
    public function photoUrl(Photo $photo, string $kind): string
    {
        return $this->urls->url($photo, $kind);
    }
}
