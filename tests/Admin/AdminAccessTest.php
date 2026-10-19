<?php

namespace App\Tests\Admin;

use App\Tests\Support\AppWebTestCase;

/** The admin panel is for administrators: anonymous visitors go to the login, members are refused, the nav link is theirs only. */
class AdminAccessTest extends AppWebTestCase
{
    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testMembersAreRefusedAndDoNotSeeTheNavLink(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/');
        self::assertSame(0, $crawler->filter('.masthead-menu a[href="/admin"]')->count());
    }

    public function testAdministratorsSeeTheOverviewAndTheNavLink(): void
    {
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vue d’ensemble');
        self::assertSame(1, $crawler->filter('.masthead-menu a[href="/admin"]')->count());
        self::assertSame('page', $crawler->filter('.masthead-menu a[href="/admin"]')->attr('aria-current'));
    }
}
