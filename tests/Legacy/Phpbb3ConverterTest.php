<?php

namespace App\Tests\Legacy;

use App\Legacy\Text\Phpbb3Converter;
use PHPUnit\Framework\TestCase;

/** Samples are real rows of `phpbb3_posts` / `phpbb3_privmsgs` of the PostgreSQL dump. */
final class Phpbb3ConverterTest extends TestCase
{
    private Phpbb3Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Phpbb3Converter();
    }

    public function testRenderedSmiliesAndPlainText(): void
    {
        // post 3
        $html = $this->converter->convert("YOUHOU !!!!!!!!!!!!\nSuper nouveau forum, l'a l'air tout beau <!-- s;) --><img src=\"{SMILIES_PATH}/icon_e_wink.gif\" alt=\";)\" title=\"Wink\" /><!-- s;) -->\nOn va voir ça.", '2d1ea');

        self::assertSame(
            '<p>YOUHOU !!!!!!!!!!!!<br>Super nouveau forum, l\'a l\'air tout beau <img src="/smilies/icon_e_wink.gif" alt=";)" title="Wink"><br>On va voir ça.</p>',
            $html,
        );
    }

    public function testBbcodeWithUidColourAndEncodedUrl(): void
    {
        // post 4 and post 9
        $html = $this->converter->convert('[color=#008040:3bf54]Le fil pour les [b:3bf54]petits bonheurs[/b:3bf54][/color:3bf54]', '3bf54');
        self::assertSame('<p>Le fil pour les <strong>petits bonheurs</strong></p>', $html);

        $html = $this->converter->convert("[url:0dd49]http&#58;//comtso2&#46;free&#46;fr/[/url:0dd49]\nOù sous une jolie bannière", '0dd49');
        self::assertSame('<p><a href="http://comtso2.free.fr/">http://comtso2.free.fr/</a><br>Où sous une jolie bannière</p>', $html);
    }

    public function testQuoteWithEncodedQuotesInTheHeader(): void
    {
        // post 717 and private message 7
        $html = $this->converter->convert("[quote=&quot;Vincent&quot;:70606]Elle a pas dit ça exprès mon doudou ! Non non non...[/quote:70606]\n\n??", '70606');

        self::assertSame('<blockquote><cite>Vincent a écrit :</cite><p>Elle a pas dit ça exprès mon doudou ! Non non non...</p></blockquote><p>??</p>', $html);
    }

    public function testMagicLinkMarkup(): void
    {
        $html = $this->converter->convert('voir <!-- m --><a class="postlink" href="http://www.youtube.com/watch?v=VzYCs_ZZR08">http://www.youtube.com/watch?v=VzYCs_ZZR08</a><!-- m -->'."\n\n".'<!-- m --><a class="postlink" href="http://example.com/x">http://example.com/x</a><!-- m -->', 'abcde');

        // A link inside a sentence stays a link, a link alone on its line is embedded.
        self::assertStringContainsString('<p>voir <a href="http://www.youtube.com/watch?v=VzYCs_ZZR08">http://www.youtube.com/watch?v=VzYCs_ZZR08</a></p>', $html);
        self::assertStringContainsString('<p><a href="http://example.com/x">http://example.com/x</a></p>', $html);
    }

    public function testLinkAloneOnItsLineIsEmbeddedWhenYoutube(): void
    {
        // post 400
        $html = $this->converter->convert('<!-- m --><a class="postlink" href="http://www.youtube.com/watch?v=VzYCs_ZZR08">http://www.youtube.com/watch?v=VzYCs_ZZR08</a><!-- m -->'."\nJuste parfait.", '');

        self::assertSame('<div class="youtube-embed"><iframe src="https://www.youtube-nocookie.com/embed/VzYCs_ZZR08" width="560" height="315" frameborder="0" allowfullscreen></iframe></div><p>Juste parfait.</p>', $html);
    }

    public function testCustomStrikeBbcodeAndEncodedParentheses(): void
    {
        // bbcodes 13 and 14 of the board: [s] and [strike]
        $html = $this->converter->convert('[s:abc12]barré &#40;vraiment&#41;[/s:abc12] [strike:abc12]aussi[/strike:abc12]', 'abc12');

        self::assertSame('<p><del>barré (vraiment)</del> <del>aussi</del></p>', $html);
    }

    public function testCodeBlockKeepsEntitiesDecoded(): void
    {
        // post 2135
        $html = $this->converter->convert("[code:6e96e]#include &lt;connard&#46;h&gt;\nint main()\n[/code:6e96e]", '6e96e');

        self::assertSame("<pre><code>#include <connard.h>\nint main()</code></pre>", str_replace(['&lt;', '&gt;'], ['<', '>'], $html));
        self::assertStringContainsString('&lt;connard.h&gt;', $html);
    }

    public function testAttachmentLeavesATraceInsteadOfBbcode(): void
    {
        // post 350: the whole post is an attachment tag wrapped in HTML comments
        $html = $this->converter->convert('[attachment=0:cc8e2]<!-- ia0 -->le manoir.jpg<!-- ia0 -->[/attachment:cc8e2]', 'cc8e2');

        self::assertSame('<p><em>[pièce jointe : le manoir.jpg]</em></p>', $html);
    }

    public function testUnbalancedAndStrayTagsNeverLeakBbcode(): void
    {
        $html = $this->converter->convert('[b:abcde]ouvert sans fin [/i:abcde] et [/quote:abcde] fin', 'abcde');

        self::assertSame('<p><strong>ouvert sans fin  et  fin</strong></p>', $html);
    }

    public function testUidsWithLettersAreDiscoveredWithoutTheStoredUid(): void
    {
        // The old PHP forum lost the uid column: uids are inferred from the closing tags.
        $html = $this->converter->convert('[b:2m5fdkog]x[/b:2m5fdkog]', '');

        self::assertSame('<p><strong>x</strong></p>', $html);
    }
}
