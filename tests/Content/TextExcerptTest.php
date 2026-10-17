<?php

namespace App\Tests\Content;

use App\Content\TextExcerpt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TextExcerptTest extends TestCase
{
    /** @return iterable<string, array{?string, string}> */
    public static function plainTextProvider(): iterable
    {
        yield 'null' => [null, ''];
        yield 'blank' => ["  \n ", ''];
        yield 'plain text' => ['Bonjour   tout le monde', 'Bonjour tout le monde'];
        yield 'inline markup is dropped' => ['<p>Un <strong>gros</strong> <a href="https://example.test">lien</a></p>', 'Un gros lien'];
        yield 'block ends and line breaks are spaces' => ['<p>Un</p><p>deux<br>trois<br/>quatre</p><ul><li>cinq</li><li>six</li></ul>', 'Un deux trois quatre cinq six'];
        yield 'entities are decoded once' => ['<p>Tom &amp; Jerry &lt;3 &eacute;t&eacute; &quot;ok&quot;</p>', 'Tom & Jerry <3 été "ok"'];
        yield 'double encoded entities stay as typed' => ['<p>&amp;lt;b&amp;gt;</p>', '&lt;b&gt;'];
        yield 'non-breaking spaces are collapsed' => ["<p>a&nbsp;&nbsp;b\u{00A0}c&#160;d</p>", 'a b c d'];
        yield 'smilies and images contribute nothing' => ['<p>Salut <img src="/smilies/smile.gif" alt=":)"> toi<img src="https://example.test/a.jpg"></p>', 'Salut toi'];
        yield 'only an image' => ['<p><img src="https://example.test/a.jpg" alt="vacances"></p>', ''];
        yield 'iframes (videos) are dropped with their content' => ['<p>Regardez</p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ">fallback</iframe><p>ça</p>', 'Regardez ça'];
        yield 'scripts and styles are dropped' => ['<p>ok</p><script>alert(1)</script><style>p{color:red}</style>', 'ok'];
        yield 'quoted messages are skipped' => ['<blockquote><p>message cité</p></blockquote><p>ma réponse</p>', 'ma réponse'];
        yield 'nested quotes are skipped' => ['<blockquote>un<blockquote>deux</blockquote>trois</blockquote><p>réponse</p>', 'réponse'];
        yield 'a message that only quotes falls back to the quote' => ['<blockquote><p>seulement une citation</p></blockquote>', 'seulement une citation'];
        yield 'escaped markup is text, not markup' => ['<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', '<script>alert(1)</script>'];
        yield 'broken html' => ['<p>ouvert <b>gras <i>italique', 'ouvert gras italique'];
    }

    #[DataProvider('plainTextProvider')]
    public function testPlainText(?string $html, string $expected): void
    {
        self::assertSame($expected, (new TextExcerpt())->plain($html));
    }

    public function testShortTextIsNotCut(): void
    {
        self::assertSame('Court et bon', (new TextExcerpt())->excerpt('<p>Court et bon</p>', 220));
        self::assertSame('Pile poil', (new TextExcerpt())->excerpt('Pile poil', 9), 'A text of exactly the limit is kept whole.');
    }

    public function testLongTextIsCutAtAWordBoundaryWithAnEllipsis(): void
    {
        $excerpt = (new TextExcerpt())->excerpt('<p>Les sanglots longs des violons de l\'automne blessent mon coeur d\'une langueur monotone.</p>', 40);

        self::assertSame('Les sanglots longs des violons de…', $excerpt);
    }

    public function testCutRightAfterAWordKeepsTheWord(): void
    {
        self::assertSame('Un deux trois…', (new TextExcerpt())->excerpt('Un deux trois quatre cinq', 14));
    }

    public function testTrailingPunctuationIsNotLeftBeforeTheEllipsis(): void
    {
        self::assertSame('Bonjour à tous…', (new TextExcerpt())->excerpt('Bonjour à tous, et bienvenue ici', 17));
    }

    public function testCutIsMultibyteSafe(): void
    {
        $text = str_repeat('éléphant ', 30);
        $excerpt = (new TextExcerpt())->excerpt($text, 25);

        self::assertSame('éléphant éléphant…', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }

    public function testAWordLongerThanTheLimitIsCutHard(): void
    {
        $excerpt = (new TextExcerpt())->excerpt('https://example.test/'.str_repeat('é', 100), 30);

        self::assertSame(30, mb_strlen($excerpt) - 1);
        self::assertStringEndsWith('éé…', $excerpt);
        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
    }

    public function testMarkupNeverLeaksIntoAnExcerpt(): void
    {
        $html = '<p onclick="x()">Texte <b>gras</b></p><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">piège</a><script>alert(2)</script>';

        $excerpt = (new TextExcerpt())->excerpt($html, 220);

        self::assertSame('Texte gras piège', $excerpt);
        self::assertStringNotContainsString('<', $excerpt);
    }
}
