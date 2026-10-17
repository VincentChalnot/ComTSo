<?php

namespace App\Twig;

use App\Content\TextExcerpt;
use Twig\Attribute\AsTwigFilter;

class ExcerptExtension
{
    public function __construct(private readonly TextExcerpt $excerpts)
    {
    }

    /**
     * Plain-text preview of stored HTML, cut at a word boundary. The result is TEXT (not marked safe):
     * Twig's auto-escaping applies, so no markup from user content can ever reach the page.
     * Not to be confused with `excerpt`, the search-highlighting filter of {@see SearchExtension}.
     */
    #[AsTwigFilter('excerpt_text')]
    public function excerptText(?string $html, int $length = 220): string
    {
        return $this->excerpts->excerpt($html, $length);
    }
}
