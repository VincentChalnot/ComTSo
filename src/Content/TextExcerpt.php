<?php

namespace App\Content;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * Plain-text previews of stored HTML (topic descriptions, messages): never leaks markup.
 *
 * The HTML is parsed (not regex-stripped) so that entities are decoded once, nesting is handled and
 * everything that is not prose disappears: images and smilies, iframes (YouTube players), scripts, styles.
 * Block ends and line breaks count as spaces, every kind of whitespace (non-breaking spaces included) is collapsed.
 * Quoted messages (`<blockquote>`) are skipped so that a reply is previewed by what it says, unless it only quotes.
 *
 * The result is TEXT, not HTML: the caller (Twig auto-escaping) is responsible for escaping it on output.
 */
class TextExcerpt
{
    /** Elements whose content is not prose. */
    private const DROPPED = ['script', 'style', 'template', 'noscript', 'iframe', 'object', 'embed', 'video', 'audio', 'svg', 'img', 'picture', 'canvas'];

    /** Elements that break the text flow: a space is inserted around them. */
    private const BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    /** Plain text of an HTML fragment, whitespace collapsed (may be empty). */
    public function plain(?string $html): string
    {
        $html = (string) $html;
        if ('' === trim($html)) {
            return '';
        }
        if (!str_contains($html, '<') && !str_contains($html, '&')) {
            return $this->collapse($html);
        }

        $body = HTMLDocument::createFromString('<!DOCTYPE html><body>'.$html, LIBXML_NOERROR, 'UTF-8')->body;
        if (null === $body) {
            return '';
        }

        $text = $this->collapse($this->text($body, false));

        return '' !== $text ? $text : $this->collapse($this->text($body, true));
    }

    /** Plain text cut at a word boundary to at most $length characters (ellipsis included only when something was cut). */
    public function excerpt(?string $html, int $length = 220): string
    {
        return $this->truncate($this->plain($html), $length);
    }

    /** Multibyte-safe cut of a text whose whitespace is already collapsed. */
    public function truncate(string $text, int $length): string
    {
        if ($length < 1 || mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        // When the next character is a space the cut already falls on a word boundary; otherwise step back to the last space.
        if (' ' !== mb_substr($text, $length, 1)) {
            $space = mb_strrpos($cut, ' ');
            if (false !== $space && $space >= $length / 2) {
                $cut = mb_substr($cut, 0, $space);
            }
        }

        $trimmed = preg_replace('/[\s,;:.\-–—]+$/u', '', $cut);

        return ('' === (string) $trimmed ? $cut : $trimmed).'…';
    }

    private function text(Node $node, bool $withQuotes): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $out .= $child->data;
            } elseif ($child instanceof Element) {
                $name = $child->localName;
                if (\in_array($name, self::DROPPED, true) || (!$withQuotes && 'blockquote' === $name)) {
                    continue;
                }
                $gap = \in_array($name, self::BLOCKS, true) ? ' ' : '';
                $out .= $gap.$this->text($child, $withQuotes).$gap;
            }
        }

        return $out;
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', ' ', $text) ?? '');
    }
}
