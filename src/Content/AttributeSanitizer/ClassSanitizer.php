<?php

namespace App\Content\AttributeSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Only the few CSS classes the forum styles survive (legacy quotes are `<div class="cite">`). */
final class ClassSanitizer implements AttributeSanitizerInterface
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'div' => ['cite'],
    ];

    public function getSupportedElements(): ?array
    {
        return array_keys(self::ALLOWED);
    }

    public function getSupportedAttributes(): ?array
    {
        return ['class'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $kept = array_values(array_intersect(preg_split('/\s+/', trim($value)) ?: [], self::ALLOWED[$element] ?? []));

        return $kept ? implode(' ', $kept) : null;
    }
}
