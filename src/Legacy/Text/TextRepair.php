<?php

namespace App\Legacy\Text;

/**
 * Low-level text clean-ups shared by every legacy converter.
 */
final class TextRepair
{
    /** Windows-1252 characters of the 0x80-0x9F range => their byte (MySQL "latin1" is really cp1252). */
    private const CP1252_REVERSE = [
        0x20AC => 0x80, 0x201A => 0x82, 0x0192 => 0x83, 0x201E => 0x84, 0x2026 => 0x85, 0x2020 => 0x86,
        0x2021 => 0x87, 0x02C6 => 0x88, 0x2030 => 0x89, 0x0160 => 0x8A, 0x2039 => 0x8B, 0x0152 => 0x8C,
        0x017D => 0x8E, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94, 0x2022 => 0x95,
        0x2013 => 0x96, 0x2014 => 0x97, 0x02DC => 0x98, 0x2122 => 0x99, 0x0161 => 0x9A, 0x203A => 0x9B,
        0x0153 => 0x9C, 0x017E => 0x9E, 0x0178 => 0x9F,
    ];

    private static ?string $mojibakePattern = null;

    /**
     * Repairs text that was UTF-8 encoded twice (UTF-8 bytes read as cp1252 and encoded to UTF-8 again, which is
     * what the old PHP forum dump contains: "prÃªt" for "prêt", "â€¦" for "…").
     *
     * Works sequence by sequence so that texts mixing correct and garbled characters are handled, and
     * a sequence that does not decode to valid UTF-8 is left untouched.
     */
    public static function fixMojibake(string $text): string
    {
        if ('' === $text || !preg_match('/[\x{00C2}\x{00C3}\x{00C5}\x{00E0}-\x{00EF}\x{00F0}]/u', $text)) {
            return $text;
        }

        return preg_replace_callback(self::mojibakePattern(), static function (array $m): string {
            $bytes = '';
            $chars = mb_str_split($m[0], 1, 'UTF-8');
            // French typography next to an accented letter ("été\u{00A0}»", "é«\u{00A0}") is valid UTF-8 by accident: E9 A0 BB.
            if (\count($chars) >= 3 && mb_ord($chars[0], 'UTF-8') >= 0xE0 && !array_diff(\array_slice($chars, 1), ["\u{00A0}", '«', '»'])) {
                return $m[0];
            }
            foreach ($chars as $char) {
                $cp = mb_ord($char, 'UTF-8');
                if ($cp < 0x100) {
                    $bytes .= \chr($cp);
                } elseif (isset(self::CP1252_REVERSE[$cp])) {
                    $bytes .= \chr(self::CP1252_REVERSE[$cp]);
                } else {
                    return $m[0];
                }
            }

            return mb_check_encoding($bytes, 'UTF-8') ? $bytes : $m[0];
        }, $text) ?? $text;
    }

    private static function mojibakePattern(): string
    {
        if (null === self::$mojibakePattern) {
            $specials = '';
            foreach (array_keys(self::CP1252_REVERSE) as $cp) {
                $specials .= sprintf('\x{%04X}', $cp);
            }
            $cont = '[\x{0080}-\x{00BF}'.$specials.']';
            // Leads of double-encoded text: Â Ã Å (2-byte sequences: accents, « » ° œ), any E0-EF (3-byte: CJK, … ’ “ ” €)
            // and ð (4-byte: emoji).
            self::$mojibakePattern = '/[\x{00C2}\x{00C3}\x{00C5}]'.$cont.'|[\x{00E0}-\x{00EF}]'.$cont.'{2}|\x{00F0}'.$cont.'{3}/u';
        }

        return self::$mojibakePattern;
    }

    /**
     * Decodes HTML entities (named and numeric) in a single pass. Numeric entities that are not valid text
     * (UTF-16 surrogates such as "&#56319;" left by old browsers, control characters, private use) are dropped.
     */
    public static function decodeEntities(string $text): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }

        return preg_replace_callback('/&(#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);/', static function (array $m): string {
            if ('#' === $m[1][0]) {
                $cp = 'x' === ($m[1][1] ?? '') || 'X' === ($m[1][1] ?? '') ? hexdec(substr($m[1], 2)) : (int) substr($m[1], 1);
                if ($cp < 0x20 && !\in_array($cp, [0x09, 0x0A, 0x0D], true)
                    || ($cp >= 0xD800 && $cp <= 0xDFFF)
                    || ($cp >= 0x7F && $cp <= 0x9F)
                    || $cp > 0x10FFFF || ($cp >= 0xE000 && $cp <= 0xF8FF) || ($cp >= 0xFFF0 && $cp <= 0xFFFF)
                    || $cp >= 0xF0000
                ) {
                    return '';
                }

                return mb_chr((int) $cp, 'UTF-8') ?: '';
            }

            return html_entity_decode($m[0], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }, $text) ?? $text;
    }

    /** Removes control characters and unpaired UTF-8 junk that cannot be stored or displayed. */
    public static function cleanControlChars(string $text): string
    {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFD}]/u', '', $text) ?? $text;
    }
}
