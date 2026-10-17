<?php

namespace App\Tests\Forum;

use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Tests\Support\AppWebTestCase;

class HomeDigestTest extends AppWebTestCase
{
    public function testHomeGroupsMessagesPostedSinceTheGivenDateByTopic(): void
    {
        $alice = $this->createUser('alice');
        $forum = $this->createForum();
        $busy = $this->createTopic($forum, $alice, 'Sujet animé', new \DateTime('2026-01-01'));
        $quiet = $this->createTopic($forum, $alice, 'Sujet calme', new \DateTime('2026-01-01'));
        $this->createComment($busy, $alice, '<p>vieux message</p>', new \DateTime('2026-02-01'));
        $this->createComment($busy, $alice, '<p>message récent un</p>', new \DateTime('2026-03-10 10:00'));
        $this->createComment($busy, $alice, '<p>message récent deux</p>', new \DateTime('2026-03-11 10:00'));
        $busy->setLastActivityAt(new \DateTime('2026-03-11 10:00'));
        $this->createComment($quiet, $alice, '<p>encore plus vieux</p>', new \DateTime('2026-01-05'));
        $this->em()->flush();
        $this->loginAs($alice);

        $this->client->request('GET', '/?since=2026-03-01');

        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Sujet animé', $html);
        self::assertStringContainsString('message récent un', $html);
        self::assertStringContainsString('message récent deux', $html);
        self::assertStringNotContainsString('vieux message', $html, 'Messages older than the date are not part of the digest.');
        self::assertStringNotContainsString('Sujet calme', $html, 'Topics without news are not listed.');
    }

    public function testHomeDefaultsToTheEndOfThePreviousVisit(): void
    {
        $alice = $this->createUser('alice');
        $alice->setPreviousLogin(new \DateTime('2026-03-05'));
        $alice->setLastActivity(new \DateTime()); // currently browsing: the visit goes on, previous_login stays
        $this->em()->flush();
        $topic = $this->createTopic($this->createForum(), $alice, 'Un sujet', new \DateTime('2026-01-01'));
        $this->createComment($topic, $alice, '<p>avant la dernière visite</p>', new \DateTime('2026-03-01'));
        $this->createComment($topic, $alice, '<p>après la dernière visite</p>', new \DateTime('2026-03-09'));
        $topic->setLastActivityAt(new \DateTime('2026-03-09'));
        $this->em()->flush();
        $this->loginAs($alice);

        $this->client->request('GET', '/');

        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('après la dernière visite', $html);
        self::assertStringNotContainsString('avant la dernière visite', $html);
        self::assertSame('2026-03-05', $this->client->getCrawler()->filter('input[name=since]')->attr('value'));
    }

    public function testLegacyFormSinceParameterStillWorks(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Un sujet', new \DateTime('2026-01-01'));
        $this->createComment($topic, $alice, '<p>dans la fenêtre</p>', new \DateTime('2026-03-09'));
        $topic->setLastActivityAt(new \DateTime('2026-03-09'));
        $this->em()->flush();
        $this->loginAs($alice);

        $this->client->request('GET', '/?form[since]=2026-03-01');

        self::assertStringContainsString('dans la fenêtre', $this->client->getResponse()->getContent());
    }

    public function testPhotosAddedSinceTheDateAreShownWithTheirTopic(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Album', new \DateTime('2026-01-01'));
        $photo = (new Photo())->setFilename('p.jpeg')->setOriginalFilename('p.jpg')->setFileSize(1)->setFileType('image/jpeg')->setAuthor($alice);
        $link = (new PhotoTopic())->setTopic($topic)->setPhoto($photo)->setOrder(0)->setCreatedAt(new \DateTime('2026-03-09'))->setUpdatedAt(new \DateTime('2026-03-09'));
        $topic->setLastActivityAt(new \DateTime('2026-03-09'));
        $this->em()->persist($photo);
        $this->em()->persist($link);
        $this->em()->flush();
        $this->loginAs($alice);

        $this->client->request('GET', '/?since=2026-03-01');

        self::assertSelectorCount(1, '.digest a[data-lightbox]');
        self::assertSelectorTextContains('main', 'Album');
    }
}
