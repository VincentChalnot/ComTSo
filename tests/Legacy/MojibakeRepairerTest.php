<?php

namespace App\Tests\Legacy;

use App\Entity\Comment;
use App\Entity\Topic;
use App\Legacy\Import\MojibakeRepairer;
use App\Tests\Support\AppWebTestCase;

/** The Symfony 2 migration left double-encoded UTF-8 in a few rows: the import repairs them, and only them. */
final class MojibakeRepairerTest extends AppWebTestCase
{
    private function text(string $table, int $id, string $column = 'content'): string
    {
        return (string) $this->em()->getConnection()->fetchOne("SELECT $column FROM $table WHERE id = ?", [$id]);
    }

    public function testRepairsDoubleEncodedRowsAndLeavesCleanOnesAlone(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, "No\u{C3}\u{AB}l !"); // "NoÃ«l !" is the double-encoded "Noël !"
        $garbled = $this->createComment($topic, $alice, "<p>Rah,\u{C2}\u{A0}j\u{E2}\u{20AC}\u{2122}aurais, pr\u{C3}\u{AA}t</p>"); // NBSP, ’ and ê
        $clean = $this->createComment($topic, $alice, '<p>Déjà propre « comme ça » ! Ça c’est du français.</p>');
        $typography = $this->createComment($topic, $alice, "« C'était l'été\u{A0}» dit-il"); // "é NBSP »" is valid UTF-8 by accident: must stay
        $beforeUpdated = $this->text('ctso_comment', $garbled->getId(), 'updated_at');

        $repaired = static::getContainer()->get(MojibakeRepairer::class)->repair();

        self::assertSame([$garbled->getId()], $repaired['ctso_comment.content']);
        self::assertSame("<p>Rah,\u{A0}j’aurais, prêt</p>", $this->text('ctso_comment', $garbled->getId()));
        self::assertSame('<p>Déjà propre « comme ça » ! Ça c’est du français.</p>', $this->text('ctso_comment', $clean->getId()));
        self::assertSame('Noël !', $this->text('ctso_topic', $topic->getId(), 'title'));
        self::assertSame($beforeUpdated, $this->text('ctso_comment', $garbled->getId(), 'updated_at'), 'The edit date is left alone.');
        self::assertSame("« C'était l'été\u{A0}» dit-il", $this->text('ctso_comment', $typography->getId()));
    }

    public function testIsIdempotentAndDryRunWritesNothing(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, "Caf\u{C3}\u{A9}");
        $repairer = static::getContainer()->get(MojibakeRepairer::class);

        self::assertSame([$comment->getId()], $repairer->repair(dryRun: true)['ctso_comment.content']);
        self::assertSame("Caf\u{C3}\u{A9}", $this->text('ctso_comment', $comment->getId()), 'Dry run: untouched.');
        self::assertCount(1, $repairer->repair());
        self::assertSame('Café', $this->text('ctso_comment', $comment->getId()));
        self::assertSame([], $repairer->repair(), 'Second run: nothing left to repair.');
    }

    public function testADoubleEncodedByteOrderMarkDisappears(): void
    {
        self::assertSame('shaker Caliss', MojibakeRepairer::repairText("shaker\u{EF}\u{BB}\u{BF} Caliss"));
    }
}
