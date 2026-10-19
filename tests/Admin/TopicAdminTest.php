<?php

namespace App\Tests\Admin;

use App\Entity\Topic;
use Symfony\Component\DomCrawler\Crawler;

class TopicAdminTest extends AdminWebTestCase
{
    public function testTheListLinksToThePublicPagesAndHasNoEditScreen(): void
    {
        $admin = $this->loginAdmin();
        $topic = $this->createTopic($this->createForum(), $admin, 'Bienvenue à tous');

        $crawler = $this->client->request('GET', '/admin/topics/');

        self::assertResponseIsSuccessful();
        self::assertSame('/forum/general/'.$topic->getId(), $crawler->filter('table.table tbody td:first-child a')->attr('href'));
        $this->client->request('GET', '/admin/topics/'.$topic->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $this->assertNoEmailAddress();
    }

    public function testFiltersOnForumAuthorAndText(): void
    {
        $alice = $this->loginAdmin('alice');
        $bob = $this->createUser('bob');
        $general = $this->createForum('general', 'Général');
        $music = $this->createForum('musique', 'Musique');
        $this->createTopic($general, $alice, 'Vacances');
        $this->createTopic($music, $alice, 'Concerts');
        $this->createTopic($music, $bob, 'Vinyles');

        self::assertSame(['Vacances'], $this->titles($this->client->request('GET', '/admin/topics/', ['form' => ['filters' => ['title' => 'vac']]])));
        self::assertSame(['Vinyles'], $this->titles($this->client->request('GET', '/admin/topics/', ['form' => ['filters' => ['author' => 'bo']]])));
        $crawler = $this->client->request('GET', '/admin/topics/', ['form' => ['filters' => ['forum' => 'musique']]]);
        self::assertEqualsCanonicalizing(['Concerts', 'Vinyles'], $this->titles($crawler));
    }

    public function testSortingByForumAndAuthorWorks(): void
    {
        $alice = $this->loginAdmin('alice');
        $zed = $this->createUser('zed');
        $a = $this->createForum('a', 'A forum');
        $z = $this->createForum('z', 'Z forum');
        $this->createTopic($z, $alice, 'Chez Z');
        $this->createTopic($a, $zed, 'Chez A');

        foreach (['forum.title' => ['Chez A', 'Chez Z'], 'author.username' => ['Chez Z', 'Chez A']] as $column => $expected) {
            $crawler = $this->client->request('GET', '/admin/topics/', ['form' => ['sortable' => ['config' => ['column' => $column, 'direction' => '0']]]]);

            self::assertResponseIsSuccessful($column);
            self::assertSame($expected, $this->titles($crawler), $column);
        }
    }

    public function testRemovalGoesThroughTheTopicRemoverAfterConfirmation(): void
    {
        $admin = $this->loginAdmin();
        $topic = $this->createTopic($this->createForum(), $admin, 'À modérer');
        $this->createComment($topic, $admin);
        $this->createComment($topic, $admin);
        $id = $topic->getId();

        $crawler = $this->client->request('GET', '/admin/topics/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2 messages', $crawler->filter('.admin-details, .alert-body')->text());
        self::assertSame(1, $this->em()->getRepository(Topic::class)->count([]), 'the confirmation page changes nothing');

        $this->client->request('POST', '/admin/topics/'.$id.'/delete');
        self::assertSame(1, $this->em()->getRepository(Topic::class)->count([]), 'no token, no removal');

        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());

        self::assertResponseRedirects('/admin/topics/');
        self::assertSame(0, $this->em()->getRepository(Topic::class)->count([]));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_comment'), 'its messages went with it (TopicRemover)');
    }

    /** @return list<string> */
    private function titles(Crawler $crawler): array
    {
        return $crawler->filter('table.table tbody tr td:first-child a')->each(fn (Crawler $a) => trim($a->text()));
    }
}
