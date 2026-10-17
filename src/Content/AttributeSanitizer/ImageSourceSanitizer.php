<?php

namespace App\Content\AttributeSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Images come from absolute http(s) URLs or from this site (`/smilies/…`, `/photos/…`). Legacy posts also
 * contain page-relative paths that never worked (`images/\nsmilies/icon_love.gif`): smilies are repaired,
 * every other relative path is dropped. Scheme/host validation is left to Symfony's URL sanitizer that runs next.
 */
final class ImageSourceSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $value = trim($value);

        if (preg_match('#^(?:https?:)?//[^/\s\\\\]#i', $value)) {
            return $value;
        }
        if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return $value;
        }
        if (preg_match('#smilies/(?:%0[AD]|\s)*(icon_[a-z0-9_]+\.gif)$#i', $value, $m)) {
            return '/smilies/'.$m[1];
        }

        return null;
    }
}
