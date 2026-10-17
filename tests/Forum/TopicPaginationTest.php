<?php

namespace App\Tests\Forum;

use App\Controller\TopicController;
use App\Tests\Support\AppWebTestCase;

class TopicPaginationTest extends AppWebTestCase
{
    private const TOTAL = 45; // 3 pages of 20/20/5

    private function topicWithMessages(string $order): array
    {
        $alice = $this->createUser('alice');
        $alice->setMessageOrder($order);
        $forum = $this->createForum();
        $topic = $this->createTopic($forum, $alice);
        $start = new \DateTime('2026-01-01 10:00:00');
        for ($i = 1; $i <= self::TOTAL; ++$i) {
            $this->createComment($topic, $alice, "<p>message-$i</p>", (clone $start)->modify("+$i minutes"));
        }
        $this->loginAs($alice);

        return [$topic, $alice];
    }

    private function messages(): array
    {
        return array_map(
            static fn (string $text) => (int) substr($text, strlen('message-')),
            $this->client->getCrawler()->filter('[data-post] .post-body')->each(static fn ($node) => trim($node->text())),
        );
    }

    public function testOldestFirstOrderLandsOnTheLastPage(): void
    {
        [$topic] = $this->topicWithMessages('asc');

        $this->client->request('GET', "/forum/general/{$topic->getId()}");

        self::assertResponseIsSuccessful();
        self::assertSame([41, 42, 43, 44, 45], $this->messages());
    }

    public function testNewestFirstOrderLandsOnTheFirstPage(): void
    {
        [$topic] = $this->topicWithMessages('desc');

        $this->client->request('GET', "/forum/general/{$topic->getId()}");

        self::assertSame(range(45, 26), $this->messages());
    }

    public function testExplicitPageIsHonouredAndClamped(): void
    {
        [$topic] = $this->topicWithMessages('asc');

        $this->client->request('GET', "/forum/general/{$topic->getId()}?page=2");
        self::assertSame(range(21, 40), $this->messages());

        $this->client->request('GET', "/forum/general/{$topic->getId()}?page=999");
        self::assertSame([41, 42, 43, 44, 45], $this->messages(), 'A page past the end falls back to the last page.');

        $this->client->request('GET', "/forum/general/{$topic->getId()}?page=-3");
        self::assertSame(range(1, TopicController::MESSAGES_PER_PAGE), $this->messages());
    }

    public function testPagerLinksKeepTheTopicAndOnlyShowMeaningfulPages(): void
    {
        [$topic] = $this->topicWithMessages('asc');

        $this->client->request('GET', "/forum/general/{$topic->getId()}?page=2");

        $links = $this->client->getCrawler()->filter('nav.pager')->first()->filter('a')->each(static fn ($a) => $a->attr('href'));
        self::assertContains("/forum/general/{$topic->getId()}?page=1", $links);
        self::assertContains("/forum/general/{$topic->getId()}?page=3", $links);
        self::assertNotContains("/forum/general/{$topic->getId()}?page=4", $links);
    }

    public function testViewingATopicCountsAViewWithoutTouchingItsModificationDate(): void
    {
        [$topic] = $this->topicWithMessages('asc');
        $updatedAt = $topic->getUpdatedAt()->format('c');

        $this->client->request('GET', "/forum/general/{$topic->getId()}");
        $this->client->request('GET', "/forum/general/{$topic->getId()}");

        $this->em()->clear();
        $reloaded = $this->em()->find($topic::class, $topic->getId());
        self::assertSame(2, $reloaded->getViews());
        self::assertSame($updatedAt, $reloaded->getUpdatedAt()->format('c'));
    }

    public function testTopicUrlWithWrongForumRedirectsToTheCanonicalUrl(): void
    {
        [$topic] = $this->topicWithMessages('asc');

        $this->client->request('GET', "/forum/another-forum/{$topic->getId()}");

        self::assertResponseRedirects("/forum/general/{$topic->getId()}", 301);
    }
}
