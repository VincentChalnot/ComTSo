<?php

namespace App\Tests\Admin;

use App\Entity\Forum;
use App\Forum\ForumRemover;
use Symfony\Component\DomCrawler\Crawler;

class ForumAdminTest extends AdminWebTestCase
{
    public function testTheListShowsTopicCountsInPositionOrder(): void
    {
        $admin = $this->loginAdmin();
        $second = $this->createForum('second', 'Deuxième')->setOrder(2);
        $first = $this->createForum('first', 'Premier')->setOrder(1);
        $this->em()->flush();
        $this->createTopic($second, $admin);
        $this->createTopic($second, $admin, 'Autre');

        $crawler = $this->client->request('GET', '/admin/forums/');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('table.table tbody tr')->each(fn (Crawler $tr) => preg_replace('/\s+/', ' ', trim($tr->text())));
        self::assertStringContainsString('Premier first aucun sujet', $rows[0]);
        self::assertStringContainsString('Deuxième second 2 sujets', $rows[1]);
    }

    public function testAnAdministratorCreatesAForumWhoseAddressComesFromTheTitle(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/forums/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Créer')->form();
        $form['form_forum_create[title]'] = 'Cuisine et dépendances';
        $form['form_forum_create[order]'] = '20';
        $form['form_forum_create[content]'] = '<p>Recettes <script>alert(1)</script></p>';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $forum = $this->em()->find(Forum::class, 'cuisine-et-dependances');
        self::assertNotNull($forum);
        self::assertSame(20, $forum->getOrder());
        self::assertStringNotContainsString('<script', (string) $forum->getContent(), 'rich text is sanitized like everywhere else');
        $this->client->request('GET', '/forums/cuisine-et-dependances');
        self::assertResponseIsSuccessful();
    }

    public function testASlugAlreadyTakenIsRefused(): void
    {
        $this->loginAdmin();
        $this->createForum('general', 'Général');

        $crawler = $this->client->request('GET', '/admin/forums/new');
        $form = $crawler->selectButton('Créer')->form();
        $form['form_forum_create[title]'] = 'Général';
        $crawler = $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('existe déjà', $crawler->filter('.admin-form')->text());
        self::assertSame(1, $this->em()->getRepository(Forum::class)->count([]));
    }

    public function testAnAdministratorRenamesAForumWithoutChangingItsAddress(): void
    {
        $this->loginAdmin();
        $this->createForum('general', 'Général');

        $crawler = $this->client->request('GET', '/admin/forums/general/edit');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['form_forum_edit[title]'] = 'Discussions générales';
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em()->clear();
        $forum = $this->em()->find(Forum::class, 'general');
        self::assertSame('Discussions générales', $forum->getTitle());
        self::assertSame('general', $forum->getId());
    }

    public function testAnEmptyForumIsDeletedAfterConfirmation(): void
    {
        $this->loginAdmin();
        $this->createForum('vide', 'Vide');

        $crawler = $this->client->request('GET', '/admin/forums/vide/delete');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->em()->find(Forum::class, 'vide'), 'the confirmation page changes nothing');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());

        self::assertResponseRedirects('/admin/forums/');
        $this->em()->clear();
        self::assertNull($this->em()->find(Forum::class, 'vide'));
    }

    public function testAForumWithTopicsCannotBeDeleted(): void
    {
        $admin = $this->loginAdmin();
        $forum = $this->createForum('plein', 'Plein');
        $topic = $this->createTopic($forum, $admin);

        $this->client->request('GET', '/admin/forums/plein/delete');
        self::assertResponseRedirects('/admin/forums/');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('contient encore 1 sujet', $crawler->filter('.alert-danger')->text());

        // Not even a well-formed POST (a token fetched while the forum was still empty cannot be replayed either).
        $this->client->request('POST', '/admin/forums/plein/delete', ['form_forum_delete' => ['_token' => 'whatever']]);
        self::assertResponseRedirects('/admin/forums/');
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Forum::class, 'plein'));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_topic WHERE id = ?', [$topic->getId()]));
    }

    public function testTheRemoverRefusesAForumWithTopics(): void
    {
        $forum = $this->createForum('plein', 'Plein');
        $this->createTopic($forum, $this->createUser('alice'));

        $this->expectException(\DomainException::class);

        static::getContainer()->get(ForumRemover::class)->remove($forum);
    }

    public function testDeletionNeedsTheFormToken(): void
    {
        $this->loginAdmin();
        $this->createForum('vide', 'Vide');

        $this->client->request('POST', '/admin/forums/vide/delete');

        self::assertResponseIsSuccessful(); // the confirmation page again
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Forum::class, 'vide'));
    }
}
