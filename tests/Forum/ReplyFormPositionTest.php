<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** The reply form sits where the next message will appear: under the thread (oldest first), above it (most recent first). */
class ReplyFormPositionTest extends AppWebTestCase
{
    private const FORM = '//form[@name="comment"]';
    private const MESSAGES = '//section[@aria-label="Messages"]';

    private function topicPage(string $order): Crawler
    {
        $alice = $this->createUser('alice');
        $alice->setMessageOrder($order);
        $topic = $this->createTopic($this->createForum(), $alice, 'Sujet');
        $this->createComment($topic, $alice, '<p>Premier</p>');
        $this->em()->flush();
        $this->loginAs($alice);

        return $this->client->request('GET', '/forum/general/'.$topic->getId());
    }

    public function testTheFormEndsTheThreadWhenTheOldestMessageComesFirst(): void
    {
        $crawler = $this->topicPage('asc');

        self::assertSame(1, $crawler->filterXPath(self::FORM)->count());
        self::assertSame(1, $crawler->filterXPath(self::MESSAGES.'/following::form[@name="comment"]')->count(), 'form after the messages');
        self::assertSame(0, $crawler->filterXPath(self::FORM.'/following::section[@aria-label="Messages"]')->count());
    }

    public function testTheFormOpensTheThreadWhenTheMostRecentMessageComesFirst(): void
    {
        $crawler = $this->topicPage('desc');

        self::assertSame(1, $crawler->filterXPath(self::FORM)->count(), 'one reply form only');
        self::assertSame(1, $crawler->filterXPath(self::FORM.'/following::section[@aria-label="Messages"]')->count(), 'form before the messages');
        self::assertSame(0, $crawler->filterXPath(self::MESSAGES.'/following::form[@name="comment"]')->count());
    }

    public function testARefusedReplyKeepsTheFormAboveTheThreadInDescendingOrder(): void
    {
        $crawler = $this->topicPage('desc');
        $form = $crawler->filter('form[action$="/comment"]')->form();
        $values = $form->getPhpValues();
        $values['comment']['content'] = '<p></p>';

        $crawler = $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $crawler->filterXPath(self::FORM.'/following::section[@aria-label="Messages"]')->count());
    }
}
