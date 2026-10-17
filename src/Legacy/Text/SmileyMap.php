<?php

namespace App\Legacy\Text;

/**
 * phpBB 2 stored smilies as plain text codes (":lol:", ";)"). The new forum serves the images of the phpBB 3
 * generation from /smilies/ (public/smilies), so the phpBB 2 codes are mapped onto those files (the phpBB 3
 * theme used the "icon_e_*" variants of the same emoticons).
 */
final class SmileyMap
{
    public const URL_PREFIX = '/smilies/';

    /** file => [title, codes...] */
    private const SMILIES = [
        'icon_e_biggrin.gif' => ['Very Happy', ':D', ':-D', ':grin:'],
        'icon_e_smile.gif' => ['Smile', ':)', ':-)', ':smile:'],
        'icon_e_sad.gif' => ['Sad', ':(', ':-(', ':sad:'],
        'icon_e_surprised.gif' => ['Surprised', ':o', ':-o', ':eek:'],
        'icon_eek.gif' => ['Shocked', ':shock:'],
        'icon_e_confused.gif' => ['Confused', ':?', ':-?', ':???:'],
        'icon_cool.gif' => ['Cool', '8)', '8-)', ':cool:'],
        'icon_lol.gif' => ['Laughing', ':lol:'],
        'icon_mad.gif' => ['Mad', ':x', ':-x', ':mad:'],
        'icon_razz.gif' => ['Razz', ':P', ':-P', ':razz:'],
        'icon_redface.gif' => ['Embarrassed', ':oops:'],
        'icon_cry.gif' => ['Crying or Very sad', ':cry:'],
        'icon_evil.gif' => ['Evil or Very Mad', ':evil:'],
        'icon_twisted.gif' => ['Twisted Evil', ':twisted:'],
        'icon_rolleyes.gif' => ['Rolling Eyes', ':roll:'],
        'icon_e_wink.gif' => ['Wink', ':wink:', ';)', ';-)'],
        'icon_exclaim.gif' => ['Exclamation', ':!:'],
        'icon_question.gif' => ['Question', ':?:'],
        'icon_idea.gif' => ['Idea', ':idea:'],
        'icon_arrow.gif' => ['Arrow', ':arrow:'],
        'icon_neutral.gif' => ['Neutral', ':|', ':-|', ':neutral:'],
        'icon_mrgreen.gif' => ['Mr. Green', ':mrgreen:'],
        // Custom smilies of the old PHP forum (`:name:` for `images/smilies/icon_name.gif`) and the phpBB 3 geeks:
        // phpBB 2 users typed the same codes.
        'icon_e_geek.gif' => ['Geek', ':geek:'],
        'icon_e_ugeek.gif' => ['Uber Geek', ':ugeek:'],
        'icon_casse.gif' => ['Casse', ':casse:'],
        'icon_coucou.gif' => ['Coucou', ':coucou:'],
        'icon_love.gif' => ['Love', ':love:'],
        'icon_mouais.gif' => ['Mouais', ':mouais:'],
        'icon_out.gif' => ['Out', ':out:'],
        'icon_rateau.gif' => ['Rateau', ':rateau:'],
        'icon_siffle.gif' => ['Siffle', ':siffle:'],
        'icon_sleep.gif' => ['Sleep', ':sleep:'],
        'icon_tongue.gif' => ['Tongue', ':tongue:'],
        'icon_up.gif' => ['Up', ':up:'],
        'icon_zen.gif' => ['Zen', ':zen:'],
    ];

    /** @var array<string, array{0: string, 1: string}>|null code => [file, title] */
    private static ?array $codes = null;
    private static ?string $pattern = null;

    public static function image(string $file, string $alt, ?string $title = null): string
    {
        return sprintf(
            '<img src="%s%s" alt="%s"%s>',
            self::URL_PREFIX,
            htmlspecialchars($file, \ENT_QUOTES),
            htmlspecialchars($alt, \ENT_QUOTES),
            null !== $title && '' !== $title ? ' title="'.htmlspecialchars($title, \ENT_QUOTES).'"' : '',
        );
    }

    /**
     * Splits a plain (entity-decoded) text into text parts and smiley parts.
     *
     * @return list<array{0: 'text'|'smiley', 1: string}> text parts carry the text, smiley parts the <img> markup
     */
    public static function split(string $text): array
    {
        $parts = preg_split(self::pattern(), $text, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $codes = self::codes();
        $result = [];
        // With a single capture group the parts alternate: text, code, text, code, ..., text.
        foreach ($parts as $i => $part) {
            if ('' === $part) {
                continue;
            }
            if (1 === $i % 2) {
                [$file, $title] = $codes[$part];
                $result[] = ['smiley', self::image($file, $part, $title)];
            } else {
                $result[] = ['text', $part];
            }
        }

        return $result;
    }

    private static function pattern(): string
    {
        if (null === self::$pattern) {
            $codes = array_keys(self::codes());
            usort($codes, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));
            self::$pattern = '/(?<![\p{L}\p{N}_])('.implode('|', array_map(static fn (string $c): string => preg_quote($c, '/'), $codes)).')(?![\p{L}\p{N}_])/u';
        }

        return self::$pattern;
    }

    /** @return array<string, array{0: string, 1: string}> */
    private static function codes(): array
    {
        if (null === self::$codes) {
            self::$codes = [];
            foreach (self::SMILIES as $file => $entry) {
                $title = array_shift($entry);
                foreach ($entry as $code) {
                    self::$codes[$code] = [$file, $title];
                }
            }
        }

        return self::$codes;
    }
}
