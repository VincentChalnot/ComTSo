<?php

namespace App\Tests\Content;

use App\Content\YouTube;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class YouTubeTest extends TestCase
{
    private const ID = 'dQw4w9WgXcQ';

    /** @return iterable<string, array{string, ?string}> */
    public static function videoUrlProvider(): iterable
    {
        $embed = 'https://www.youtube-nocookie.com/embed/'.self::ID;
        yield 'watch' => ['https://www.youtube.com/watch?v='.self::ID, $embed];
        yield 'watch without www and scheme-less case' => ['HTTPS://YOUTUBE.COM/watch?v='.self::ID, $embed];
        yield 'surrounding whitespace' => ["  https://youtu.be/".self::ID." \n", $embed];
        yield 'seconds' => ['https://youtu.be/'.self::ID.'?t=90', $embed.'?start=90'];
        yield 'seconds suffix' => ['https://youtu.be/'.self::ID.'?t=90s', $embed.'?start=90'];
        yield 'minutes and seconds' => ['https://www.youtube.com/watch?v='.self::ID.'&t=1m30s', $embed.'?start=90'];
        yield 'hours' => ['https://www.youtube.com/watch?v='.self::ID.'&t=1h2m3s', $embed.'?start=3723'];
        yield 'start wins' => ['https://www.youtube.com/watch?v='.self::ID.'&start=5&t=60', $embed.'?start=5'];
        yield 'zero start dropped' => ['https://youtu.be/'.self::ID.'?t=0', $embed];
        yield 'absurd start capped' => ['https://youtu.be/'.self::ID.'?t=999999', $embed.'?start=359999'];
        yield 'start too long to be real' => ['https://youtu.be/'.self::ID.'?t=99999999999999999999', $embed];
        yield 'garbage start' => ['https://youtu.be/'.self::ID.'?t=abc', $embed];
        yield 'shorts' => ['https://www.youtube.com/shorts/'.self::ID, $embed];
        yield 'live' => ['https://www.youtube.com/live/'.self::ID, $embed];
        yield 'embed' => ['//www.youtube.com/embed/'.self::ID, $embed];
        yield 'watch without v' => ['https://www.youtube.com/watch', null];
        yield 'v as array' => ['https://www.youtube.com/watch?v[]='.self::ID, null];
        yield 'playlist player' => ['https://www.youtube.com/embed/videoseries?list=PLabc', null];
        yield 'youtu.be path with extras' => ['https://youtu.be/'.self::ID.'/extra', null];
        yield 'ftp' => ['ftp://youtube.com/watch?v='.self::ID, null];
        yield 'credentials' => ['https://user:pass@www.youtube.com/watch?v='.self::ID, null];
        yield 'space inside' => ['https://www.youtube.com/watch?v='.self::ID.' x', null];
        yield 'too long' => ['https://www.youtube.com/watch?v='.self::ID.'&x='.str_repeat('a', 300), null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('videoUrlProvider')]
    public function testVideoUrlsBecomeTheCanonicalEmbed(string $url, ?string $expected): void
    {
        self::assertSame($expected, YouTube::fromVideoUrl($url));
    }

    public function testAnIframeSourceMustBeAnEmbedUrl(): void
    {
        self::assertSame('https://www.youtube-nocookie.com/embed/'.self::ID, YouTube::fromEmbedUrl('//youtube.com/embed/'.self::ID.'?rel=0'));
        // A watch URL is fine as a pasted link but is not a valid iframe source.
        self::assertNull(YouTube::fromEmbedUrl('https://www.youtube.com/watch?v='.self::ID));
        self::assertNull(YouTube::fromEmbedUrl('https://youtu.be/'.self::ID));
    }
}
