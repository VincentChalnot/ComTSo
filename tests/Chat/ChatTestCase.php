<?php

namespace App\Tests\Chat;

use App\Entity\ChatMessage;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

/** Helpers shared by the chat tests: messages, the page's CSRF token, JSON calls and the recorded Mercure updates. */
abstract class ChatTestCase extends AppWebTestCase
{
    protected function createMessage(User $author, string $content = 'Salut', ?\DateTimeInterface $createdAt = null): ChatMessage
    {
        $message = (new ChatMessage())->setContent($content)->setAuthor($author);
        if ($createdAt) {
            $message->setCreatedAt($createdAt)->setUpdatedAt($createdAt);
        }
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    /** @return list<ChatMessage> */
    protected function createMessages(User $author, int $count): array
    {
        $messages = [];
        for ($i = 1; $i <= $count; ++$i) {
            $messages[] = $message = (new ChatMessage())->setContent('Message '.$i)->setAuthor($author);
            $this->em()->persist($message);
        }
        $this->em()->flush();

        return $messages;
    }

    /** Opens the chat page (as the widget does) and returns the props handed to it, CSRF token included. */
    protected function openChat(): array
    {
        $crawler = $this->client->request('GET', '/chat');
        self::assertResponseIsSuccessful();

        return json_decode($crawler->filter('[data-vue-component="ChatRoom"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** POSTs JSON the way the widget does. */
    protected function postJson(string $url, array $data, ?string $csrf): array
    {
        $this->client->jsonRequest('POST', $url, $data, null === $csrf ? [] : ['HTTP_X_CSRF_TOKEN' => $csrf]);

        return $this->json();
    }

    protected function getJson(string $url): array
    {
        $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->json();
    }

    protected function json(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /** What the last request published to the (recorded) hub. @return list<\Symfony\Component\Mercure\Update> */
    protected function published(): array
    {
        return static::getContainer()->get(RecordingHub::class)->updates;
    }

    protected function hub(): RecordingHub
    {
        return static::getContainer()->get(RecordingHub::class);
    }
}
