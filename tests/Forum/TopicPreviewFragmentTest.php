<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;

class TopicPreviewFragmentTest extends AppWebTestCase
{
    private const TOTAL = 8;

    private function topicWithMessages(string $order = 'asc'): int
    {
        $alice = $this->createUser('alice');
        $alice->setMessageOrder($order);
        $this->em()->flush();
        $topic = $this->createTopic($this->createForum(), $alice, 'Un sujet animé');
        $start = new \DateTime('2026-01-01 10:00:00');
        for ($i = 1; $i <= self::TOTAL; ++$i) {
            $this->createComment($topic, $alice, "<p>message-$i</p>", (clone $start)->modify("+$i minutes"));
        }
        $this->loginAs($alice);

        return $topic->getId();
    }

    /** @return list<int> */
    private function messages(): array
    {
        return array_map(
            static fn (string $text) => (int) substr($text, strlen('message-')),
            $this->client->getCrawler()->filter('[data-post] .post-body')->each(static fn ($node) => trim($node->text())),
        );
    }

    public function testAnonymousVisitorsAreSentToTheLoginPage(): void
    {
        $topic = $this->createTopic($this->createForum(), $this->createUser('alice'));

        $this->client->request('GET', "/forum/general/{$topic->getId()}/preview");

        self::assertResponseRedirects('/login');
    }

    public function testFragmentHoldsTheLastFiveMessagesOldestFirstByDefault(): void
    {
        $id = $this->topicWithMessages('asc');

        $this->client->request('GET', "/forum/general/$id/preview");

        self::assertResponseIsSuccessful();
        self::assertSame([4, 5, 6, 7, 8], $this->messages());
    }

    public function testFragmentFollowsTheMemberMessageOrder(): void
    {
        $id = $this->topicWithMessages('desc');

        $this->client->request('GET', "/forum/general/$id/preview");

        self::assertResponseIsSuccessful();
        self::assertSame([8, 7, 6, 5, 4], $this->messages());
    }

    public function testFragmentHasNoLayoutAndLinksToTheTopic(): void
    {
        $id = $this->topicWithMessages();

        $this->client->request('GET', "/forum/general/$id/preview");

        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsStringIgnoringCase('<html', $html);
        self::assertStringNotContainsStringIgnoringCase('<body', $html);
        $link = $this->client->getCrawler()->filter('a.btn.btn-primary');
        self::assertCount(1, $link);
        self::assertSame("/forum/general/$id", $link->attr('href'));
        self::assertSame('Lire le sujet', trim($link->text()));
        self::assertStringNotContainsString('Supprimer', $html, 'The preview offers no edit/delete actions.');
        self::assertStringNotContainsString('Modifier', $html, 'The preview offers no edit/delete actions.');
    }

    public function testFragmentIsPrivateAndNeverStored(): void
    {
        $id = $this->topicWithMessages();

        $this->client->request('GET', "/forum/general/$id/preview");

        $cache = $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        self::assertStringNotContainsString('public', $cache);
    }

    public function testMessageContentIsSanitized(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->createComment($topic, $alice, '<p>Salut</p><script>alert("xss")</script><img src="x" onerror="alert(1)"><a href="javascript:alert(2)">piège</a>');
        $this->loginAs($alice);

        $this->client->request('GET', "/forum/general/{$topic->getId()}/preview");

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Salut', $html);
        self::assertStringNotContainsStringIgnoringCase('<script', $html);
        self::assertStringNotContainsStringIgnoringCase('onerror', $html);
        self::assertStringNotContainsStringIgnoringCase('javascript:', $html);
    }

    public function testTopicWithoutMessagesShowsAnEmptyNote(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->loginAs($alice);

        $this->client->request('GET', "/forum/general/{$topic->getId()}/preview");

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aucun message', $this->client->getResponse()->getContent());
    }

    public function testWrongForumSlugRedirectsToTheCanonicalUrl(): void
    {
        $id = $this->topicWithMessages();
        $this->createForum('autre', 'Autre');

        $this->client->request('GET', "/forum/autre/$id/preview");

        self::assertResponseRedirects("/forum/general/$id/preview", 301);
    }

    public function testUnknownTopicIsNotFound(): void
    {
        $this->topicWithMessages();

        $this->client->request('GET', '/forum/general/999999/preview');

        self::assertResponseStatusCodeSame(404);
    }
}
