<?php

namespace App\Tests\Legacy;

use App\Legacy\Text\OldPhpConverter;
use PHPUnit\Framework\TestCase;

/** Samples are real rows of the old PHP forum's `posts.text` / `messages.body` / `quotes.text`. */
final class OldPhpConverterTest extends TestCase
{
    private OldPhpConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new OldPhpConverter();
    }

    public function testFirstGenerationNl2brMojibakeSmiliesAndUidLeftovers(): void
    {
        // post 9 (2007): mojibake, nl2br, BBCode with uid never rendered
        $html = $this->converter->convert('Comme vous avez peut-Ãªtre pu le constater, il existe maintenant une sorte de site:<br />'."\n".'[url:0dd49]http&#58;//comtso2&#46;free&#46;fr/[/url:0dd49]<br />'."\n".'OÃ¹ sous une jolie banniÃ¨re');

        self::assertSame('<p>Comme vous avez peut-être pu le constater, il existe maintenant une sorte de site:<br><a href="http://comtso2.free.fr/">http://comtso2.free.fr/</a><br>Où sous une jolie bannière</p>', $html);
    }

    public function testSmiliesAreRewrittenToThePublicFolder(): void
    {
        // post 3
        $html = $this->converter->convert('Super nouveau forum <img src="images/smilies/icon_e_wink.gif" alt=";)" title="Wink" /><br />'."\n".'On va voir Ã§a. <br />'."\n".'Merci Vincent !');

        self::assertSame('<p>Super nouveau forum <img src="/smilies/icon_e_wink.gif" alt=";)" title="Wink"><br>On va voir ça.<br>Merci Vincent !</p>', $html);
    }

    public function testQuoteLeftoverAndColourOfThePhpBB3Era(): void
    {
        // post 4
        $html = $this->converter->convert("[color=#008040:3bf54]Le fil pour les [b:3bf54]petits bonheurs[/b:3bf54] passagers[/color:3bf54]<br />\n[quote=&quot;gigi&quot;:81487]non mais<br />\nok[/quote:81487]");

        self::assertSame('<p>Le fil pour les <strong>petits bonheurs</strong> passagers</p><blockquote><cite>gigi a écrit :</cite><p>non mais<br>ok</p></blockquote>', $html);
    }

    public function testTinyMceHtmlIsKept(): void
    {
        $html = $this->converter->convert('<p><strong>Surprise !</strong> Un tout nouveau forum tout beau.</p>'."\n".'<p>Donc d&eacute;j&agrave;, avant toute chose</p>');

        self::assertSame('<p><strong>Surprise !</strong> Un tout nouveau forum tout beau.</p>'."\n".'<p>Donc d&eacute;j&agrave;, avant toute chose</p>', $html);
    }

    public function testTinyMceHtmlWithBbcodeRemnantsIsConverted(): void
    {
        // 2009 posts pasted from phpBB
        $html = $this->converter->convert('<p>Avant</p><p>[quote="gigi":abcde]citation[/quote:abcde]</p><p>Après</p>');

        self::assertStringNotContainsString('[quote', $html);
        self::assertStringContainsString('<blockquote><cite>gigi a écrit :</cite><p>citation</p></blockquote>', $html);
        self::assertStringContainsString('Avant', $html);
        self::assertStringContainsString('Après', $html);
    }

    public function testPlainMessageBodiesWithEntities(): void
    {
        // messages.body #100 and quotes.text #5
        self::assertSame(
            '<p>Ouais c\'est ce que ej me suis dit… Nan mais faut pas chercher :p</p>',
            $this->converter->convert('Ouais c&#039;est ce que ej me suis dit&hellip; Nan mais faut pas chercher :p'),
        );
        self::assertSame('<p>Mais PUTAAAAIN, c\'est quoi ce bordel ?</p>', $this->converter->convert('Mais PUTAAAAIN, c&#039;est quoi ce bordel ?'));
    }

    public function testRemoteImageIsKeptAndRelativeImagesAreDropped(): void
    {
        $html = $this->converter->convert('<img src="http://example.com/a.png" alt="" /><br />'."\n".'<img src="avatars/x.png" />');

        self::assertSame('<p><img src="http://example.com/a.png" alt=""></p>', $html);
    }
}
