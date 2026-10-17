<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoRowsTrait;

/** Pages that render many messages load their attached photos in bulk (no query per message). */
class MessagePhotosQueryTest extends AppWebTestCase
{
    use PhotoRowsTrait;

    private function queryCount(string $url): int
    {
        // The test's own entity manager must not hand its in-memory collections to the request.
        $user = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'alice']);
        $this->em()->clear();
        $this->loginAs($this->em()->getRepository(\App\Entity\User::class)->find($user->getId()));
        $this->client->enableProfiler();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->client->getProfile()->getCollector('db')->getQueryCount();
    }

    private function messagesWithPhotos(int $count, string $title): int
    {
        $alice = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'alice']) ?? $this->createUser('alice');
        $forum = $this->em()->getRepository(\App\Entity\Forum::class)->find('general') ?? $this->createForum();
        $topic = $this->createTopic($forum, $alice, $title);
        for ($i = 1; $i <= $count; ++$i) {
            $comment = $this->createComment($topic, $alice, "<p>Message $i</p>", new \DateTime("-$i minutes"));
            $this->attachToComment($comment, $this->photoRow(), 0);
            $this->attachToComment($comment, $this->photoRow(), 1);
        }

        return $topic->getId();
    }

    public function testTopicPageLoadsMessagePhotosInBulk(): void
    {
        $small = $this->messagesWithPhotos(2, 'Petit');
        $large = $this->messagesWithPhotos(15, 'Grand');

        $this->queryCount("/forum/general/$small"); // warm-up: the first request of a kernel also pays one-off costs
        $this->queryCount("/forum/general/$small"); // warm-up: the profiler of the first request also counts the test's own setup queries
        $few = $this->queryCount("/forum/general/$small");
        $crawler = $this->client->getCrawler();
        self::assertCount(4, $crawler->filter('.post-photos .post-photo'));
        $many = $this->queryCount("/forum/general/$large");

        self::assertCount(30, $this->client->getCrawler()->filter('.post-photos .post-photo'));
        self::assertSame($few, $many, 'The number of queries does not depend on the number of messages with photos.');
    }

    public function testHomeDigestLoadsMessagePhotosInBulk(): void
    {
        $this->messagesWithPhotos(2, 'Petit');
        $this->queryCount('/?since=2000-01-01'); // warm-up
        $few = $this->queryCount('/?since=2000-01-01');

        $this->messagesWithPhotos(12, 'Grand');
        $many = $this->queryCount('/?since=2000-01-01');

        self::assertCount(28, $this->client->getCrawler()->filter('.post-photos .post-photo'));
        // One more topic card costs a fixed handful of queries; the 24 extra photos must cost none.
        self::assertLessThanOrEqual($few + 6, $many);
    }
}
