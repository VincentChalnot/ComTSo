<?php

namespace App\Twig;

use App\Entity\Photo;
use App\Photo\PhotoDetails;
use Twig\Attribute\AsTwigFunction;

class PhotoExtension
{
    public function __construct(private readonly PhotoDetails $details)
    {
    }

    /** @return list<array{label: string, value: string, url?: string}> */
    #[AsTwigFunction('photo_details')]
    public function details(Photo $photo, bool $withGps = false): array
    {
        return $this->details->rows($photo, $withGps);
    }
}
