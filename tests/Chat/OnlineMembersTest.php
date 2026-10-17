<?php

namespace App\Tests\Chat;

use App\Chat\OnlineMembers;

class OnlineMembersTest extends ChatTestCase
{
    private function at(string $when, ?\DateTimeImmutable $now = null): \DateTime
    {
        return \DateTime::createFromImmutable(($now ?? new \DateTimeImmutable())->modify($when));
    }

    public function testOnlyMembersActiveWithinTheLastFiveMinutesAreOnline(): void
    {
        $now = new \DateTimeImmutable('2026-10-17 15:00:00');
        $fresh = $this->createUser('fresh')->setLastActivity($this->at('-30 seconds', $now));
        $edge = $this->createUser('edge')->setLastActivity($this->at('-5 minutes', $now));
        $this->createUser('stale')->setLastActivity($this->at('-5 minutes -1 second', $now));
        $this->createUser('long-gone')->setLastActivity($this->at('-3 years', $now));
        $this->createUser('never');
        $this->em()->flush();

        $online = static::getContainer()->get(OnlineMembers::class)->list($now);

        self::assertSame(['edge', 'fresh'], array_map(static fn ($u) => $u->getUsername(), $online), 'alphabetical, window inclusive');
        self::assertSame([$edge->getId(), $fresh->getId()], array_map(static fn ($u) => $u->getId(), $online));
    }

    public function testDisabledMembersAreNeverListed(): void
    {
        $now = new \DateTimeImmutable('2026-10-17 15:00:00');
        $this->createUser('gone')->setEnabled(false)->setLastActivity($this->at('now', $now));
        $this->em()->flush();

        self::assertSame([], static::getContainer()->get(OnlineMembers::class)->list($now));
    }

    public function testOnlineEndpointListsTheCurrentMemberAndRecentVisitors(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('bob')->setLastActivity($this->at('-2 minutes'));
        $this->createUser('carol')->setLastActivity($this->at('-20 minutes'));
        $this->em()->flush();
        $this->loginAs($alice);

        $members = $this->getJson('/chat/online')['members'];

        // alice just made a request: she counts as online herself.
        self::assertSame(['alice', 'bob'], array_column($members, 'username'));
    }
}
