<?php

namespace App\Tests\Legacy;

use App\Legacy\Text\TextRepair;
use PHPUnit\Framework\TestCase;

final class TextRepairTest extends TestCase
{
    public function testMojibakeOfTheOldPhpForumDumpIsRepaired(): void
    {
        // Real samples of `posts.text` (old PHP forum, 2007): UTF-8 stored as cp1252 and encoded again.
        self::assertSame('Le nouveau forum est prêt !', TextRepair::fixMojibake('Le nouveau forum est prÃªt !'));
        self::assertSame('Vous remarquerez … la possibilité', TextRepair::fixMojibake('Vous remarquerez â€¦ la possibilitÃ©'));
        self::assertSame('chuis désolée', TextRepair::fixMojibake('chuis dÃ©solÃ©e'));
        // Chinese quote of 2007-10-22 (live comment 839), cp1252-garbled: 3-byte sequences with leads other than â
        self::assertSame('我餓', TextRepair::fixMojibake('æˆ‘é¤“'));
        // ... including the sequences that are made of Latin-1 characters only (live comment 842)
        self::assertSame('誰屬於', TextRepair::fixMojibake('èª°å±¬æ–¼'));
    }

    public function testCorrectTextAndMixedTextAreLeftAlone(): void
    {
        self::assertSame('Déjà vu à Besançon', TextRepair::fixMojibake('Déjà vu à Besançon'));
        self::assertSame('ÂME et Ãa', TextRepair::fixMojibake('ÂME et Ãa'), 'sequences that are not valid UTF-8 once re-read are not touched');
        // A text mixing a garbled word and a correct one is repaired word by word.
        self::assertSame('prêt et déjà', TextRepair::fixMojibake('prÃªt et déjà'));
    }

    public function testFrenchTypographyWithNoBreakSpaceIsNotMistakenForMojibake(): void
    {
        // Live comment 7751: "é" + U+00A0 + "»" are the bytes E9 A0 BB, valid UTF-8 for an unrelated CJK character.
        $text = "« coMté\u{00A0}» et non pas « coNté\u{00A0}»";

        self::assertSame($text, TextRepair::fixMojibake($text));
    }

    public function testEntitiesAreDecodedAndJunkEntitiesDropped(): void
    {
        self::assertSame('l\'été … & <b>', TextRepair::decodeEntities('l&#039;&eacute;t&eacute; &#8230; &amp; &lt;b&gt;'));
        self::assertSame(':', TextRepair::decodeEntities('&#58;'));
        self::assertSame('a', TextRepair::decodeEntities('a&#56319;&#57065;'), 'UTF-16 surrogates left by old browsers are dropped');
        self::assertSame('&unknown;', TextRepair::decodeEntities('&unknown;'));
    }
}
