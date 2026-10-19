<?php

namespace App\Legacy\Text;

/**
 * Builds the comparison keys used to recognise a legacy message that already exists in the live database.
 *
 * Two renderings of the same message never share their markup (BBCode vs HTML, `<br />` vs `<p>`, typographic
 * quotes added by the previous migration, smilies as images vs `:)` codes), so the key is made of the letters
 * and digits of the visible text only, case-folded and accent-preserving.
 */
final class TextNormalizer
{
    private const TYPOGRAPHY = [
        "\u{2019}" => "'", "\u{2018}" => "'", "\u{201A}" => "'", "\u{00B4}" => "'", "`" => "'",
        "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{00AB}" => '"', "\u{00BB}" => '"',
        "\u{2026}" => '...', "\u{2013}" => '-', "\u{2014}" => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ',
        "\u{0153}" => 'oe', "\u{0152}" => 'oe', "\u{00E6}" => 'ae', "\u{20AC}" => 'eur',
    ];

    /** Visible text and link targets of a message, reduced to lowercase letters and digits. */
    public static function fingerprint(?string $content): string
    {
        return self::key((string) $content, true);
    }

    /**
     * Same as {@see fingerprint()} but ignoring everything inside quotes: the previous migration rewrote
     * `[quote]` blocks ("Citation de x" headers), so the part written by the author is the reliable one.
     */
    public static function ownFingerprint(?string $content): string
    {
        return self::key((string) $content, false);
    }

    /**
     * "<text>|<links>": the previous migration replaced the label of `[url=…]label[/url]` by the address itself, so
     * link labels are ignored and links are compared through their targets only (anchors, `[url]`, bare addresses).
     */
    private static function key(string $content, bool $keepQuotes): string
    {
        $links = [];
        $text = self::visibleText($content, $keepQuotes, $links);

        return self::compact($text).'|'.implode(',', array_map(self::compactLink(...), $links));
    }

