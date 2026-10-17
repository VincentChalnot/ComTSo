<?php

namespace App\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;

class ChatAccessTest extends ChatTestCase
{
    /** The forum is private: nobody can read, send or mark messages as read without being logged in. */
    #[DataProvider('endpoints')]
    public function testAnonymousVisitorIsRedirectedToLogin(string $method, string $url): void
    {
        $before = $this->em()->getRepository(\App\Entity\ChatMessage::class)->count([]);

        $this->client->request($method, $url);

        self::assertResponseRedirects('/login');
        self::assertSame($before, $this->em()->getRepository(\App\Entity\ChatMessage::class)->count([]), 'nothing may be created');
        self::assertSame([], $this->published(), 'nothing may be published');
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            self::assertNotSame('mercureAuthorization', $cookie->getName(), 'no Mercure subscriber cookie for anonymous visitors');
        }
    }

    public static function endpoints(): iterable
    {
        yield 'page' => ['GET', '/chat'];
        yield 'history' => ['GET', '/chat/messages'];
        yield 'catch-up' => ['GET', '/chat/messages?after=1'];
        yield 'online members' => ['GET', '/chat/online'];
        yield 'hub state and subscriber cookie' => ['GET', '/chat/state'];
        yield 'send' => ['POST', '/chat/send'];
        yield 'mark as read' => ['POST', '/chat/read'];
    }

    public function testCanonicalUrlHasNoTrailingSlash(): void
    {
        $this->loginAs($this->createUser());

        $this->client->request('GET', '/chat/');

        self::assertResponseRedirects('/chat', 301);
    }
}
