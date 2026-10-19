<?php

namespace App\Tests\Legacy;

use App\Legacy\Import\IndexMatch;
use App\Legacy\Import\LiveIndex;
use App\Legacy\Text\TextNormalizer;
use PHPUnit\Framework\TestCase;

final class TextNormalizerTest extends TestCase
{
    public function testSameMessageInEveryRepresentationHasTheSameFingerprint(): void
    {
        // phpBB 3 rendering (this command), the live row written by the previous migration, and the raw old PHP forum row.
        $converted = '<p>Remarque, la dernière fois qu\'il s\'est frotté aux gens d\'en bas <img src="/smilies/icon_mrgreen.gif" alt=":mrgreen:" title="Mr. Green"><br><a href="http://youtube.com/watch?v=Luufip9LgvI">(vous vous rappellez ?)</a></p>';
        $live = 'Remarque, la dernière fois qu’il s’est frotté aux gens d’en bas <img src="/smilies/icon_mrgreen.gif" alt=":mrgreen:" title="Mr. Green" /><br /><br /><a href="http://youtube.com/watch?v=Luufip9LgvI" target="_blank">http://youtube.com/watch?v=Luufip9LgvI</a>';
        $raw = 'Remarque, la derniÃ¨re fois qu\'il s\'est frottÃ© aux gens d\'en bas <img src="images/smilies/icon_mrgreen.gif" alt=":mrgreen:" title="Mr. Green" /><br />'."\n".'[url=http&#58;//youtube&#46;com/watch?v=Luufip9LgvI:1r1fa2i6](vous vous rappellez ?)[/url:1r1fa2i6]';

        self::assertSame(TextNormalizer::fingerprint($live), TextNormalizer::fingerprint($converted));
        self::assertSame(TextNormalizer::fingerprint($live), TextNormalizer::fingerprint($raw));
    }

    public function testNormalisationIgnoresCaseTypographyEntitiesAndWhitespace(): void
    {
        self::assertSame(
            TextNormalizer::fingerprint("<p>L’été   “EST” là…</p>"),
            TextNormalizer::fingerprint("l&#039;&eacute;t&eacute; \"est\" LÀ...\n"),
        );
        self::assertSame(TextNormalizer::fingerprint('a &amp;amp; b'), TextNormalizer::fingerprint('<p>a & b</p>'));
    }

    public function testDifferentMessagesDiffer(): void
    {
        self::assertNotSame(TextNormalizer::fingerprint('Salut Léa'), TextNormalizer::fingerprint('Salut Lea'));
        self::assertNotSame(TextNormalizer::fingerprint('oui'), TextNormalizer::fingerprint('non'));
    }

    public function testInvalidImagePlaceholderOfThePreviousMigrationIsIgnored(): void
    {
        self::assertSame(
            TextNormalizer::fingerprint('Hehe<br /><br />(Invalid img)'),
            TextNormalizer::fingerprint("Hehe\n[img:abcde]http://example.com/x.jpg[/img:abcde]"),
        );
    }

    public function testAttachmentMarkersInEveryFormAreIgnored(): void
    {
        $fingerprint = TextNormalizer::fingerprint('<p>Sans commentaire.</p>');

        foreach ([
            'Sans commentaire.<br />[attachment=1]Photo 9.jpg[/attachment]',
            '<p>Sans commentaire.</p><p><em>[pièce jointe : Photo 9.jpg]</em></p>',
            'Sans commentaire.[attachment=0:cc8e2]<!-- ia0 -->Photo 9.jpg<!-- ia0 -->[/attachment:cc8e2]',
            'Sans commentaire. <em>[pièce jointe perdue : Photo 9.jpg]</em>',
        ] as $content) {
            self::assertSame($fingerprint, TextNormalizer::fingerprint($content), $content);
        }
    }

    public function testYoutubeLinksAreComparedByVideo(): void
    {
        self::assertSame(
            TextNormalizer::fingerprint('<a href="http://www.youtube.com/watch?v=VzYCs_ZZR08">http://www.youtube.com/watch?v=VzYCs_ZZR08</a> top'),
            TextNormalizer::fingerprint('<iframe src="https://www.youtube-nocookie.com/embed/VzYCs_ZZR08"></iframe><p>top</p>'),
        );
    }

    public function testPhotoLinksOfEveryGenerationAreEquivalent(): void
    {
        self::assertSame(
            TextNormalizer::fingerprint('<a href="image.php?photo=610.jpg"><img src="image.php?thumb=610.jpg"></a>'),
            TextNormalizer::fingerprint('<a href="/photos/preview/610.jpg"><img src="/photos/thumbnail/610.jpg"></a>'),
        );
    }

    public function testQuotesAreIgnoredByTheOwnTextFingerprint(): void
    {
        $source = '<blockquote><cite>gigi a écrit :</cite><p>citation</p></blockquote><p>ma réponse bien longue</p>';
        $live = '<blockquote>    <p>citation</p>      Citation de &amp;amp;quot;gigi&amp;amp;quot;     </blockquote><br /><br />ma réponse bien longue';

        self::assertSame(TextNormalizer::ownFingerprint($live), TextNormalizer::ownFingerprint($source));
        self::assertSame('maréponsebienlongue|', TextNormalizer::ownFingerprint($source), 'the quoted part is left out');
        self::assertStringContainsString('citation', TextNormalizer::fingerprint($source));
    }

