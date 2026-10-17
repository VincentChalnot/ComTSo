<?php

namespace App\Tests\Legacy;

use App\Legacy\Text\Phpbb2Converter;
use PHPUnit\Framework\TestCase;

/** Samples are real rows of `phpbb_posts_text` / `phpbb_privmsgs_text` of the phpBB 2 dump. */
final class Phpbb2ConverterTest extends TestCase
{
    private Phpbb2Converter $converter;

    protected function setUp(): void
    {
        $this->converter = new Phpbb2Converter();
    }

    public function testPlainTextWithSmiliesBecomesParagraphs(): void
    {
        // post 18
        $html = $this->converter->convert("Ben voilà, j'inaugure la salle à tous le monde...\nC'est génial ici on s'amuse bien ! (hem...)\nAllez ! Venez quoi !\nMe laissez pas tout seul ! :(", 'aad459b705');

        self::assertSame(
            '<p>Ben voilà, j\'inaugure la salle à tous le monde...<br>C\'est génial ici on s\'amuse bien ! (hem...)<br>Allez ! Venez quoi !<br>Me laissez pas tout seul ! <img src="/smilies/icon_e_sad.gif" alt=":(" title="Sad"></p>',
            $html,
        );
    }

    public function testQuoteWithUidAndAuthorBecomesBlockquoteWithCite(): void
    {
        // post 205
        $html = $this->converter->convert("[quote:529f7b77c5=\"nounou !\"]ralala ! mes pauvres petits !\nou vais-je aller ?? :oops: \n\n...[/quote:529f7b77c5]\n\nC'est marrant de voir les anciens messages !!!\nlol", '529f7b77c5');

        self::assertStringStartsWith('<blockquote><cite>nounou ! a écrit :</cite><p>ralala ! mes pauvres petits !<br>ou vais-je aller ?? <img src="/smilies/icon_redface.gif" alt=":oops:" title="Embarrassed"></p><p>...</p></blockquote>', $html);
        self::assertStringEndsWith('<p>C\'est marrant de voir les anciens messages !!!<br>lol</p>', $html);
        self::assertStringNotContainsString('[quote', $html);
    }

    public function testQuoteWithoutAuthorAndImage(): void
    {
        // post 27
        $html = $this->converter->convert("[quote:68ac1c6405]\nNan, on va te laisser tout seul !\n\nBisous\n[/quote:68ac1c6405]\n\n[img:68ac1c6405]http://v.picture.free.fr/ressources/fight.jpg[/img:68ac1c6405]\n\nTu peux lui rajouter des dents... :lol:", '68ac1c6405');

        self::assertSame(
            '<blockquote><p>Nan, on va te laisser tout seul !</p><p>Bisous</p></blockquote><p><img src="http://v.picture.free.fr/ressources/fight.jpg" alt=""></p><p>Tu peux lui rajouter des dents... <img src="/smilies/icon_lol.gif" alt=":lol:" title="Laughing"></p>',
            $html,
        );
    }

    public function testFormattingIsKeptOrDropped(): void
    {
        $html = $this->converter->convert('[b:u1u1u1u1u1]gras[/b:u1u1u1u1u1] [i:u1u1u1u1u1]ital[/i:u1u1u1u1u1] [color=red:u1u1u1u1u1][size=18:u1u1u1u1u1]grand[/size:u1u1u1u1u1][/color:u1u1u1u1u1] [u:u1u1u1u1u1]sou[/u:u1u1u1u1u1]', 'u1u1u1u1u1');

        self::assertSame('<p><strong>gras</strong> <em>ital</em> grand <u>sou</u></p>', $html);
    }

    public function testEntitiesOfLatin1Columns(): void
    {
        // phpBB 2 stored characters outside latin1 as numeric entities; some surrogates are pure junk.
        $html = $this->converter->convert('Bon&#8230; l&#8217;ami &amp; co &gt; x &#56319;&#57065; fin', '');

        self::assertSame('<p>Bon… l’ami &amp; co &gt; x  fin</p>', $html);
    }

