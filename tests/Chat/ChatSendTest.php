<?php

namespace App\Tests\Chat;

use App\Entity\ChatMessage;
use PHPUnit\Framework\Attributes\DataProvider;

class ChatSendTest extends ChatTestCase
{
    private function stored(): int
    {
        return $this->em()->getRepository(ChatMessage::class)->count([]);
    }

    public function testSendingPersistsTheMessageAndPublishesAPrivateUpdate(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];
        $before = $this->stored();

        $data = $this->postJson('/chat/send', ['content' => "  Bonjour\r\nà tous  "], $csrf);

        self::assertResponseStatusCodeSame(201);
        self::assertSame("Bonjour\nà tous", $data['content'], 'trimmed, one kind of line break');
        self::assertSame(['id' => $alice->getId(), 'username' => 'alice'], array_intersect_key($data['author'], ['id' => 1, 'username' => 1]));
        self::assertArrayHasKey('avatarUrl', $data['author']);
        self::assertSame($data['createdAt'], (new \DateTimeImmutable($data['createdAt']))->format(\DATE_ATOM), 'ISO-8601 with offset');

        self::assertSame($before + 1, $this->stored());
        $message = $this->em()->find(ChatMessage::class, $data['id']);
        self::assertSame("Bonjour\nà tous", $message->getContent());
        self::assertSame($alice->getId(), $message->getAuthor()->getId());

        $updates = $this->published();
        self::assertCount(1, $updates);
        self::assertTrue($updates[0]->isPrivate(), 'the forum is private: chat updates must never be public');
        self::assertSame(['chat'], $updates[0]->getTopics());
        $payload = json_decode($updates[0]->getData(), true);
        self::assertSame('message', $payload['type']);
        self::assertSame($data, $payload['message'], 'subscribers receive exactly what the sender got');
    }

    #[DataProvider('invalidContents')]
    public function testInvalidMessagesAreRejectedWithoutSideEffects(mixed $content, int $status): void
    {
        $this->loginAs($this->createUser());
        $csrf = $this->openChat()['csrf'];
        $before = $this->stored();

        $data = $this->postJson('/chat/send', ['content' => $content], $csrf);

        self::assertResponseStatusCodeSame($status);
        self::assertArrayHasKey('error', $data);
        self::assertSame($before, $this->stored());
        self::assertSame([], $this->published());
    }

    public static function invalidContents(): iterable
    {
        yield 'empty' => ['', 422];
        yield 'blank' => ["  \n\t \r\n ", 422];
        yield 'only control characters' => ["\x00\x07", 422];
        yield 'not a string' => [['x'], 422];
        yield 'too long' => [str_repeat('a', 2001), 422];
        yield 'too long in characters, not bytes' => [str_repeat('é', 2001), 422];
    }

    public function testMessageOfExactlyTheMaximumLengthIsAccepted(): void
    {
        $this->loginAs($this->createUser());
        $csrf = $this->openChat()['csrf'];

        $this->postJson('/chat/send', ['content' => str_repeat('é', 2000)], $csrf);

        self::assertResponseStatusCodeSame(201);
    }

    public function testMessagesArePlainTextAndStayVerbatim(): void
    {
        $this->loginAs($this->createUser());
        $csrf = $this->openChat()['csrf'];
        $text = '<script>alert(1)</script> <b>gras</b> https://example.test/?a=1&b=2';

        $data = $this->postJson('/chat/send', ['content' => $text], $csrf);

        self::assertSame($text, $data['content'], 'no HTML sanitizing or escaping: the widget renders text nodes only');
    }

    #[DataProvider('badTokens')]
    public function testSendingRequiresAValidCsrfToken(?string $token): void
    {
        $this->loginAs($this->createUser());
        $this->openChat();
        $before = $this->stored();

        $this->postJson('/chat/send', ['content' => 'forgé'], $token);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($before, $this->stored());
        self::assertSame([], $this->published());
    }

    public static function badTokens(): iterable
    {
        yield 'missing' => [null];
        yield 'wrong' => ['not-the-token'];
    }

    public function testAHubOutageDoesNotLoseOrFailTheMessage(): void
    {
        $this->loginAs($this->createUser());
        $csrf = $this->openChat()['csrf'];
        $before = $this->stored();
        $this->client->disableReboot();
        $this->hub()->down = true;

        $this->postJson('/chat/send', ['content' => 'Toujours là'], $csrf);

        self::assertResponseStatusCodeSame(201);
        self::assertSame($before + 1, $this->stored());
    }
}
