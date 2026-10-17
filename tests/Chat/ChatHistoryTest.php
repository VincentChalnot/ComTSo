<?php

namespace App\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;

class ChatHistoryTest extends ChatTestCase
{
    public function testLatestPageThenOlderPagesDoNotOverlapAndKeepAStableOrder(): void
    {
        $alice = $this->createUser('alice');
        $created = $this->createMessages($alice, 120);
        $this->loginAs($alice);
        $expected = array_map(static fn ($m) => $m->getId(), $created);

        $first = $this->getJson('/chat/messages');
        $ids = array_column($first['messages'], 'id');
        self::assertCount(50, $ids);
        self::assertSame(array_slice($expected, -50), $ids, 'latest 50, oldest first');
        self::assertTrue($first['hasMore']);

        $second = $this->getJson('/chat/messages?before='.$ids[0]);
        self::assertCount(50, $second['messages']);
        self::assertSame(array_slice($expected, 20, 50), array_column($second['messages'], 'id'));
        self::assertTrue($second['hasMore']);

        $third = $this->getJson('/chat/messages?before='.$second['messages'][0]['id']);
        self::assertSame(array_slice($expected, 0, 20), array_column($third['messages'], 'id'));
        self::assertFalse($third['hasMore']);

        $everything = [...array_column($third['messages'], 'id'), ...array_column($second['messages'], 'id'), ...$ids];
        self::assertSame($expected, $everything, 'every message exactly once, in order');
    }

    public function testPageSizeBoundaryDoesNotAdvertiseAnEmptyNextPage(): void
    {
        $alice = $this->createUser('alice');
        $this->createMessages($alice, 50);
        $this->loginAs($alice);

        $page = $this->getJson('/chat/messages');

        self::assertCount(50, $page['messages']);
        self::assertFalse($page['hasMore']);
    }

    public function testCatchUpReturnsOnlyNewerMessagesOldestFirst(): void
    {
        $alice = $this->createUser('alice');
        $created = $this->createMessages($alice, 10);
        $this->loginAs($alice);
        $ids = array_map(static fn ($m) => $m->getId(), $created);

        $data = $this->getJson('/chat/messages?after='.$ids[6]);

        self::assertSame([$ids[7], $ids[8], $ids[9]], array_column($data['messages'], 'id'));
        self::assertFalse($data['hasMore']);

        $none = $this->getJson('/chat/messages?after='.$ids[9]);
        self::assertSame([], $none['messages']);
    }

    public function testCatchUpIsPaginatedSoALongOutageCanBeReplayed(): void
    {
        $alice = $this->createUser('alice');
        $created = $this->createMessages($alice, 205);
        $this->loginAs($alice);
        $before = $created[0]->getId() - 1;

        $first = $this->getJson('/chat/messages?after='.$before);
        self::assertCount(200, $first['messages']);
        self::assertTrue($first['hasMore']);
        self::assertSame($created[0]->getId(), $first['messages'][0]['id']);

        $rest = $this->getJson('/chat/messages?after='.$first['messages'][199]['id']);
        self::assertSame(array_map(static fn ($m) => $m->getId(), array_slice($created, 200)), array_column($rest['messages'], 'id'));
        self::assertFalse($rest['hasMore']);
    }

    #[DataProvider('invalidCursors')]
    public function testInvalidCursorsAreRejected(string $query): void
    {
        $this->loginAs($this->createUser());

        $this->getJson('/chat/messages?'.$query);

        self::assertResponseStatusCodeSame(400);
    }

    public static function invalidCursors(): iterable
    {
        yield 'text' => ['before=abc'];
        yield 'zero before' => ['before=0'];
        yield 'negative' => ['after=-3'];
        yield 'both cursors' => ['before=5&after=2'];
    }

    public function testMessagesAreSerializedWithAuthorAndAvatarFallback(): void
    {
        $alice = $this->createUser('alice');
        $this->createMessage($alice, 'Coucou', new \DateTime('2026-10-17 14:32:00'));
        $this->loginAs($alice);

        $message = $this->getJson('/chat/messages')['messages'][0];

        self::assertSame('Coucou', $message['content']);
        self::assertSame($alice->getId(), $message['author']['id']);
        self::assertSame('alice', $message['author']['username']);
        self::assertNull($message['author']['avatarUrl']);
        self::assertSame('2026-10-17T14:32:00+02:00', $message['createdAt'], 'Paris local time with its offset');
    }

    public function testMessagesWithoutAuthorAreSupported(): void
    {
        $alice = $this->createUser('alice');
        $orphan = $this->createMessage($alice, 'Ancien message');
        $orphan->setAuthor(null);
        $this->em()->flush();
        $this->loginAs($alice);

        $messages = $this->getJson('/chat/messages')['messages'];

        self::assertNull($messages[array_key_last($messages)]['author']);
    }
}
