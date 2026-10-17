<?php

namespace App\Tests\Chat;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * The hub is private: only logged-in members hold a subscriber cookie, it grants the chat topics of THAT member only,
 * and the hub itself does not accept anonymous subscribers.
 */
class ChatMercureAuthorizationTest extends ChatTestCase
{
    private function subscriberCookie(): ?Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ('mercureAuthorization' === $cookie->getName()) {
                return $cookie;
            }
        }

        return null;
    }

    private function claims(Cookie $cookie): array
    {
        $payload = explode('.', (string) $cookie->getValue())[1];

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testMembersGetASubscriberCookieLimitedToTheirTopics(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('bob');
        $this->loginAs($alice);

        $state = $this->getJson('/chat/state');

        $cookie = $this->subscriberCookie();
        self::assertNotNull($cookie, 'GET /chat/state issues the subscriber JWT cookie');
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly(), 'not readable from JavaScript');
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame('/.well-known/mercure', $cookie->getPath());
        self::assertLessThanOrEqual(3601, $this->claims($cookie)['exp'] - time(), 'short-lived JWT, renewed by the badge script');

        $claims = $this->claims($cookie);
        self::assertSame(['chat', 'chat/users/'.$alice->getId()], $claims['mercure']['subscribe']);
        self::assertArrayNotHasKey('publish', $claims['mercure'], 'browsers must never be able to publish');
        self::assertSame($claims['mercure']['subscribe'], $state['topics']);
        self::assertSame('/.well-known/mercure', $state['hubUrl'], 'same origin, so the cookie is sent');
    }

    public function testEveryChatUpdateIsPrivate(): void
    {
        $alice = $this->createUser('alice');
        $this->createMessage($this->createUser('bob'), 'un');
        $this->loginAs($alice);
        $csrf = $this->openChat()['csrf'];
        $this->client->disableReboot();

        $this->postJson('/chat/send', ['content' => 'Salut'], $csrf);
        $this->postJson('/chat/read', [], $csrf);

        $updates = $this->published();
        self::assertCount(2, $updates);
        foreach ($updates as $update) {
            self::assertTrue($update->isPrivate());
        }
    }

    public function testLoggingOutDropsTheSubscriberCookie(): void
    {
        $this->loginAs($this->createUser('alice'));
        $this->getJson('/chat/state');

        $this->client->request('GET', '/logout');

        $cookie = $this->subscriberCookie();
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isCleared(), 'the cookie is expired');
    }

    public function testTheHubDoesNotAllowAnonymousSubscribers(): void
    {
        $caddyfile = file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/frankenphp/Caddyfile');
        self::assertNotFalse($caddyfile);
        self::assertSame(1, preg_match('/^\s*mercure\s*\{(.*?)^\t\}/ms', $caddyfile, $block), 'mercure block found');

        $directives = array_filter(array_map('trim', explode("\n", $block[1])), static fn ($line) => '' !== $line && !str_starts_with($line, '#'));
        foreach ($directives as $directive) {
            self::assertDoesNotMatchRegularExpression('/^(anonymous|subscriptions)\b/', $directive, 'private forum: no anonymous subscription, no subscriptions API');
        }
    }
}
