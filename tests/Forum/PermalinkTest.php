<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PermalinkTest extends AppWebTestCase
{
    /** The own page of a message must lead to the page of the topic that really contains it, in the member's order. */
    #[DataProvider('orders')]
    public function testMessagePageLinksToTheRightTopicPageAndAnchor(string $order, int $expectedPage): void
    {
        $alice = $this->createUser('alice');
        $alice->setMessageOrder($order);
        $topic = $this->createTopic($this->createForum(), $alice);
        $comments = [];
        for ($i = 1; $i <= 30; ++$i) {
            $comments[$i] = $this->createComment($topic, $alice, "<p>m$i</p>", new \DateTime("2026-01-01 10:00:00 +$i minutes"));
        }
        $this->loginAs($alice);

        // message #3 is on page 1 when oldest first, on page 2 when newest first (position 27 of 30)
        $this->client->request('GET', '/comment/'.$comments[3]->getId());

        self::assertResponseIsSuccessful();
        $href = $this->client->getCrawler()->selectLink('Voir dans le sujet')->attr('href');
        self::assertSame("/forum/general/{$topic->getId()}?page=$expectedPage#post-{$comments[3]->getId()}", $href);
    }

    public static function orders(): iterable
    {
        yield 'oldest first' => ['asc', 1];
        yield 'newest first' => ['desc', 2];
    }

    public function testPostingAMessageRedirectsToItsPage(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        for ($i = 1; $i <= 20; ++$i) {
            $this->createComment($topic, $alice, "<p>m$i</p>", new \DateTime("2026-01-01 10:00:00 +$i minutes"));
        }
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', "/forum/general/{$topic->getId()}");
        $form = $crawler->selectButton('Envoyer')->form();
        $form['comment[content]'] = '<p>nouveau</p>';
        $this->client->submit($form);

        // 21st message: oldest first => page 2
        self::assertResponseRedirects();
        self::assertMatchesRegularExpression('~^/forum/general/\d+\?page=2#post-\d+$~', $this->client->getResponse()->headers->get('Location'));
    }
}
