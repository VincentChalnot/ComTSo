<?php

namespace App\Photo;

use App\Entity\Photo;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The only place that builds the URLs of a photo's image files (`photo_variant`, `photo_source`). They carry
 * {@see Photo::getFileVersion()} as `?v=`: the controllers let browsers keep the files for a year ("immutable"), so a
 * photo whose file was replaced (rotation) must get a new URL, or members would keep seeing the old picture.
 */
final class PhotoUrls
{
    /** Kinds accepted by {@see self::url()} besides the thumbnail variants: the stored original, inline or as a download. */
    public const SOURCE = 'source';
    public const DOWNLOAD = 'download';

    public function __construct(private readonly UrlGeneratorInterface $urls)
    {
    }

    /** @param string $kind `thumb`, `medium`, `hero`, `avatar`, `source` or `download` */
    public function url(Photo $photo, string $kind): string
    {
        return match ($kind) {
            self::SOURCE => $this->urls->generate('photo_source', ['id' => $photo->getId(), 'v' => $photo->getFileVersion()]),
            self::DOWNLOAD => $this->urls->generate('photo_source', ['id' => $photo->getId(), 'download' => 1, 'v' => $photo->getFileVersion()]),
            default => $this->urls->generate('photo_variant', ['id' => $photo->getId(), 'variant' => $kind, 'v' => $photo->getFileVersion()]),
        };
    }
}
