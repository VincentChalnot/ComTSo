<?php

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

class SearchExtension
{
    /** Accented spellings of each base letter: the database search ignores accents, so does the highlighting. */
    private const VARIANTS = [
        'a' => 'aàáâãäå', 'c' => 'cç', 'e' => 'eèéêë', 'i' => 'iìíîï', 'n' => 'nñ',
        'o' => 'oòóôõö', 'u' => 'uùúûü', 'y' => 'yýÿ',
    ];

    /**
     * Plain-text excerpt of stored HTML around the first occurrence of a term, HTML-escaped,
     * with every occurrence (case and accent insensitive) wrapped in <mark>.
     */
    #[AsTwigFilter('excerpt', isSafe: ['html'])]
    public function excerpt(?string $html, string $term, int $length = 280): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br />', '<br/>'], ' ', (string) $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
        if ('' === $text) {
            return '';
        }

        $pattern = '' === trim($term) ? null : $this->pattern($term);
        $start = 0;
        if ($pattern && preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE)) {
            $start = max(0, mb_strlen(substr($text, 0, $match[0][1])) - (int) ($length / 3));
        }

        $slice = mb_substr($text, $start, $length);
        $excerpt = ($start > 0 ? '… ' : '').$slice.(mb_strlen($text) > $start + $length ? ' …' : '');
        $escaped = htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');
        if (!$pattern) {
            return $escaped;
        }

        // The excerpt is escaped before highlighting: search the escaped spelling of the term.
        return preg_replace($this->pattern(htmlspecialchars($term, ENT_QUOTES, 'UTF-8')), '<mark class="search-hit">$0</mark>', $escaped) ?? $escaped;
    }

    private function pattern(string $term): string
    {
        $regex = '';
        foreach (mb_str_split(mb_strtolower($term)) as $char) {
            $base = $this->baseLetter($char);
            $regex .= isset(self::VARIANTS[$base]) ? '['.self::VARIANTS[$base].mb_strtoupper(self::VARIANTS[$base]).']' : preg_quote($char, '/');
        }

        return '/'.$regex.'/iu';
    }

    private function baseLetter(string $char): string
    {
        foreach (self::VARIANTS as $base => $variants) {
            if (str_contains($variants, $char)) {
                return $base;
            }
        }

        return $char;
    }
}