    public function testLinksBareAndLabelled(): void
    {
        $html = $this->converter->convert("[url=http://www.youtube.com]Youutuuuuuube !!!![/url] et http://www.example.com/a?b=1&amp;c=2.", '');

        self::assertSame('<p><a href="http://www.youtube.com">Youutuuuuuube !!!!</a> et <a href="http://www.example.com/a?b=1&amp;c=2">http://www.example.com/a?b=1&amp;c=2</a>.</p>', $html);
    }

    public function testBareYoutubeLinkOnItsOwnLineBecomesAnEmbed(): void
    {
        // post with "http://www.youtube.com/watch?v=zLSNLvnYl3Y\n\nCe mec est tout simplement magique  :lol:"
        $html = $this->converter->convert("http://www.youtube.com/watch?v=zLSNLvnYl3Y\n\nCe mec est tout simplement magique  :lol:", '');

        self::assertStringStartsWith('<div class="youtube-embed"><iframe src="https://www.youtube-nocookie.com/embed/zLSNLvnYl3Y"', $html);
        self::assertStringContainsString('<p>Ce mec est tout simplement magique', $html);
    }

    public function testYoutubeLinkInASentenceStaysALink(): void
    {
        $html = $this->converter->convert('et hop : [url=http://www.youtube.com/watch?v=qxH2lFI629Y]un clip qu\'il est vraiment laid[/url] bref', '');

        self::assertStringNotContainsString('iframe', $html);
        self::assertStringContainsString('<a href="http://www.youtube.com/watch?v=qxH2lFI629Y">un clip qu\'il est vraiment laid</a>', $html);
    }

    public function testCodeBlockAndNestingCounterOfPhpBB2(): void
    {
        // post 30: phpBB 2 writes [code:1:uid]
        $html = $this->converter->convert("lorsque ax+b &gt;= 12\n[code:1:abcdef0123]\nPush eax\na+1\n[/code:1:abcdef0123]\nLa pile a", 'abcdef0123');

        self::assertSame('<p>lorsque ax+b &gt;= 12</p><pre><code>Push eax'."\n".'a+1</code></pre><p>La pile a</p>', $html);
    }

    public function testRawHtmlIsKeptWhenAllowedByThePost(): void
    {
        // post 3409 had enable_html
        $html = $this->converter->convert('voilà le test : <a href="http://www.humainavendre.com" title="Combien ?">Je vaux 6 144 450 € !</a>', '', true, true, true);

        self::assertSame('<p>voilà le test : <a href="http://www.humainavendre.com" title="Combien ?">Je vaux 6 144 450 € !</a></p>', $html);
    }

    public function testBracketsThatAreNotBbcodeStayLiteral(): void
    {
        $html = $this->converter->convert('[edit] j\'ai corrigé [virgule] et [i am here', '');

        self::assertSame('<p>[edit] j\'ai corrigé [virgule] et [i am here</p>', $html);
    }

    public function testSmileyCodesInsideWordsAreNotConverted(): void
    {
        $html = $this->converter->convert('url:Dommage 8) ok:)x', '');

        self::assertSame('<p>url:Dommage <img src="/smilies/icon_cool.gif" alt="8)" title="Cool"> ok:)x</p>', $html);
    }

    public function testCustomSmiliesOfTheOldForumAreRecognised(): void
    {
        $html = $this->converter->convert('je t\'aime :love: et :siffle:', '');

        self::assertSame('<p>je t\'aime <img src="/smilies/icon_love.gif" alt=":love:" title="Love"> et <img src="/smilies/icon_siffle.gif" alt=":siffle:" title="Siffle"></p>', $html);
    }

    public function testListsAreRendered(): void
    {
        $html = $this->converter->convert("Courses:\n[list:u1u1u1u1u1]\n[*:u1u1u1u1u1]pain[/*:m:u1u1u1u1u1]\n[*:u1u1u1u1u1]lait[/*:m:u1u1u1u1u1]\n[/list:u1u1u1u1u1]", 'u1u1u1u1u1');

        self::assertSame('<p>Courses:</p><ul><li>pain</li><li>lait</li></ul>', $html);
    }
}
