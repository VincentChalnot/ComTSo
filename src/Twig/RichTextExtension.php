<?php

namespace App\Twig;

use App\Content\ContentSanitizer;
use Twig\Attribute\AsTwigFilter;

class RichTextExtension
{
    public function __construct(private readonly ContentSanitizer $sanitizer)
    {
    }

    /** Renders stored HTML content (already sanitized on write, sanitized again here). */
    #[AsTwigFilter('rich_text', isSafe: ['html'])]
    public function richText(?string $content): string
    {
        return $this->sanitizer->sanitize($content);
    }
}
