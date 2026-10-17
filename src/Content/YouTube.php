<?php

namespace App\Content;

/**
 * Recognises YouTube URLs and builds the single embed form the forum accepts: the privacy-friendly
 * `https://www.youtube-nocookie.com/embed/<id>` (plus an optional `start` offset in seconds).
 */
final class YouTube
{
    private const ID = '[A-Za-z0-9_-]{11}';
    private const EMBED_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
    private const MAX_URL_LENGTH = 300;
    private const MAX_START = 359999;

    private function __construct()
    {
    }

    /** Canonical embed URL of a video. */
    public static function embedUrl(string $id, int $start = 0): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.$id.($start > 0 ? '?start='.min($start, self::MAX_START) : '');
    }

    /**
     * Normalises the `src` of an `<iframe>`: only `/embed/<id>` URLs of YouTube hosts are accepted, the
     * result is always the canonical embed URL. Returns null for anything else.
     */
    public static function fromEmbedUrl(string $url): ?string
    {
        $parts = self::parse($url);
        if (null === $parts || !\in_array($parts['host'], self::EMBED_HOSTS, true)) {
            return null;
        }
        if (!preg_match('#^/embed/('.self::ID.')/?$#D', $parts['path'], $m) || self::isPlaylist($m[1])) {
            return null;
        }

        return self::embedUrl($m[1], self::seconds($parts['query']['start'] ?? null));
    }

    /**
     * Turns any usual YouTube video URL (watch, youtu.be, shorts, live, embed) into the canonical embed URL.
     * Returns null when the URL is not a YouTube video link.
     */
    public static function fromVideoUrl(string $url): ?string
    {
        $parts = self::parse($url);
        if (null === $parts) {
            return null;
        }

        $id = null;
        if ('youtu.be' === $parts['host']) {
            $id = preg_match('#^/('.self::ID.')/?$#D', $parts['path'], $m) ? $m[1] : null;
        } elseif (\in_array($parts['host'], self::EMBED_HOSTS, true)) {
            if ('/watch' === $parts['path']) {
                $v = $parts['query']['v'] ?? null;
                $id = \is_string($v) && preg_match('#^'.self::ID.'$#D', $v) ? $v : null;
            } else {
                $id = preg_match('#^/(?:embed|shorts|live)/('.self::ID.')/?$#D', $parts['path'], $m) ? $m[1] : null;
            }
        }
        if (null === $id || self::isPlaylist($id)) {
            return null;
        }

        return self::embedUrl($id, self::seconds($parts['query']['start'] ?? $parts['query']['t'] ?? null));
    }

    /** `/embed/videoseries?list=…` (playlist player) has an 11-character "id" too: it is not a video. */
    private static function isPlaylist(string $id): bool
    {
        return 'videoseries' === $id;
    }

    /**
     * @return array{host: string, path: string, query: array<string, mixed>}|null
     */
    private static function parse(string $url): ?array
    {
        $url = trim($url, " \t\n\r\f");
        // Whitespace/control characters and backslashes are parsed differently by browsers and parse_url().
        if ('' === $url || \strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host']) || !\in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        return ['host' => strtolower($parts['host']), 'path' => $parts['path'] ?? '', 'query' => $query];
    }

    /** Accepts `90`, `90s`, `1m30s`, `1h2m3s`. */
    private static function seconds(mixed $value): int
    {
        if (!\is_string($value) || '' === $value) {
            return 0;
        }
        if (ctype_digit($value)) {
            return \strlen($value) <= 6 ? min((int) $value, self::MAX_START) : 0;
        }
        if (!preg_match('/^(?:(\d{1,3})h)?(?:(\d{1,4})m)?(?:(\d{1,6})s)?$/D', $value, $m)) {
            return 0;
        }

        return min(3600 * (int) ($m[1] ?? 0) + 60 * (int) ($m[2] ?? 0) + (int) ($m[3] ?? 0), self::MAX_START);
    }
}
