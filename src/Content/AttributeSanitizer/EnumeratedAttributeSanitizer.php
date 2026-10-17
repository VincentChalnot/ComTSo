<?php

namespace App\Content\AttributeSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/** Presentational attributes of legacy markup (`<p align="center">`, `<td valign="top" colspan="2">`) with strict values. */
final class EnumeratedAttributeSanitizer implements AttributeSanitizerInterface
{
    private const PATTERNS = [
        'align' => '/^(?:left|right|center|justify)$/D',
        'valign' => '/^(?:top|middle|bottom|baseline)$/D',
        'colspan' => '/^[1-9]\d?$/D',
        'rowspan' => '/^[1-9]\d?$/D',
    ];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return array_keys(self::PATTERNS);
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = strtolower(trim($value));

        return preg_match(self::PATTERNS[$attribute], $value) ? $value : null;
    }
}
