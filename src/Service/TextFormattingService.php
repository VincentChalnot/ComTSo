<?php

namespace App\Service;

class TextFormattingService
{
    /**
     * Format text with basic BBCode-like syntax
     * Converts common forum formatting codes to HTML
     */
    public function formatBBCode(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Escape HTML first
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Bold [b]text[/b]
        $text = preg_replace('/\[b\](.*?)\[\/b\]/is', '<strong>$1</strong>', $text);

        // Italic [i]text[/i]
        $text = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $text);

        // Underline [u]text[/u]
        $text = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $text);

        // Strikethrough [s]text[/s]
        $text = preg_replace('/\[s\](.*?)\[\/s\]/is', '<del>$1</del>', $text);

        // Code [code]text[/code]
        $text = preg_replace('/\[code\](.*?)\[\/code\]/is', '<code>$1</code>', $text);

        // Quote [quote]text[/quote]
        $text = preg_replace('/\[quote\](.*?)\[\/quote\]/is', '<blockquote class="message-body">$1</blockquote>', $text);

        // Quote with author [quote=username]text[/quote]
        $text = preg_replace('/\[quote=([^\]]+)\](.*?)\[\/quote\]/is', '<blockquote class="message-body"><strong>$1 wrote:</strong><br>$2</blockquote>', $text);

        // URL [url]http://example.com[/url]
        $text = preg_replace('/\[url\](.*?)\[\/url\]/i', '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>', $text);

        // URL with text [url=http://example.com]text[/url]
        $text = preg_replace('/\[url=([^\]]+)\](.*?)\[\/url\]/i', '<a href="$1" target="_blank" rel="noopener noreferrer">$2</a>', $text);

        // Image [img]http://example.com/image.jpg[/img]
        $text = preg_replace('/\[img\](.*?)\[\/img\]/i', '<img src="$1" alt="Image" class="content-image" />', $text);

        // Lists [list][*]item[/list]
        $text = preg_replace('/\[list\](.*?)\[\/list\]/is', '<ul>$1</ul>', $text);
        $text = preg_replace('/\[\*\](.*?)(?=\[\*\]|\[\/list\])/is', '<li>$1</li>', $text);

        // Convert newlines to <br>
        $text = nl2br($text);

        return $text;
    }

    /**
     * Strip all BBCode tags from text
     */
    public function stripBBCode(string $text): string
    {
        // Remove all BBCode tags
        $text = preg_replace('/\[([^\]]+)\]/i', '', $text);

        return $text;
    }

    /**
     * Sanitize text for safe display (remove harmful content)
     */
    public function sanitize(string $text): string
    {
        // Remove script tags and event handlers
        $text = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $text);
        $text = preg_replace('/on\w+="[^"]*"/i', '', $text);
        $text = preg_replace("/on\w+='[^']*'/i", '', $text);

        return $text;
    }

    /**
     * Create excerpt from text
     */
    public function createExcerpt(string $text, int $maxLength = 200): string
    {
        // Strip BBCode first
        $text = $this->stripBBCode($text);

        // Strip HTML tags
        $text = strip_tags($text);

        // Trim to max length
        if (mb_strlen($text) > $maxLength) {
            $text = mb_substr($text, 0, $maxLength);
            $lastSpace = mb_strrpos($text, ' ');
            if ($lastSpace !== false) {
                $text = mb_substr($text, 0, $lastSpace);
            }
            $text .= '...';
        }

        return $text;
    }

    /**
     * Auto-link URLs in plain text
     */
    public function autoLinkUrls(string $text): string
    {
        $pattern = '/(https?:\/\/[^\s<]+)/i';
        $replacement = '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>';

        return preg_replace($pattern, $replacement, $text);
    }

    /**
     * Highlight search terms in text
     */
    public function highlightSearchTerms(string $text, array $terms): string
    {
        foreach ($terms as $term) {
            $term = preg_quote($term, '/');
            $text = preg_replace('/(' . $term . ')/i', '<mark>$1</mark>', $text);
        }

        return $text;
    }

    /**
     * Convert markdown-style formatting to HTML
     */
    public function formatMarkdown(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Escape HTML first
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Headers
        $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);

        // Bold **text**
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);

        // Italic *text*
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);

        // Code `text`
        $text = preg_replace('/`(.+?)`/s', '<code>$1</code>', $text);

        // Links [text](url)
        $text = preg_replace('/\[([^\]]+)\]\(([^\)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $text);

        // Convert newlines to <br>
        $text = nl2br($text);

        return $text;
    }

    /**
     * Clean text for database storage (remove excessive whitespace, etc.)
     */
    public function cleanForStorage(string $text): string
    {
        // Trim whitespace
        $text = trim($text);

        // Remove multiple consecutive newlines (keep max 2)
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        // Remove trailing whitespace from each line
        $lines = explode("\n", $text);
        $lines = array_map('rtrim', $lines);
        $text = implode("\n", $lines);

        return $text;
    }

    /**
     * Count words in text
     */
    public function countWords(string $text): int
    {
        $text = $this->stripBBCode($text);
        $text = strip_tags($text);
        $text = trim($text);

        if (empty($text)) {
            return 0;
        }

        return str_word_count($text);
    }

    /**
     * Detect and warn about potential spam patterns
     */
    public function detectSpam(string $text): bool
    {
        // Multiple URLs
        if (preg_match_all('/(https?:\/\/[^\s<]+)/i', $text, $matches) > 5) {
            return true;
        }

        // Excessive capitalization
        $upperCount = preg_match_all('/[A-Z]/', $text);
        $totalCount = mb_strlen(preg_replace('/\s/', '', $text));
        if ($totalCount > 0 && ($upperCount / $totalCount) > 0.5) {
            return true;
        }

        // Repeated characters
        if (preg_match('/(.)\1{10,}/', $text)) {
            return true;
        }

        return false;
    }
}
