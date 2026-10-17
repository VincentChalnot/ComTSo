<?php

namespace App\Tests\Forum;

use App\Content\TextExcerpt;
use App\Entity\Forum;
use App\Entity\Topic;
use App\Entity\User;
use App\Forum\ThreadPreview;
use App\Forum\ThreadPreviewBuilder;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoRowsTrait;

class ThreadPreviewBuilderTest extends AppWebTestCase
{
    use PhotoRowsTrait;

    private Forum $forum;
    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = $this->createUser('alice');
        $this->forum = $this->createForum();
    }

    private function topic(string $title = 'Un sujet', ?string $description = null): Topic
    {
        $topic = $this->createTopic($this->forum, $this->alice, $title, new \DateTime('2026-01-01'));
        $topic->setContent($description);
        $this->em()->flush();

        return $topic;
    }

    private function preview(Topic $topic, ?User $viewer = null): ThreadPreview
    {
        return $this->previews([$topic], $viewer)[0];
    }

    /** @return list<ThreadPreview> */
    private function previews(array $topics, ?User $viewer = null): array
    {
        return (new ThreadPreviewBuilder($this->em(), new TextExcerpt()))->build($topics, $viewer);
    }

    private function at(string $time): \DateTime
    {
        return new \DateTime("2026-03-01 $time");
    }

    public function testNoTopicsNoPreviews(): void
    {
        self::assertSame([], $this->previews([]));
    }

    public function testPreviewsKeepTheOrderOfTheTopics(): void
    {
        $first = $this->topic('Premier');
        $second = $this->topic('Deuxième');

        $previews = $this->previews([$second, $first]);

        self::assertSame(['Deuxième', 'Premier'], array_map(static fn (ThreadPreview $p) => $p->topic->getTitle(), $previews));
    }

    public function testStandfirstIsThePlainTextOfTheDescription(): void
    {
        $topic = $this->topic('Sujet', '<p>Une <strong>description</strong>&nbsp;avec &amp; des <a href="https://example.test">liens</a><img src="/smilies/smile.gif"></p><blockquote>citation</blockquote>');
        $this->createComment($topic, $this->alice, '<p>premier message</p>', $this->at('10:00'));

        $preview = $this->preview($topic);

        self::assertSame('Une description avec & des liens', $preview->excerpt);
    }

    public function testEmptyDescriptionFallsBackToTheFirstMessage(): void
    {
        $bob = $this->createUser('bob');
        $topic = $this->topic('Sujet', '<p>&nbsp;</p>');
        $this->createComment($topic, $bob, '<p>Le <em>tout premier</em> message</p>', $this->at('10:00'));
        $newest = $this->createComment($topic, $this->alice, '<p>Le dernier message</p>', $this->at('12:00'));
        $this->createComment($topic, $bob, '<p>Un message du milieu</p>', $this->at('11:00'));

        $preview = $this->preview($topic);

        self::assertSame('Le tout premier message', $preview->excerpt);
        self::assertSame($newest->getId(), $preview->lastReply?->comment->getId());
    }

    public function testStandfirstIsEmptyWithoutDescriptionNorMessage(): void
    {
        $preview = $this->preview($this->topic('Vide'));

        self::assertSame('', $preview->excerpt);
        self::assertNull($preview->lastReply);
    }

    public function testTheOnlyMessageIsNotRepeatedAsLastReplyWhenItIsTheStandfirst(): void
    {
        $topic = $this->topic('Sujet');
        $this->createComment($topic, $this->alice, '<p>Un seul message</p>', $this->at('10:00'));

        $preview = $this->preview($topic);

        self::assertSame('Un seul message', $preview->excerpt);
        self::assertNull($preview->lastReply);
    }

    public function testLongExcerptsAreCutAtAWordBoundaryAndShortOnesAreKept(): void
    {
        $long = $this->topic('Long', '<p>'.trim(str_repeat('parole ', 60)).'</p>');
        $short = $this->topic('Court', '<p>Juste ce qu\'il faut.</p>');

        [$cutPreview, $keptPreview] = $this->previews([$long, $short]);

        self::assertStringEndsWith('parole…', $cutPreview->excerpt);
        self::assertLessThanOrEqual(ThreadPreviewBuilder::EXCERPT_LENGTH + 1, mb_strlen($cutPreview->excerpt));
        self::assertSame('Juste ce qu\'il faut.', $keptPreview->excerpt);
    }

    public function testExcerptCutIsMultibyteSafe(): void
    {
        $topic = $this->topic('Accents', '<p>'.str_repeat('été à la mer ', 40).'</p>');

        $excerpt = $this->preview($topic)->excerpt;

        self::assertTrue(mb_check_encoding($excerpt, 'UTF-8'));
        self::assertStringEndsWith('mer…', $excerpt);
    }

    public function testLastReplyIsTheNewestMessageByDateWithAShortExcerpt(): void
    {
        $bob = $this->createUser('bob');
        $topic = $this->topic('Sujet', '<p>Description</p>');
        $newest = $this->createComment($topic, $bob, '<p>'.str_repeat('bavardage ', 50).'</p>', $this->at('15:00'));
        $this->createComment($topic, $this->alice, '<p>Plus ancien mais créé après</p>', $this->at('09:00'));

        $reply = $this->preview($topic)->lastReply;

        self::assertSame($newest->getId(), $reply->comment->getId());
        self::assertSame('bob', $reply->author->getUsername());
        self::assertLessThanOrEqual(ThreadPreviewBuilder::REPLY_LENGTH + 1, mb_strlen($reply->excerpt));
        self::assertStringEndsWith('bavardage…', $reply->excerpt);
        self::assertFalse($reply->hasPhoto);
    }

    public function testPhotoOnlyLastReplyHasNoTextAndFlagsThePhoto(): void
    {
        $topic = $this->topic('Sujet', '<p>Description</p>');
        $this->createComment($topic, $this->alice, '<p>Avant</p>', $this->at('09:00'));
        $photoMessage = $this->createComment($topic, $this->alice, '', $this->at('10:00'));
        $this->attachToComment($photoMessage, $this->photoRow());

        $reply = $this->preview($topic)->lastReply;

        self::assertSame($photoMessage->getId(), $reply->comment->getId());
        self::assertSame('', $reply->excerpt);
        self::assertTrue($reply->hasPhoto);
    }

    public function testLastReplyWithNeitherTextNorPhotoIsOmitted(): void
    {
        $topic = $this->topic('Sujet', '<p>Description</p>');
        $this->createComment($topic, $this->alice, '<p><img src="/smilies/smile.gif"></p>', $this->at('10:00'));

        self::assertNull($this->preview($topic)->lastReply);
    }

    public function testTopicPhotoIsTheCoverEvenWhenMessagesHavePhotos(): void
    {
        $topic = $this->topic('Album');
        $second = $this->photoRow('second');
        $first = $this->photoRow('first');
        $this->attachToTopic($topic, $second, 2);
        $this->attachToTopic($topic, $first, 1);
        $message = $this->createComment($topic, $this->alice, '<p>Voilà</p>', $this->at('10:00'));
        $this->attachToComment($message, $this->photoRow('in message'));

        self::assertSame($first->getId(), $this->preview($topic)->coverPhoto?->getId());
    }

    public function testCoverFallsBackToTheFirstPhotoOfTheNewestMessageThatHasOne(): void
    {
        $topic = $this->topic('Sans album');
        $old = $this->createComment($topic, $this->alice, '<p>ancien</p>', $this->at('08:00'));
        $this->attachToComment($old, $this->photoRow('old'));
        $recent = $this->createComment($topic, $this->alice, '<p>récent</p>', $this->at('09:00'));
        $this->attachToComment($recent, $second = $this->photoRow('recent second'), 2);
        $this->attachToComment($recent, $first = $this->photoRow('recent first'), 1);
        $this->createComment($topic, $this->alice, '<p>sans photo</p>', $this->at('10:00'));

        self::assertSame($first->getId(), $this->preview($topic)->coverPhoto?->getId());
    }

    public function testNoCoverWithoutAnyPhotoOrWhenTheMessagePhotoIsTooOld(): void
    {
        $bare = $this->topic('Nu');
        $this->createComment($bare, $this->alice, '<p>texte</p>', $this->at('08:00'));

        $buried = $this->topic('Enfoui');
        $ancient = $this->createComment($buried, $this->alice, '<p>photo très ancienne</p>', $this->at('01:00'));
        $this->attachToComment($ancient, $this->photoRow());
        for ($i = 1; $i <= ThreadPreviewBuilder::COVER_LOOKBACK; ++$i) {
            $this->createComment($buried, $this->alice, "<p>message $i</p>", $this->at(sprintf('%02d:30', $i + 1)));
        }

        [$bareCover, $buriedCover] = $this->previews([$bare, $buried]);

        self::assertNull($bareCover->coverPhoto);
        self::assertNull($buriedCover->coverPhoto, 'Only the newest messages are searched for a cover.');
    }

    public function testParticipantsAreOrderedByMessageCountThenRecencyAndCapped(): void
    {
        $users = [];
        foreach (['bob', 'carol', 'dave', 'erin', 'frank', 'grace'] as $name) {
            $users[$name] = $this->createUser($name);
        }
        $topic = $this->topic('Discussion'); // alice authored the topic but never replied
        $post = fn (string $who, string $time) => $this->createComment($topic, $users[$who], "<p>$who $time</p>", $this->at($time));
        $post('bob', '08:00');
        $post('bob', '08:10');
        $post('bob', '08:20');
        $post('carol', '09:00');
        $post('carol', '09:10');
        $post('dave', '09:05');
        $post('dave', '09:30'); // ties with carol on 2 messages, but more recent
        $post('erin', '10:00');
        $post('frank', '11:00'); // single messages: most recent first
        $post('grace', '07:00');

        $preview = $this->preview($topic);

        self::assertSame(['bob', 'dave', 'carol', 'frank', 'erin'], array_map(static fn (User $u) => $u->getUsername(), $preview->participants));
        self::assertSame(1, $preview->moreParticipants, 'Six members posted: the least active one does not fit in the stack.');
    }

    public function testTopicAuthorOnlyCountsWhenTheyPostedMessages(): void
    {
        $bob = $this->createUser('bob');
        $silent = $this->topic('Silencieux');
        $this->createComment($silent, $bob, '<p>bob</p>', $this->at('08:00'));
        $lonely = $this->topic('Seul');
        $talkative = $this->topic('Bavard');
        $this->createComment($talkative, $bob, '<p>bob</p>', $this->at('08:00'));
        $this->createComment($talkative, $this->alice, '<p>alice</p>', $this->at('09:00'));

        [$silentPreview, $lonelyPreview, $talkativePreview] = $this->previews([$silent, $lonely, $talkative]);

        self::assertSame(['bob'], array_map(static fn (User $u) => $u->getUsername(), $silentPreview->participants));
        self::assertSame(['alice'], array_map(static fn (User $u) => $u->getUsername(), $lonelyPreview->participants), 'Without messages the topic author is the only participant.');
        self::assertSame(['alice', 'bob'], array_map(static fn (User $u) => $u->getUsername(), $talkativePreview->participants), 'Alice replied last: tie on count, most recent first.');
        self::assertSame(0, $lonelyPreview->moreParticipants);
    }

    public function testNewCountUsesThePreviousLogin(): void
    {
        $viewer = $this->createUser('viewer');
        $viewer->setPreviousLogin(new \DateTime('2026-03-05 12:00:00'));
        $this->em()->flush();
        $busy = $this->topic('Animé');
        $this->createComment($busy, $this->alice, '<p>avant</p>', new \DateTime('2026-03-01 10:00:00'));
        $this->createComment($busy, $this->alice, '<p>pile à l\'heure</p>', new \DateTime('2026-03-05 12:00:00'));
        $this->createComment($busy, $viewer, '<p>mon propre message</p>', new \DateTime('2026-03-09 10:00:00'));
        $quiet = $this->topic('Calme');
        $this->createComment($quiet, $this->alice, '<p>vieux</p>', new \DateTime('2026-03-02 10:00:00'));

        [$busyPreview, $quietPreview] = $this->previews([$busy, $quiet], $viewer);

        self::assertSame(2, $busyPreview->newCount, 'Messages posted since the previous login, whoever wrote them (like CommentRepository::countSince).');
        self::assertTrue($busyPreview->isNew());
        self::assertSame(0, $quietPreview->newCount);
        self::assertFalse($quietPreview->isNew());
    }

    public function testNothingIsNewWithoutABaseline(): void
    {
        $viewer = $this->createUser('viewer'); // never logged in before: no previous login
        $topic = $this->topic('Sujet');
        $this->createComment($topic, $this->alice, '<p>message</p>', new \DateTime('2026-03-09 10:00:00'));

        self::assertSame(0, $this->preview($topic, $viewer)->newCount);
        self::assertSame(0, $this->preview($topic, null)->newCount);
    }

    public function testStatsCountBothKindsOfPhotoLinks(): void
    {
        $topic = $this->topic('Photos');
        $topic->setViews(340);
        $this->em()->flush();
        $this->attachToTopic($topic, $this->photoRow(), 0);
        $this->attachToTopic($topic, $this->photoRow(), 1);
        $one = $this->createComment($topic, $this->alice, '<p>un</p>', $this->at('08:00'));
        $two = $this->createComment($topic, $this->alice, '<p>deux</p>', $this->at('09:00'));
        $this->createComment($topic, $this->alice, '<p>trois</p>', $this->at('10:00'));
        $this->attachToComment($one, $this->photoRow());
        $this->attachToComment($two, $this->photoRow());
        $this->attachToComment($two, $this->photoRow());
        $other = $this->topic('Autre sujet');
        $this->attachToComment($this->createComment($other, $this->alice, '<p>x</p>', $this->at('08:00')), $this->photoRow());

        [$preview, $otherPreview] = $this->previews([$topic, $other]);

        self::assertSame(5, $preview->photoCount);
        self::assertSame(3, $preview->messageCount);
        self::assertSame(340, $preview->viewCount);
        self::assertSame(1, $otherPreview->photoCount, 'Counts are per topic.');
    }
}
