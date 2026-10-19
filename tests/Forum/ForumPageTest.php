<?php

namespace App\Tests\Forum;

use App\Controller\ForumController;
use App\Entity\Forum;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoRowsTrait;

class ForumPageTest extends AppWebTestCase
{
    use PhotoRowsTrait;

    private function member(string $name = 'alice'): User
    {
        $user = $this->createUser($name);
        $user->setLastActivity(new \DateTime()); // currently browsing: previous_login stays what the test sets
        $this->em()->flush();

        return $user;
    }

    private function article(int $topicId): \Symfony\Component\DomCrawler\Crawler
    {
        return $this->client->getCrawler()->filter(sprintf('article.thread-preview[aria-labelledby="thread-title-%d"]', $topicId));
    }

    public function testEveryTopicIsAThreadPreviewWithEscapedContent(): void
    {
        $alice = $this->member();
        $forum = $this->createForum();
        $tricky = $this->createTopic($forum, $alice, '<b>Gras</b> & "co"');
        $tricky->setContent('<p>&lt;i&gt;pas italique&lt;/i&gt; <b>vraiment</b></p><script>alert(1)</script><img src="/smilies/smile.gif">');
        $plain = $this->createTopic($forum, $alice, 'Un autre sujet');
        $this->em()->flush();
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertCount(2, $crawler->filter('article.thread-preview'));
        self::assertSame('<b>Gras</b> & "co"', trim($this->article($tricky->getId())->filter('h2.thread-preview-title a')->text()));
        self::assertSame("/forum/general/{$tricky->getId()}", $this->article($tricky->getId())->filter('h2.thread-preview-title a')->attr('href'));
        self::assertSame('<i>pas italique</i> vraiment', trim($this->article($tricky->getId())->filter('.thread-preview-excerpt')->text()));
        self::assertCount(0, $this->article($plain->getId())->filter('.thread-preview-excerpt'), 'No standfirst without description nor message.');

        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('&lt;b&gt;Gras&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Gras</b>', $html);
        self::assertStringContainsString('&lt;i&gt;pas italique&lt;/i&gt;', $html);
        self::assertStringNotContainsString('<i>pas italique', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function testStandfirstFallsBackToTheFirstMessageAndTheLastReplyIsQuoted(): void
    {
        $alice = $this->member();
        $bob = $this->createUser('bob');
        $topic = $this->createTopic($this->createForum(), $alice, 'Sans description');
        $this->createComment($topic, $alice, '<p>Le point de départ</p>', new \DateTime('2026-03-01 10:00'));
        $this->createComment($topic, $bob, '<p>La <strong>réponse</strong> de <em>Bob</em></p>', new \DateTime('2026-03-01 11:00'));
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        $article = $this->article($topic->getId());
        self::assertSame('Le point de départ', trim($article->filter('.thread-preview-excerpt')->text()));
        self::assertSame('bob', trim($article->filter('blockquote.thread-preview-last .thread-preview-last-author')->text()));
        self::assertStringContainsString('La réponse de Bob', $article->filter('blockquote.thread-preview-last')->text());
    }

    public function testCoverIsOnlyRenderedForTopicsWithAPhoto(): void
    {
        $alice = $this->member();
        $forum = $this->createForum();
        $withPhoto = $this->createTopic($forum, $alice, 'Avec photo');
        $photo = $this->photoRow('Plage');
        $this->attachToTopic($withPhoto, $photo, 0);
        $withMessagePhoto = $this->createTopic($forum, $alice, 'Photo dans un message');
        $this->attachToComment($this->createComment($withMessagePhoto, $alice, '<p>regardez</p>'), $messagePhoto = $this->photoRow());
        $bare = $this->createTopic($forum, $alice, 'Sans photo');
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('article.thread-preview.has-cover'));

        $cover = $this->article($withPhoto->getId())->filter('a.thread-preview-cover');
        self::assertCount(1, $cover);
        self::assertSame("/forum/general/{$withPhoto->getId()}", $cover->attr('href'));
        self::assertSame('true', $cover->attr('aria-hidden'));
        self::assertSame('-1', $cover->attr('tabindex'));
        self::assertSame('', $cover->filter('img')->attr('alt'), 'The cover duplicates the headline link: decorative.');
        self::assertSame("/photos/{$photo->getId()}/thumb?v={$photo->getFileVersion()}", $cover->filter('img')->attr('src'));
        self::assertSame("/photos/{$messagePhoto->getId()}/thumb?v={$messagePhoto->getFileVersion()}", $this->article($withMessagePhoto->getId())->filter('a.thread-preview-cover img')->attr('src'));

        $bareArticle = $this->article($bare->getId());
        self::assertCount(1, $bareArticle);
        self::assertStringNotContainsString('has-cover', $bareArticle->attr('class'));
        self::assertCount(0, $bareArticle->filter('.thread-preview-cover'));
    }

    public function testNewMessagesSincePreviousLoginAreFlagged(): void
    {
        $alice = $this->member();
        $alice->setPreviousLogin(new \DateTime('2026-03-05 12:00:00'));
        $this->em()->flush();
        $bob = $this->createUser('bob');
        $forum = $this->createForum();
        $busy = $this->createTopic($forum, $bob, 'Animé');
        $this->createComment($busy, $bob, '<p>avant</p>', new \DateTime('2026-03-01 10:00'));
        $this->createComment($busy, $bob, '<p>après un</p>', new \DateTime('2026-03-06 10:00'));
        $this->createComment($busy, $bob, '<p>après deux</p>', new \DateTime('2026-03-07 10:00'));
        $single = $this->createTopic($forum, $bob, 'Un seul nouveau');
        $this->createComment($single, $bob, '<p>après</p>', new \DateTime('2026-03-08 10:00'));
        $quiet = $this->createTopic($forum, $bob, 'Calme');
        $this->createComment($quiet, $bob, '<p>avant</p>', new \DateTime('2026-03-01 10:00'));
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        self::assertResponseIsSuccessful();
        self::assertSame('2 nouveaux', trim($this->article($busy->getId())->filter('.badge.badge-accent')->text()));
        self::assertStringContainsString('is-new', $this->article($busy->getId())->attr('class'));
        self::assertSame('1 nouveau', trim($this->article($single->getId())->filter('.badge.badge-accent')->text()));
        self::assertStringNotContainsString('is-new', $this->article($quiet->getId())->attr('class'));
        self::assertCount(0, $this->article($quiet->getId())->filter('.badge-accent'));
    }

    public function testNothingIsNewForAMemberWithoutPreviousLogin(): void
    {
        $alice = $this->member();
        $topic = $this->createTopic($this->createForum(), $alice, 'Sujet');
        $this->createComment($topic, $alice, '<p>message</p>', new \DateTime('2026-03-08 10:00'));
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        self::assertCount(0, $this->client->getCrawler()->filter('.is-new, .badge-accent'));
    }

    public function testStatsParticipantsAndProgressiveEnhancementHooks(): void
    {
        $alice = $this->member();
        $topic = $this->createTopic($this->createForum(), $alice, 'Détails');
        $topic->setViews(340);
        $this->em()->flush();
        $this->createComment($topic, $alice, '<p>un</p>', new \DateTime('2026-03-01 10:00'));
        $this->createComment($topic, $alice, '<p>deux</p>', new \DateTime('2026-03-01 11:00'));
        $this->attachToTopic($topic, $this->photoRow(), 0);
        $this->attachToComment($this->createComment($topic, $alice, '<p>trois</p>', new \DateTime('2026-03-01 12:00')), $this->photoRow());
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        $article = $this->article($topic->getId());
        $stats = $article->filter('.thread-preview-stat')->each(static fn ($node) => trim($node->text()));
        self::assertSame(['3 messages', '340 vues', '2 photos'], $stats);
        self::assertSame(['alice'], $article->filter('ul.avatar-stack li')->each(static fn ($node) => $node->attr('title')));
        self::assertCount(1, $article->filter('.thread-preview-meta form[data-star-form]'), 'The favourite toggle is part of the preview.');
        self::assertSame('Lire le sujet', trim($article->filter('.thread-preview-meta a.btn-ghost')->text()), 'Works without JavaScript.');

        $toggle = $article->filter('button.thread-preview-toggle');
        self::assertCount(1, $toggle);
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertTrue($toggle->getNode(0)->hasAttribute('hidden'), 'Revealed by JavaScript only.');
        self::assertSame("preview-more-{$topic->getId()}", $toggle->attr('aria-controls'));
        self::assertSame("/forum/general/{$topic->getId()}/preview", $toggle->attr('data-preview-url'));
        $panel = $article->filter("#preview-more-{$topic->getId()}.thread-preview-more");
        self::assertCount(1, $panel);
        self::assertTrue($panel->getNode(0)->hasAttribute('hidden'));
    }

    public function testSingularLabels(): void
    {
        $alice = $this->member();
        $topic = $this->createTopic($this->createForum(), $alice, 'Singulier');
        $topic->setViews(1);
        $this->em()->flush();
        $this->createComment($topic, $alice, '<p>unique</p>', new \DateTime('2026-03-01 10:00'));
        $this->attachToTopic($topic, $this->photoRow(), 0);
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        $stats = $this->article($topic->getId())->filter('.thread-preview-stat')->each(static fn ($node) => trim($node->text()));
        self::assertSame(['1 message', '1 vue', '1 photo'], $stats);
    }

    public function testEmptyForumShowsTheEmptyState(): void
    {
        $this->loginAs($this->member());
        $this->createForum();

        $this->client->request('GET', '/forums/general');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucun sujet dans ce forum pour le moment.');
        self::assertSelectorNotExists('article.thread-preview');
    }

    public function testParticipantOverflowShowsTheRemainingCount(): void
    {
        $alice = $this->member();
        $topic = $this->createTopic($this->createForum(), $alice, 'Foule');
        foreach (['bob', 'carol', 'dave', 'erin', 'frank', 'grace', 'heidi'] as $i => $name) {
            $this->createComment($topic, $this->createUser($name), "<p>$name</p>", new \DateTime("2026-03-01 1$i:00"));
        }
        $this->loginAs($alice);

        $this->client->request('GET', '/forums/general');

        $stack = $this->article($topic->getId())->filter('ul.avatar-stack');
        self::assertCount(6, $stack->filter('li'), '5 avatars and the "+N" item.');
        self::assertSame('+2', trim($stack->filter('li.avatar-stack-more')->text()));
    }

    public function testPageQueryCountDoesNotGrowWithTheNumberOfTopics(): void
    {
        $alice = $this->member();
        $users = [$alice, $this->createUser('bob'), $this->createUser('carol')];
        $this->populate($this->createForum('petit', 'Petit forum'), $users, 2);
        $this->populate($this->createForum('general', 'Général'), $users, ForumController::TOPICS_PER_PAGE);
        $this->loginAs($alice);

        $this->queryCount('/forums/petit'); // warm-up: the first request's profile also counts the test's own setup queries
        $small = $this->queryCount('/forums/petit');
        self::assertCount(2, $this->client->getCrawler()->filter('article.thread-preview'));
        $large = $this->queryCount('/forums/general');
        self::assertCount(ForumController::TOPICS_PER_PAGE, $this->client->getCrawler()->filter('article.thread-preview'));

        self::assertLessThanOrEqual($small + 2, $large, "A page of 12 topics ran $large queries against $small for 2 topics: something queries per topic.");
        self::assertLessThan(60, $large);
    }

    /** @param list<User> $users */
    private function populate(Forum $forum, array $users, int $topics): void
    {
        for ($t = 1; $t <= $topics; ++$t) {
            $topic = $this->createTopic($forum, $users[$t % 3], "Sujet {$forum->getId()} $t", new \DateTime("2026-02-01 10:00:00 +$t hours"));
            $topic->setContent(0 === $t % 3 ? null : "<p>Description du sujet $t</p>");
            if (0 === $t % 2) {
                $this->attachToTopic($topic, $this->photoRow(), 0);
            }
            for ($c = 1; $c <= 6; ++$c) {
                $comment = $this->createComment($topic, $users[($t + $c) % 3], "<p>Message $c du sujet $t</p>", new \DateTime("2026-02-01 10:00:00 +$t hours +$c minutes"));
                if (3 === $c) {
                    $this->attachToComment($comment, $this->photoRow());
                }
            }
        }
        $this->em()->flush();
    }

    private function queryCount(string $url): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();
        self::assertNotFalse($profile, 'The profiler must collect the request (KernelBrowser::enableProfiler).');

        return $profile->getCollector('db')->getQueryCount();
    }
}
