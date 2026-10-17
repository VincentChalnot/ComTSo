<?php

namespace App\Content\AttributeSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Inline CSS is reduced to two declarations with strictly enumerated values: `text-align` (what the editor
 * emits) and `text-decoration` underline/line-through (TinyMCE-era legacy posts). Everything else is dropped.
 */
final class StyleSanitizer implements AttributeSanitizerInterface
{
    private const ALIGNMENTS = ['left', 'right', 'center', 'justify'];
    private const DECORATIONS = ['underline', 'line-through', 'underline line-through', 'line-through underline'];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $align = $decoration = null;

        foreach (explode(';', $value) as $declaration) {
            $pair = explode(':', $declaration, 2);
            if (2 !== \count($pair)) {
                continue;
            }
            $property = strtolower(trim($pair[0]));
            $propertyValue = strtolower(trim(preg_replace('/\s+/', ' ', $pair[1]) ?? ''));

            if ('text-align' === $property && \in_array($propertyValue, self::ALIGNMENTS, true)) {
                $align = $propertyValue;
            } elseif (\in_array($property, ['text-decoration', 'text-decoration-line'], true) && \in_array($propertyValue, self::DECORATIONS, true)) {
                $decoration = $propertyValue;
            }
        }

        $style = [];
        if (null !== $align) {
            $style[] = 'text-align:'.$align;
        }
        if (null !== $decoration) {
            $style[] = 'text-decoration:'.$decoration;
        }

        return $style ? implode(';', $style) : null;
    }
}
