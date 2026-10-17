<?php

namespace App\Tests\Forum;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Tests\Support\AppWebTestCase;

class PrivateMessagesTest extends AppWebTestCase
{
    private function send(\App\Entity\User $from, \App\Entity\User $to, string $html, ?int $state = null): Message
    {
        $message = (new Message())->setAuthor($from)->setRecipient($to)->setContent($html)->setState($state);
        $this->em()->persist($message);
        $this->em()->flush();

        return $message;
    }

    public function testUnreadCountsPerSenderIgnoreMemosAndReadMessages(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $this->send($bob, $alice, '<p>1</p>');
        $this->send($bob, $alice, '<p>2</p>');
        $this->send($carol, $alice, '<p>3</p>');
        $this->send($carol, $alice, '<p>lu</p>', 1);
        $this->send($alice, $alice, '<p>memo</p>');
        $this->send($alice, $bob, '<p>sent by me</p>');

        $counts = static::getContainer()->get(MessageRepository::class)->unreadCountsBySender($alice);

        self::assertSame([$bob->getId() => 2, $carol->getId() => 1], $counts);
    }

    public function testOpeningTheConversationMarksItAsRead(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->send($bob, $alice, '<p>coucou</p>');
        $this->loginAs($alice);

        $this->client->request('GET', '/gens');
        self::assertSelectorExists('a[title="Messages privés non lus"], span[title="Messages privés non lus"]');

        $this->client->request('GET', '/gens/bob');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('coucou', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/gens');
        self::assertSelectorNotExists('span[title="Messages privés non lus"]');
    }

    public function testConversationOnlyContainsTheTwoParticipants(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $this->send($alice, $bob, '<p>alice vers bob</p>');
        $this->send($carol, $bob, '<p>secret de carol</p>');
        $this->loginAs($alice);

        $this->client->request('GET', '/gens/bob');

        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('alice vers bob', $html);
        self::assertStringNotContainsString('secret de carol', $html);
    }

    public function testSendingAMessageSanitizesItAndShowsItInTheConversation(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('bob');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens/bob');
        $form = $crawler->selectButton('Envoyer')->form();
        $form['message[content]'] = '<p>salut</p><script>alert(1)</script>';
        $this->client->submit($form);

        self::assertResponseRedirects('/gens/bob');
        $stored = $this->em()->getRepository(Message::class)->findOneBy([])->getContent();
        self::assertStringNotContainsString('<script', $stored);
    }
}
