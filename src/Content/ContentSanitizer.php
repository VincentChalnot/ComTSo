<?php

namespace App\Content;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Single entry point to make user-provided HTML safe. Every write path (forms, imports) stores the
 * sanitized result, and rendering sanitizes again as defense in depth.
 *
 * The allow-list itself lives in config/packages/html_sanitizer.yaml (+ App\Content\AttributeSanitizer\*).
 * This class adds what a per-attribute sanitizer cannot do:
 *  - remove `<iframe>`/`<img>` elements whose `src` was rejected (and any text inside an iframe);
 *  - turn a paragraph made only of a YouTube link into the embedded player.
 *
 * Symfony renders every attribute value HTML-escaped and always encodes `=`, `<` and `>` inside values and
 * text, so the regular expressions below can safely rely on `[^>]*` spanning exactly one start tag.
 */
class ContentSanitizer
{
    private const IFRAME = '#<iframe\b([^>]*+)>[^<]*+</iframe>#';
    private const IMAGE_WITHOUT_SOURCE = '#<img\b(?![^>]*\ssrc=)[^>]*+>#';
    /** A paragraph holding nothing but a link whose text is its own URL, or a plain URL. */
    private const SOLO_LINK = '#<p>(?:<a href="([^"]{1,300})"[^>]*+>([^<]{1,300})</a>|([^<\s]{1,300}))</p>#';
    /**
     * Symfony's serializer writes these as numeric entities (a defense against UTF-7 sniffing, moot for a UTF-8 page).
     * They are harmless inside double-quoted attributes and text, and stored as entities they make e-mail addresses,
     * apostrophes and "+" unsearchable with LIKE: store the plain characters.
     */
    private const PLAIN_CHARACTERS = ['&#43;' => '+', '&#61;' => '=', '&#64;' => '@', '&#96;' => '`', '&#039;' => "'"];

    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rich_text')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {
    }

    public function sanitize(?string $html): string
    {
        if (null === $html || '' === trim($html)) {
            return '';
        }

        $clean = $this->clean($html);

        if (str_contains($clean, 'youtu')) {
            $embedded = preg_replace_callback(self::SOLO_LINK, $this->embedVideoLink(...), $clean);
            if (null !== $embedded && $embedded !== $clean) {
                // The inserted iframes go through the full pipeline so that they carry the forced attributes.
                $clean = $this->clean($embedded);
            }
        }

        return strtr($clean, self::PLAIN_CHARACTERS);
    }

    private function clean(string $html): string
    {
        $clean = $this->sanitizer->sanitize($html);

        if (str_contains($clean, '<iframe')) {
            $clean = preg_replace_callback(
                self::IFRAME,
                static fn (array $m): string => str_contains($m[1], ' src="') ? '<iframe'.$m[1].'></iframe>' : '',
                $clean,
            ) ?? '';
        }
        if (str_contains($clean, '<img')) {
            $clean = preg_replace(self::IMAGE_WITHOUT_SOURCE, '', $clean) ?? '';
        }

        return $clean;
    }

    /** @param array<int, string> $m */
    private function embedVideoLink(array $m): string
    {
        if (isset($m[3]) && '' !== $m[3]) {
            $url = html_entity_decode($m[3], \ENT_QUOTES | \ENT_HTML5);
        } else {
            $url = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5);
            $text = html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5);
            // A labelled link ("my holiday video") is a deliberate choice: keep it as a link.
            if ($this->withoutScheme($text) !== $this->withoutScheme($url)) {
                return $m[0];
            }
        }

        $embed = YouTube::fromVideoUrl($url);

        return null === $embed ? $m[0] : '<iframe src="'.htmlspecialchars($embed, \ENT_QUOTES).'"></iframe>';
    }

    private function withoutScheme(string $url): string
    {
        return rtrim(strtolower(preg_replace('#^(?:(?:https?:)?//)?(?:www\.)?#i', '', trim($url)) ?? ''), '/');
    }
}
