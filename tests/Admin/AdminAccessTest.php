<?php

namespace App\Tests\Admin;

use App\Tests\Support\AppWebTestCase;

/** The admin panel is for administrators: anonymous visitors go to the login, members are refused; the main navigation never links to it (the settings centre does). */
class AdminAccessTest extends AppWebTestCase
{
    public function testAnonymousVisitorsAreSentToTheLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testMembersAreRefusedAndTheNavHasNoAdminLink(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);

        $crawler = $this->client->request('GET', '/');
        self::assertSame(0, $crawler->filter('.masthead-menu a[href="/admin"]')->count());
    }

    public function testAdministratorsSeeTheOverviewButTheNavHasNoAdminLink(): void
    {
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Vue d’ensemble');
        self::assertSame(0, $crawler->filter('.masthead-menu a[href="/admin"], .masthead-menu a:contains("Administration")')->count(), 'on the admin pages');

        $crawler = $this->client->request('GET', '/');
        self::assertSame(0, $crawler->filter('.masthead-menu a[href="/admin"], .masthead-menu a:contains("Administration")')->count(), 'on the home page');
        self::assertSame(0, $crawler->filter('.masthead a[href="/admin"]')->count(), 'nowhere in the masthead');
    }
}