    /**
     * Lowercase, entity-free, whitespace-collapsed text with markup, BBCode and typography normalised.
     *
     * @param list<string> $links filled with the link targets found (anchors, then bare addresses)
     */
    public static function visibleText(string $content, bool $keepQuotes, array &$links = []): string
    {
        $text = TextRepair::fixMojibake($content);
        $text = TextRepair::decodeEntities(TextRepair::decodeEntities($text)); // "&amp;amp;quot;" exists in the wild

        if (!$keepQuotes) {
            for ($i = 0; $i < 5; ++$i) {
                $stripped = preg_replace('~<blockquote\b[^>]*>(?:(?!<blockquote\b).)*?</blockquote>~is', ' ', $text, -1, $count);
                $stripped = preg_replace('~<div class="cite"[^>]*>(?:(?!<div\b).)*?</div>~is', ' ', $stripped ?? $text);
                if (0 === $count && $stripped === $text) {
                    break;
                }
                $text = $stripped ?? $text;
            }
            $text = preg_replace('~\[quote\b[^\]]*\].*?\[/quote(?::[a-z0-9]+)?\]~is', ' ', $text) ?? $text;
        }

        // Quote headers differ between renderings ("gigi a écrit :" here, "Citation de "gigi"" in the previous migration).
        $text = preg_replace('~<cite\b[^>]*>.*?</cite>~is', ' ', $text) ?? $text;
        $text = preg_replace('~Citation de\s+(?:"[^"]*"?|\S+)~u', ' ', $text) ?? $text;

        // Anchors count through their target, not their label.
        $text = preg_replace_callback('~<a\b[^>]*?\bhref=(["\'])(.*?)\1[^>]*>.*?</a>~is', static function (array $m) use (&$links): string {
            $links[] = $m[2];

            return ' ';
        }, $text) ?? $text;
        $text = preg_replace_callback('~\[url(?:=([^\]]*?))?(?::[a-z0-9]{5,10})?\](.*?)\[/url(?::[a-z0-9]{5,10})?\]~is', static function (array $m) use (&$links): string {
            $links[] = '' !== $m[1] ? $m[1] : $m[2];

            return ' ';
        }, $text) ?? $text;

        // Smilies count through their alt text.
        $text = preg_replace_callback('~<img\b[^>]*>~i', static function (array $m) use (&$links): string {
            $src = preg_match('~\bsrc=(["\'])(.*?)\1~i', $m[0], $s) ? $s[2] : '';
            if (str_contains($src, '/smilies/') || str_contains($src, 'images/smilies')) {
                return preg_match('~\balt=(["\'])(.*?)\1~i', $m[0], $a) ? ' '.$a[2].' ' : ' ';
            }
            return ' '; // remote images are ignored: the previous migration replaced broken ones by "(Invalid img)"
        }, $text) ?? $text;
        $text = preg_replace('~\[img(?::[a-z0-9]{5,10})?\].*?\[/img(?::[a-z0-9]{5,10})?\]~is', ' ', $text) ?? $text;
        $text = str_ireplace('(Invalid img)', ' ', $text);

        $text = preg_replace('~<!--.*?-->~s', ' ', $text) ?? $text;
        $text = preg_replace_callback('~<iframe\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>(?:</iframe>)?~is', static function (array $m) use (&$links): string {
            $links[] = $m[2];

            return ' ';
        }, $text) ?? $text;
        // Attachment markers (raw phpBB tag, converted note, note left once the file could not be recovered) never count: the
        // attachments import rewrites them in the live text, which must not make a post look new.
        $text = preg_replace('~\[attachment=\d+(?::[a-z0-9]+)?\].*?\[/attachment(?::[a-z0-9]+)?\]~is', ' ', $text) ?? $text;
        $text = preg_replace('~\[pièce jointe(?: perdue)? : [^\]]*\]~u', ' ', $text) ?? $text;
        $text = preg_replace('~<iframe\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>(?:</iframe>)?~is', ' $2 ', $text) ?? $text;
        $text = preg_replace('~</?(?:p|br|div|li|tr|td|blockquote|cite|h[1-6]|pre|ul|ol|table)\b[^>]*>~i', ' ', $text) ?? $text;
        $text = strip_tags($text);
        // BBCode wrappers: `[b:uid]`, `[/quote:uid]`…
        $text = preg_replace('~\[/?(?:b|i|u|s|strike|quote|code|list|\*|url|img|color|size|font|attachment|youtube|flash|email|center|left|right|align|spoiler)(?:=[^\]]*)?(?::[a-z0-9])?(?::[a-z0-9]{5,10})?\]~i', ' ', $text) ?? $text;
        $text = TextRepair::decodeEntities($text);
        // Bare addresses are links too (the previous migration made them clickable).
        $text = preg_replace_callback('~(?:https?://|www\.)[^\s<>"\[\]]+~i', static function (array $m) use (&$links): string {
            $links[] = rtrim($m[0], '.,;:!?)');

            return ' ';
        }, $text) ?? $text;
        $text = strtr($text, self::TYPOGRAPHY);
        $text = mb_strtolower($text, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function compact(string $text): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
    }

    private static function compactLink(string $link): string
    {
        $link = TextRepair::decodeEntities(TextRepair::decodeEntities(trim($link)));
        // Photos are referenced through three generations of URLs; their ids are not comparable.
        if (preg_match('~image\.php\?(?:photo|thumb)=|/photos/(?:preview|thumbnail)/\d+|^/photos/\d+(?:/(?:thumb|medium|avatar))?$~i', $link)) {
            return 'photo';
        }
        // One key per YouTube video, whatever the form of the link (watch, youtu.be, nocookie embed).
        if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $link, $m)) {
            return 'yt'.mb_strtolower($m[1]);
        }
        $link = preg_replace('~^(?:https?:)?//(?:www\.)?~i', '', $link) ?? $link;

        return self::compact(mb_strtolower($link, 'UTF-8'));
    }

    /** Similarity (0..1) of two fingerprints; cheap enough for the few candidates sharing a timestamp. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $max = max(\strlen($a), \strlen($b));
        if (0 === $max) {
            return 1.0;
        }
        // Containment (one text quoting or extending the other) counts as a near match.
        if (\strlen($a) >= 12 && \strlen($b) >= 12 && (str_contains($a, $b) || str_contains($b, $a))) {
            return min(\strlen($a), \strlen($b)) / $max >= 0.5 ? 0.95 : 0.8;
        }
        // similar_text() is O(n^3) in the worst case: compare the first 600 bytes only.
        similar_text(substr($a, 0, 600), substr($b, 0, 600), $percent);

        // Order-insensitive companion: the previous migration sometimes cut a link in two, moving text around.
        return max($percent / 100, self::trigramDice($a, $b));
    }

    private static function trigramDice(string $a, string $b): float
    {
        $grams = static function (string $s): array {
            $set = [];
            for ($i = 0, $n = \strlen($s) - 2; $i < $n; ++$i) {
                $set[substr($s, $i, 3)] = true;
            }

            return $set;
        };
        $x = $grams($a);
        $y = $grams($b);
        if (!$x || !$y) {
            return 0.0;
        }

        return 2 * \count(array_intersect_key($x, $y)) / (\count($x) + \count($y));
    }
}