    public function testQuoteHeadersOfBothRenderingsAreIgnored(): void
    {
        $converted = '<blockquote><cite>Vincent a écrit :</cite><p>Elle a pas dit ça exprès mon doudou ! Non non non...</p></blockquote><p>??</p>';
        $live = '<blockquote>    <p>Elle a pas dit ça exprès mon doudou ! Non non non…</p>                                         Citation de &amp;amp;quot;Vincent&amp;amp;quot;                    </blockquote><br /><br /><br /><br />??';

        self::assertSame(TextNormalizer::fingerprint($live), TextNormalizer::fingerprint($converted));
    }

    public function testNoBreakSpaceBeforeClosingGuillemetDoesNotChangeTheText(): void
    {
        self::assertSame(
            TextNormalizer::fingerprint('<p>&#34;coMté&#34; et non pas &#34;coNté&#34;</p>'),
            TextNormalizer::fingerprint("<p>« coMté\u{00A0}» et non pas « coNté\u{00A0}»</p>"),
        );
    }

    public function testSimilarityToleratesALinkCutInTwoByThePreviousMigration(): void
    {
        $mine = TextNormalizer::fingerprint('<a href="http://www.ecrans.fr/Jouer-pour-la-science,4152.html">http://www.ecrans.fr/Jouer-pour-la-science,4152.html</a> :D');
        $live = TextNormalizer::fingerprint('<a href="http://www.ecrans.fr/Jouer-pour-la-science">http://www.ecrans.fr/Jouer-pour-la-science</a>,4152.html :D');

        self::assertGreaterThanOrEqual(0.85, TextNormalizer::similarity($mine, $live));
    }

    public function testLiveIndexMatchesWithinTheToleranceOnly(): void
    {
        $index = new LiveIndex(300);
        $index->add('c', 10, 1, 5, 1_000_000, 'Un message court');

        self::assertNull($index->claim('c', 1_000_000 + 3600, '<p>Un message court</p>', 5), 'one hour later is another message');
        $match = $index->claim('c', 1_000_000 + 2, '<p>Un message court</p>', 5);
        self::assertNotNull($match);
        self::assertSame(10, $match->id);
        self::assertSame(IndexMatch::EXACT, $match->kind);
    }

    public function testALiveRowIsClaimedOnceAndClaimsResetPerSource(): void
    {
        $index = new LiveIndex(300);
        $index->add('c', 10, 1, 5, 1_000_000, 'Un message assez long pour être unique');

        self::assertNotNull($index->claim('c', 1_000_000, 'Un message assez long pour être unique', 5));
        self::assertNull($index->claim('c', 1_000_000, 'Un message assez long pour être unique', 5), 'a double post needs two live rows');
        $index->resetClaims();
        self::assertNotNull($index->claim('c', 1_000_000, 'Un message assez long pour être unique', 5), 'another platform may match the same live row');
    }

    public function testRowsImportedDuringARunAreOnlyMatchedByTheNextSource(): void
    {
        $index = new LiveIndex(300);
        $index->add('c', -1, 1, 5, 1_000_000, 'Un message assez long pour être unique', true);

        self::assertNull($index->claim('c', 1_000_000, 'Un message assez long pour être unique', 5));
        $index->resetClaims();
        self::assertNotNull($index->claim('c', 1_000_000, 'Un message assez long pour être unique', 5));
    }

    public function testShortMessagesNeedTheSameAuthor(): void
    {
        $index = new LiveIndex(300);
        $index->add('c', 10, 1, 5, 1_000_000, 'lol');

        self::assertNull($index->claim('c', 1_000_000, 'lol', 6));
        self::assertNotNull($index->claim('c', 1_000_000, 'lol', 5));
    }

    public function testNearDuplicateAndTimeShiftAreFlaggedForReview(): void
    {
        $index = new LiveIndex(300);
        $index->add('c', 10, 1, 5, 1_000_000, 'Ceci est un message de test suffisamment long pour la comparaison floue entre deux versions');
        $near = $index->claim('c', 1_000_000, 'Ceci est un message de test suffisamment long pour la comparaison floue entre deux version', 5);
        self::assertSame(IndexMatch::NEAR, $near?->kind);

        $index->add('c', 11, 1, 5, 2_000_000, 'Un autre message tout aussi long pour verifier le decalage horaire eventuel');
        $shifted = $index->claim('c', 2_000_000 - 3600, 'Un autre message tout aussi long pour verifier le decalage horaire eventuel', 5);
        self::assertSame(IndexMatch::SHIFTED, $shifted?->kind);
        self::assertTrue($shifted->isSuspicious());
    }

    public function testGroupsKeepConversationsApart(): void
    {
        $index = new LiveIndex(60);
        $index->add('m:5>6', 1, null, null, 1_000_000, 'Ceci est un message rapide, vous pouvez envoyer ce type de message');

        self::assertNull($index->claim('m:5>7', 1_000_000, 'Ceci est un message rapide, vous pouvez envoyer ce type de message', null));
        self::assertNotNull($index->claim('m:5>6', 1_000_000, 'Ceci est un message rapide, vous pouvez envoyer ce type de message', null));
    }
}
