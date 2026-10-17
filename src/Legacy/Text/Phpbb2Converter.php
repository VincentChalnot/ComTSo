<?php

namespace App\Legacy\Text;

/**
 * phpBB 2 (`phpbb_posts_text.post_text`, `phpbb_privmsgs_text.privmsgs_text`): raw BBCode with a per-post
 * `bbcode_uid` suffix on every tag, plain text smilies (":lol:"), entity-encoded text (&amp; &#8230; …),
 * real newlines, and raw HTML tags when the post had `enable_html` set.
 */
final class Phpbb2Converter
{
    public function __construct(private readonly BbcodeRenderer $renderer = new BbcodeRenderer())
    {
    }

    public function convert(?string $text, string $uid = '', bool $bbcode = true, bool $smilies = true, bool $html = true): string
    {
        $text = TextRepair::cleanControlChars((string) $text);

        return $this->renderer->render($text, [
            'uid' => $uid,
            'bbcode' => $bbcode,
            'smilies' => $smilies,
            'autolink' => true,
            'html' => $html,
        ]);
    }
}
