<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;

class UserPreferencesTest extends AppWebTestCase
{
    public function testMessageOrderIsConfigurable(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens/alice/config');
        $form = $crawler->selectButton('Sauvegarder')->form();
        $form['form[message_order]'] = 'desc';
        $this->client->submit($form);

        self::assertResponseRedirects('/gens/alice/config');
        $this->em()->clear();
        self::assertSame('desc', $this->em()->find($alice::class, $alice->getId())->getMessageOrder());
    }

    public function testLegacyUppercaseOrderIsUnderstood(): void
    {
        $alice = $this->createUser('alice');
        $alice->setConfig(['message_order' => 'DESC', 'bootstrap_theme' => 'flatly']);

        self::assertSame('desc', $alice->getMessageOrder());
    }

    public function testOrderDefaultsToOldestFirst(): void
    {
        self::assertSame('asc', $this->createUser('alice')->getMessageOrder());
    }

    /** Members who used a dark bootswatch theme in the old forum keep a dark interface until they choose otherwise. */
    public function testDarkLegacyThemeMapsToDarkMode(): void
    {
        $user = $this->createUser('alice');
        $user->setConfig(['bootstrap_theme' => 'slate']);
        self::assertSame('dark', $user->getTheme());

        $user->setConfig(['bootstrap_theme' => 'flatly']);
        self::assertSame('light', $user->getTheme());

        $user->setConfig(['bootstrap_theme' => 'slate', 'theme' => 'light']);
        self::assertSame('light', $user->getTheme(), 'An explicit choice wins over the legacy theme.');
    }

    public function testThemeToggleIsSavedForTheMember(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $this->client->jsonRequest('POST', '/preferences/theme', ['theme' => 'dark']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/preferences/theme', ['theme' => 'neon']);
        self::assertResponseStatusCodeSame(400);

        $this->em()->clear();
        self::assertSame('dark', $this->em()->find($alice::class, $alice->getId())->getTheme());
    }

    public function testMembersCannotChangeSomeoneElsesSettings(): void
    {
        $this->createUser('alice');
        $this->loginAs($this->createUser('mallory'));

        $this->client->request('GET', '/gens/alice/config');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/gens/alice/edit');
        self::assertResponseStatusCodeSame(403);
    }
}
