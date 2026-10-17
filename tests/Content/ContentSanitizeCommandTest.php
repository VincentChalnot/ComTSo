<?php

namespace App\Tests\Content;

use App\Entity\Comment;
use App\Entity\Message;
use App\Tests\Support\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ContentSanitizeCommandTest extends AppWebTestCase
{
    private const DIRTY = '<p onclick="steal()">Salut</p><script>alert(1)</script>';
    private const CLEAN_DIRTY = '<p>Salut</p>';

    private function sanitizeCommand(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:content:sanitize'));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }

    /** Raw column value read straight from the database. */
    private function stored(string $table, string $column, int $id): ?string
    {
        $value = $this->em()->getConnection()->fetchOne(sprintf('SELECT %s FROM %s WHERE id = ?', $column, $table), [$id]);

        return false === $value ? null : $value;
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $author = $this->createUser();
        $comment = $this->createComment($this->createTopic($this->createForum(), $author), $author, self::DIRTY);

        $tester = $this->sanitizeCommand(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('dry run', $display);
        self::assertStringContainsString('1 row(s) would change', $display);
        self::assertMatchesRegularExpression('~ctso_comment\.content\s+1\s+1\s+0~', $display);
        self::assertStringContainsString('ctso_comment.content #'.$comment->getId(), $display, 'a sample difference is shown');
        self::assertStringContainsString('- <p onclick', $display);
        self::assertSame(self::DIRTY, $this->stored('ctso_comment', 'content', $comment->getId()));
    }

    /** Forum ids are slugs: paging on `id > (int) lastId` used to loop forever on the forum table. */
    public function testForumsWithSlugIdsAreProcessedOnceEach(): void
    {
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            $this->createForum($slug, ucfirst($slug))->setContent(self::DIRTY);
        }
        $this->em()->flush();

        $tester = $this->sanitizeCommand(['--batch-size' => 1]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertMatchesRegularExpression('~ctso_forum\.content\s+3\s+3\s+0~', $tester->getDisplay());
        foreach (['alpha', 'beta', 'gamma'] as $slug) {
            self::assertSame(self::CLEAN_DIRTY, $this->em()->getConnection()->fetchOne('SELECT content FROM ctso_forum WHERE id = ?', [$slug]));
        }
    }

    public function testSanitizesEveryHtmlColumnInBatchesAndLeavesPlainTextAlone(): void
    {
        $author = $this->createUser();
        $recipient = $this->createUser('bob');
        $topic = $this->createTopic($this->createForum(), $author);
        $topic->setContent('<iframe src="https://evil.example/"></iframe><p>desc</p>');
        $author->setSignature('<b onmouseover="x()">sig</b>')->setActivities('Ski & <3 randonnée');
        $message = (new Message())->setAuthor($author)->setRecipient($recipient)->setContent(self::DIRTY)->setState(0)
            ->setCreatedAt(new \DateTime())->setUpdatedAt(new \DateTime());
        $this->em()->persist($message);
        $comments = [
            $this->createComment($topic, $author, self::DIRTY),
            $this->createComment($topic, $author, '<p>déjà propre</p>'),
            $this->createComment($topic, $author, self::DIRTY),
            $this->createComment($topic, $author, self::DIRTY),
        ];
        $this->em()->flush();

        $updatedAt = $this->stored('ctso_comment', 'updated_at', $comments[0]->getId());

        $tester = $this->sanitizeCommand(['--batch-size' => 1]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('6 row(s) updated', $tester->getDisplay());
        foreach ([0, 2, 3] as $i) {
            self::assertSame(self::CLEAN_DIRTY, $this->stored('ctso_comment', 'content', $comments[$i]->getId()));
        }
        self::assertSame('<p>déjà propre</p>', $this->stored('ctso_comment', 'content', $comments[1]->getId()));
        self::assertSame('<p>desc</p>', $this->stored('ctso_topic', 'content', $topic->getId()));
        self::assertSame(self::CLEAN_DIRTY, $this->stored('ctso_message', 'content', $message->getId()));
        self::assertSame('<b>sig</b>', $this->stored('fos_user', 'signature', $author->getId()));
        self::assertSame('Ski & <3 randonnée', $this->stored('fos_user', 'activities', $author->getId()), 'plain-text columns are not HTML');
        self::assertSame($updatedAt, $this->stored('ctso_comment', 'updated_at', $comments[0]->getId()), 'sanitizing is not an edit');
    }

    public function testRunningTwiceChangesNothingTheSecondTime(): void
    {
        $author = $this->createUser();
        $this->createComment($this->createTopic($this->createForum(), $author), $author, self::DIRTY.'<p><a href="https://youtu.be/dQw4w9WgXcQ">https://youtu.be/dQw4w9WgXcQ</a></p>');

        self::assertStringContainsString('1 row(s) updated', $this->sanitizeCommand()->getDisplay());
        $second = $this->sanitizeCommand(['--dry-run' => true])->getDisplay();

        self::assertStringContainsString('0 row(s) would change', $second);
        self::assertStringNotContainsString(' #', $second, 'no difference to show');
    }

    public function testRowsWithoutContentAreSkipped(): void
    {
        $author = $this->createUser();
        $topic = $this->createTopic($this->createForum(), $author);
        $comment = (new Comment())->setTopic($topic)->setAuthor($author)->setContent(null);
        $this->em()->persist($comment);
        $this->em()->flush();

        self::assertStringContainsString('0 row(s) would change', $this->sanitizeCommand(['--dry-run' => true])->getDisplay());
    }

    public function testBatchSizeMustBePositive(): void
    {
        self::assertSame(2, $this->sanitizeCommand(['--batch-size' => 0])->getStatusCode());
    }
}
