<?php

namespace App\Legacy\Text;

/**
 * Posts of the old self-written PHP forum (`posts.text`, `topics.summary`, `messages.body`, quotes…).
 *
 * The dump is a mix of three generations, and every text went through one extra layer of mojibake
 * (UTF-8 stored as cp1252 and re-encoded, see {@see TextRepair::fixMojibake()}):
 *
 *  - 2007-2008, phpBB 3 posts imported as-is: `nl2br()`-ed text (`<br />\n`), smilies as
 *    `<img src="images/smilies/…">`, `<a class="postlink">` links and the *BBCode with uid left raw*
 *    (`[b:3bf54]…[/b:3bf54]`) because the old forum never rendered BBCode;
 *  - 2009-2012, TinyMCE HTML (`<p>`, `<strong>`, `<cite>`, `&eacute;` entities);
 *  - private messages / quotes: HTML with entities.
 */
final class OldPhpConverter
{
    private const BLOCK_HTML = '~<(?:p|div|table|ul|ol|h[1-6]|blockquote|pre)\b~i';
    private const BBCODE_REMNANT = '~\[/(?:b|i|u|s|quote|url|img|code|size|color|list|\*)(?::[a-z])?(?::[a-z0-9]{5,10})?\]|\[quote(?:=[^\]]*)?(?::[a-z0-9]{5,10})\]~i';

    public function __construct(private readonly BbcodeRenderer $renderer = new BbcodeRenderer())
    {
    }

    /** @param (callable(string, string): ?string)|null $attachment */
    public function convert(?string $text, ?callable $attachment = null): string
    {
        $text = TextRepair::cleanControlChars(TextRepair::fixMojibake((string) $text));
        $text = preg_replace('~src=(["\'])/?images/smilies/~i', 'src=$1/smilies/', $text) ?? $text;

        if (preg_match(self::BLOCK_HTML, $text)) {
            // TinyMCE era: already HTML. Only BBCode remnants (pasted from phpBB) need work.
            if (!preg_match(self::BBCODE_REMNANT, $text)) {
                return $text;
            }

            return $this->renderer->render($text, ['html' => true, 'nl2br' => false, 'paragraphs' => false, 'attachment' => $attachment]);
        }

        $tokens = [];
        $text = $this->extractHtml($text, $tokens);

        return $this->renderer->render($text, ['tokens' => $tokens, 'attachment' => $attachment, 'bbcode' => true]);
    }

    /** @param list<string> $tokens */
    private function extractHtml(string $text, array &$tokens): string
    {
        $add = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;

            return "\x01".(\count($tokens) - 1)."\x02";
        };

        $text = preg_replace('~<br\s*/?>\n?~i', "\n", $text) ?? $text;

        $text = preg_replace_callback(
            '~<img\b[^>]*?\bsrc=(["\'])([^"\']*)\1([^>]*)>~i',
            static function (array $m) use ($add): string {
                $src = TextRepair::decodeEntities($m[2]);
                if (str_starts_with($src, SmileyMap::URL_PREFIX)) {
                    $alt = preg_match('~\balt=(["\'])(.*?)\1~i', $m[3], $a) ? TextRepair::decodeEntities($a[2]) : '';
                    $title = preg_match('~\btitle=(["\'])(.*?)\1~i', $m[3], $t) ? TextRepair::decodeEntities($t[2]) : null;

                    return $add(SmileyMap::image(basename($src), $alt, $title));
                }
                $safe = BbcodeRenderer::safeUrl($src);

                return null !== $safe && preg_match('~^https?://~i', $safe)
                    ? $add('<img src="'.htmlspecialchars($safe, \ENT_QUOTES).'" alt="">')
                    : '';
            },
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~<a\b[^>]*?\bhref=(["\'])([^"\']*)\1[^>]*>(.*?)</a>~is',
            static function (array $m) use ($add): string {
                $href = BbcodeRenderer::safeUrl(TextRepair::decodeEntities($m[2]));
                $label = TextRepair::decodeEntities(strip_tags($m[3]));
                if (null === $href) {
                    return $add(htmlspecialchars($label, \ENT_NOQUOTES));
                }

                return $add('<a href="'.htmlspecialchars($href, \ENT_QUOTES).'">'.htmlspecialchars('' !== $label ? $label : $href, \ENT_NOQUOTES).'</a>');
            },
            $text,
        ) ?? $text;

        return $text;
    }
}
