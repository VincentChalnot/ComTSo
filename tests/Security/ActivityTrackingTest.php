<?php

namespace App\Tests\Security;

use App\EventSubscriber\ActivitySubscriber;
use App\Tests\Support\AppWebTestCase;

class ActivityTrackingTest extends AppWebTestCase
{
    private function subscriber(): ActivitySubscriber
    {
        return static::getContainer()->get(ActivitySubscriber::class);
    }

    public function testFirstVisitStartsASession(): void
    {
        $alice = $this->createUser('alice');
        $now = new \DateTimeImmutable('2026-06-01 12:00:00');

        $this->subscriber()->touch($alice, $now);

        self::assertEquals($now, $alice->getLastActivity());
        self::assertEquals($now, $alice->getPreviousLogin());
    }

    public function testActivityWithinTheSameVisitKeepsThePreviousVisitMarker(): void
    {
        $alice = $this->createUser('alice');
        $alice->setLastActivity(new \DateTime('2026-06-01 11:50:00'));
        $alice->setPreviousLogin(new \DateTime('2026-05-20 09:00:00'));

        $this->subscriber()->touch($alice, new \DateTimeImmutable('2026-06-01 12:00:00'));

        self::assertEquals(new \DateTime('2026-06-01 12:00:00'), $alice->getLastActivity());
        self::assertEquals(new \DateTime('2026-05-20 09:00:00'), $alice->getPreviousLogin(), 'Still the same visit: the marker must not move.');
    }

    public function testComingBackAfterTheVisitGapMovesThePreviousVisitMarker(): void
    {
        $alice = $this->createUser('alice');
        $alice->setLastActivity(new \DateTime('2026-06-01 11:00:00'));
        $alice->setPreviousLogin(new \DateTime('2026-05-20 09:00:00'));

        $this->subscriber()->touch($alice, new \DateTimeImmutable('2026-06-01 12:00:00'));

        self::assertEquals(new \DateTime('2026-06-01 11:00:00'), $alice->getPreviousLogin(), 'The previous visit ended at the last recorded activity.');
    }

    public function testActivityIsThrottledToOnceAMinute(): void
    {
        $alice = $this->createUser('alice');
        $alice->setLastActivity(new \DateTime('2026-06-01 12:00:00'));

        $this->subscriber()->touch($alice, new \DateTimeImmutable('2026-06-01 12:00:30'));

        self::assertEquals(new \DateTime('2026-06-01 12:00:00'), $alice->getLastActivity());
    }

    public function testBackgroundRequestsDoNotCountAsActivity(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $this->client->request('GET', '/gens', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        $this->em()->clear();
        self::assertNull($this->em()->find($alice::class, $alice->getId())->getLastActivity());

        $this->client->request('GET', '/gens');
        $this->em()->clear();
        self::assertNotNull($this->em()->find($alice::class, $alice->getId())->getLastActivity());
    }
}
