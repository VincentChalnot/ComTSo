<?php

namespace App\Tests\Forum;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

/** The settings centre: one page per section under /gens/{pseudo}/parametres, a menu on the left, no aside. */
class SettingsCentreTest extends AppWebTestCase
{
    private const SECTIONS = ['profil', 'avatar', 'securite', 'affichage', 'statistiques'];

    public function testEverySectionIsReachableByItsOwnerWithTheMenuAndNoAside(): void
    {
        $this->loginAs($this->createUser('alice'));

        foreach (self::SECTIONS as $section) {
            $crawler = $this->client->request('GET', '/gens/alice/parametres/'.$section);

            self::assertResponseIsSuccessful($section);
            self::assertCount(0, $crawler->filter('aside'), $section.' has no aside column');
            self::assertCount(0, $crawler->filter('.layout-aside'));
            $links = $crawler->filter('.settings-nav a:not(.btn)');
            self::assertSame(['Profil', 'Avatar', 'Sécurité', 'Affichage', 'Statistiques'], $links->each(static fn ($a) => trim($a->text())));
            $current = $crawler->filter('.settings-nav a[aria-current="page"]');
            self::assertCount(1, $current, $section.' marks its menu entry');
            self::assertSame('/gens/alice/parametres/'.$section, $current->attr('href'));
        }
    }

    public function testNoSectionIsOpenToAnotherMemberAndAnAdministratorOnlyGetsTheirProfileAndDisplay(): void
    {
        $this->createUser('alice');
        $this->loginAs($this->createUser('mallory'));
        foreach (self::SECTIONS as $section) {
            $this->client->request('GET', '/gens/alice/parametres/'.$section);
            self::assertResponseStatusCodeSame(403, 'member on '.$section);
        }

        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));
        foreach (['profil', 'affichage'] as $section) {
            $crawler = $this->client->request('GET', '/gens/alice/parametres/'.$section);
            self::assertResponseIsSuccessful('administrator on '.$section);
            self::assertSame(['Profil', 'Affichage'], $crawler->filter('.settings-nav a:not(.btn)')->each(static fn ($a) => trim($a->text())));
        }
        foreach (['avatar', 'securite', 'statistiques'] as $section) {
            $this->client->request('GET', '/gens/alice/parametres/'.$section);
            self::assertResponseStatusCodeSame(403, 'administrator on '.$section);
        }
    }

    public function testTheAdministrationButtonIsForAdministratorsOnly(): void
    {
        $this->loginAs($this->createUser('alice'));
        $crawler = $this->client->request('GET', '/gens/alice/parametres/profil');
        self::assertCount(0, $crawler->filter('.settings-nav a[href="/admin"]'));

        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));
        $crawler = $this->client->request('GET', '/gens/root/parametres/profil');
        $button = $crawler->filter('.settings-nav a.btn[href="/admin"]');
        self::assertCount(1, $button);
        self::assertSame('Administration', trim($button->text()));
        self::assertCount(5, $crawler->filter('.settings-nav li'), 'a button, not a section of the menu');
    }

    public function testTheMainNavigationHasNoStatisticsLinkAndTheUserMenuReachesTheProfile(): void
    {
        $this->loginAs($this->createUser('alice'));

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('.masthead-nav a[href*="statistiques"]'));
        self::assertCount(0, $crawler->filter('.masthead-nav a:contains("Statistiques")'));
        self::assertCount(1, $crawler->filter('.masthead-user[href="/gens/alice"]'), 'the public profile is one click away in the user menu');
        self::assertCount(1, $crawler->filter('.masthead-settings[href="/gens/alice/parametres/profil"]'));
    }

    public function testTheOldConfigUrlRedirectsToTheProfileSection(): void
    {
        $this->client->request('GET', '/gens/alice/config');
        self::assertResponseRedirects('/login', null, 'members only, like every legacy URL');

        $this->loginAs($this->createUser('alice'));
        $this->client->request('GET', '/gens/alice/config');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/gens/alice/parametres/profil', 301);
    }

    public function testTheProfileSectionSavesAloneAndKeepsInvalidInputWith422(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens/alice/parametres/profil');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['user_profile[name]'] = 'Alice';
        $form['user_profile[website]'] = 'example.org';
        $this->client->submit($form);

        self::assertResponseRedirects('/gens/alice/parametres/profil');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Le profil a été enregistré.');
        $this->em()->clear();
        self::assertSame('Alice', $this->em()->find(User::class, $alice->getId())->getName());

        $crawler = $this->client->request('GET', '/gens/alice/parametres/profil');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['user_profile[name]'] = 'Typed text';
        $form['user_profile[email]'] = 'not-an-address';
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Typed text', $crawler->filter('input[name="user_profile[name]"]')->attr('value'));
        $this->em()->clear();
        self::assertSame('Alice', $this->em()->find(User::class, $alice->getId())->getName());
    }

    public function testAnAdministratorEditsAnotherMembersProfileAndDisplay(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN']));

        $crawler = $this->client->request('GET', '/gens/alice/parametres/profil');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['user_profile[name]'] = 'Alicia';
        $this->client->submit($form);
        self::assertResponseRedirects('/gens/alice/parametres/profil');

        $crawler = $this->client->request('GET', '/gens/alice/parametres/affichage');
        $form = $crawler->selectButton('Sauvegarder')->form();
        $form['form[message_order]'] = 'desc';
        $this->client->submit($form);
        self::assertResponseRedirects('/gens/alice/parametres/affichage');

        $this->em()->clear();
        $alice = $this->em()->find(User::class, $alice->getId());
        self::assertSame('Alicia', $alice->getName());
        self::assertSame('desc', $alice->getMessageOrder());
    }
}
