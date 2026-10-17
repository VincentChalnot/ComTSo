<?php

namespace App\Legacy\Text;

/**
 * phpBB 3.0 (`phpbb3_posts.post_text`, `phpbb3_privmsgs.message_text`): the *stored* form, i.e. BBCode with a
 * `bbcode_uid` suffix on every tag, entity-encoded text, and some pieces already rendered to HTML:
 *
 *  - smilies  `<!-- s:) --><img src="{SMILIES_PATH}/icon_smile.gif" alt=":)" title="Smile" /><!-- s:) -->`
 *  - magic links `<!-- m --><a class="postlink" href="…">…</a><!-- m -->` (also `w`, `e`, `l`)
 */
final class Phpbb3Converter
{
    public function __construct(private readonly BbcodeRenderer $renderer = new BbcodeRenderer())
    {
    }

    /**
     * @param (callable(string, string): ?string)|null $attachment resolves `[attachment=N]file[/attachment]`
     */
    public function convert(?string $text, string $uid = '', bool $bbcode = true, ?callable $attachment = null): string
    {
        $text = TextRepair::cleanControlChars((string) $text);
        $tokens = [];
        $text = self::extractRenderedParts($text, $tokens);

        return $this->renderer->render($text, [
            'uid' => $uid,
            'bbcode' => $bbcode,
            'tokens' => $tokens,
            'attachment' => $attachment,
        ]);
    }

    /**
     * Replaces the already-rendered HTML of a stored phpBB 3 text (smilies, magic links) by "\x01n\x02" tokens.
     *
     * @param list<string> $tokens filled with the inline HTML of each token
     */
    public static function extractRenderedParts(string $text, array &$tokens): string
    {
        $add = static function (string $html) use (&$tokens): string {
            $tokens[] = $html;

            return "\x01".(\count($tokens) - 1)."\x02";
        };

        $text = preg_replace_callback(
            '~<!-- s(.*?) --><img src="\{SMILIES_PATH\}/([^"]+)" alt="([^"]*)" title="([^"]*)" ?/><!-- s\1 -->~s',
            static fn (array $m): string => $add(SmileyMap::image(basename($m[2]), TextRepair::decodeEntities($m[3]), TextRepair::decodeEntities($m[4]))),
            $text,
        ) ?? $text;

        $text = preg_replace_callback(
            '~<!-- ([mwel]) --><a (?:class="[^"]*" )?href="([^"]*)"[^>]*>(.*?)</a><!-- \1 -->~s',
            static function (array $m) use ($add): string {
                $href = TextRepair::decodeEntities($m[2]);
                $label = TextRepair::decodeEntities($m[3]);
                if (str_contains($label, ' ... ') || str_contains($label, '...')) {
                    $label = preg_replace('~^mailto:~i', '', $href) ?? $href; // phpBB shortens long labels
                }
                $safe = BbcodeRenderer::safeUrl($href);
                if (null === $safe) {
                    return $add(htmlspecialchars($label, \ENT_NOQUOTES));
                }

                return $add('<a href="'.htmlspecialchars($safe, \ENT_QUOTES).'">'.htmlspecialchars($label, \ENT_NOQUOTES).'</a>');
            },
            $text,
        ) ?? $text;

        // Whatever HTML comment marker is left over carries no information.
        return preg_replace('~<!-- /?[a-z0-9]* -->~', '', $text) ?? $text;
    }
}
