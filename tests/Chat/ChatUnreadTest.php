<?php

namespace App\Tests\Chat;

use App\Entity\User;
use App\Repository\ChatMessageRepository;

class ChatUnreadTest extends ChatTestCase
{
    private function repository(): ChatMessageRepository
    {
        return static::getContainer()->get(ChatMessageRepository::class);
    }

    private function lastRead(User $user): int
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $user->getId())->getChatLastReadId();
    }

    public function testUnreadCountExcludesOwnMessagesAndRespectsTheReadPointer(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $m1 = $this->createMessage($bob, 'un');
        $this->createMessage($alice, 'mon message');
        $m3 = $this->createMessage($bob, 'trois');
        $this->createMessage($bob, 'quatre');

        self::assertSame(3, $this->repository()->countUnreadFor($alice), "bob's three messages, not alice's own");
        self::assertSame(1, $this->repository()->countUnreadFor($bob), "only alice's message is unread for bob");

        $alice->setChatLastReadId($m3->getId());
        self::assertSame(1, $this->repository()->countUnreadFor($alice), 'only what is after the pointer');

        $alice->setChatLastReadId($m1->getId());
        self::assertSame(2, $this->repository()->countUnreadFor($alice));
    }

    public function testSnapshotCountsAreBoundedByTheNewestMessage(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->createMessage($bob, 'un');
        $last = $this->createMessage($bob, 'deux');

        self::assertSame(['latestId' => $last->getId(), 'unread' => 2], $this->repository()->unreadSnapshot($alice));
    }

    public function testStateEndpointReportsTheUnreadCount(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->createMessage($bob, 'un');
        $this->createMessage($alice, 'moi');
        $last = $this->createMessage($bob, 'deux');
        $this->loginAs($alice);

        $state = $this->getJson('/chat/state');

        self::assertSame(2, $state['unread']);
        self::assertSame($last->getId(), $state['latestId']);
        self::assertSame($alice->getId(), $state['userId']);
    }

    public function testNavigationBadgeShowsUnreadMessagesButNeverOnTheChatPage(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->createMessage($bob, 'un');
        $this->createMessage($bob, 'deux');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens');
        self::assertSame('2', $crawler->filter('[data-chat-badge]')->attr('data-count'));

        $crawler = $this->client->request('GET', '/chat');
        self::assertSame('0', $crawler->filter('[data-chat-badge]')->attr('data-count'), 'the page itself shows the messages');
    }

    public function testMarkingAsReadMovesThePointerAndTellsOtherTabsPrivately(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->createMessage($bob, 'un');
        $second = $this->createMessage($bob, 'deux');
        $third = $this->createMessage($bob, 'trois');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];

        $data = $this->postJson('/chat/read', ['lastId' => $second->getId()], $csrf);

        self::assertResponseIsSuccessful();
        self::assertSame($second->getId(), $data['lastReadId']);
        self::assertSame(1, $data['unread'], 'the third message is still unread');
        self::assertSame($third->getId(), $data['latestId']);
        self::assertSame($second->getId(), $this->lastRead($alice));

        $updates = $this->published();
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]->isPrivate());
        self::assertSame(['chat/users/'.$alice->getId()], $updates[0]->getTopics(), 'only the tabs of this member are told');
        self::assertSame(
            ['type' => 'read', 'lastReadId' => $second->getId(), 'latestId' => $third->getId(), 'unread' => 1],
            json_decode($updates[0]->getData(), true),
        );
    }

    public function testMarkAsReadIsMonotonic(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $first = $this->createMessage($bob, 'un');
        $second = $this->createMessage($bob, 'deux');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];

        $this->postJson('/chat/read', ['lastId' => $second->getId()], $csrf);

        // A late request from a tab that only saw the first message must not move the pointer back.
        $this->client->disableReboot();
        $this->hub()->updates = [];
        $data = $this->postJson('/chat/read', ['lastId' => $first->getId()], $csrf);

        self::assertResponseIsSuccessful();
        self::assertSame($second->getId(), $data['lastReadId']);
        self::assertSame(0, $data['unread']);
        self::assertSame($second->getId(), $this->lastRead($alice));
        self::assertSame([], $this->published(), 'nothing moved, nothing to tell the other tabs');
    }

    public function testMarkAsReadNeverGoesBeyondTheNewestMessage(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $last = $this->createMessage($bob, 'un');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];

        $data = $this->postJson('/chat/read', ['lastId' => $last->getId() + 1000], $csrf);

        self::assertSame($last->getId(), $data['lastReadId'], 'otherwise future messages would silently count as read');
    }

    public function testMarkAsReadWithoutIdMarksEverythingAndOnlyForTheCurrentMember(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $last = $this->createMessage($bob, 'un');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];

        $this->postJson('/chat/read', [], $csrf);

        self::assertSame($last->getId(), $this->lastRead($alice));
        self::assertSame(0, $this->lastRead($bob), "bob's pointer is untouched");
    }

    public function testMarkAsReadValidatesInputAndCsrf(): void
    {
        $alice = $this->createUser('alice');
        $this->createMessage($this->createUser('bob'), 'un');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];

        $this->postJson('/chat/read', ['lastId' => 'abc'], $csrf);
        self::assertResponseStatusCodeSame(422);

        $this->postJson('/chat/read', ['lastId' => -4], $csrf);
        self::assertResponseStatusCodeSame(422);

        $this->postJson('/chat/read', ['lastId' => 1], 'forged');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->lastRead($alice));
    }
}
