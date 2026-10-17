<?php

namespace App\Content\AttributeSanitizer;

use App\Content\YouTube;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * The only iframe the forum tolerates is a YouTube player: its `src` is rewritten to the canonical nocookie
 * embed URL, anything else removes the attribute (ContentSanitizer then drops the source-less iframe).
 */
final class YouTubeEmbedSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['iframe'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return YouTube::fromEmbedUrl($value);
    }
}
