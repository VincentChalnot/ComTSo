<?php

namespace App\Tests\Security;

use App\Tests\Support\AppWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class PrivateForumTest extends AppWebTestCase
{
    /** The forum is completely private: no page may answer anything but a redirect to the login form. */
    #[DataProvider('privateUrls')]
    public function testAnonymousVisitorIsRedirectedToLogin(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseRedirects('/login');
    }

    public static function privateUrls(): iterable
    {
        foreach (['/', '/forums/', '/forums/general', '/forum/general/1', '/comment/1', '/gens', '/gens/alice', '/gens/alice/config',
            '/photos/1', '/photos/source/1', '/photos/1/thumb', '/recherche?q=a', '/chat', '/chat/messages', '/no/such/page'] as $url) {
            yield $url => [$url];
        }
    }

    public function testLoginPageIsReachableAnonymously(): void
    {
        $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
    }

    public function testStaticPrivateDataIsNotServedFromThePublicDirectory(): void
    {
        self::assertDirectoryDoesNotExist(static::getContainer()->getParameter('kernel.project_dir').'/public/uploads');
    }
}
