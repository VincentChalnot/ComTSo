<?php

namespace App\Tests\Admin;

use App\Entity\Comment;
use App\Entity\Photo;
use App\Entity\Topic;
use App\Entity\User;
use App\Forum\CommentRemover;
use App\Service\PhotoUploader;
use App\Tests\Support\PhotoFilesTrait;
use App\Tests\Support\PhotoRowsTrait;
use Symfony\Component\DomCrawler\Crawler;

class CommentAdminTest extends AdminWebTestCase
{
    use PhotoFilesTrait;
    use PhotoRowsTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    private function commentAt(\App\Entity\Topic $topic, User $author, string $html, string $date): Comment
    {
        return $this->createComment($topic, $author, $html, new \DateTime($date));
    }

    /** @return list<int> ids of the rows, in display order */
    private function ids(Crawler $crawler): array
    {
        return array_map('intval', $crawler->filter('table.table tbody tr td:first-child')->each(fn (Crawler $td) => trim($td->text())));
    }

    public function testTheListShowsTheLatestMessagesFirstAsPlainTextExcerptsLinkedToTheirPermalink(): void
    {
        $admin = $this->loginAdmin();
        $topic = $this->createTopic($this->createForum(), $admin, 'Vacances');
        $old = $this->commentAt($topic, $admin, '<p>Premier</p>', '2026-01-01 10:00');
        $new = $this->commentAt($topic, $admin, '<p>Un <strong>message</strong> <script>alert(1)</script><img src="/x.png"> en HTML</p>', '2026-02-01 10:00');
        $this->attachToComment($new, $this->photoRow());
        $this->attachToComment($new, $this->photoRow());

        $crawler = $this->client->request('GET', '/admin/comments/');

        self::assertResponseIsSuccessful();
        self::assertSame([$new->getId(), $old->getId()], $this->ids($crawler));
        $row = $crawler->filter('table.table tbody tr')->first();
        self::assertSame('Un message en HTML', trim($row->filter('td')->eq(4)->text()));
        self::assertSame('/comment/'.$new->getId(), $row->filter('td')->eq(3)->filter('a')->attr('href'));
        self::assertSame('Vacances', trim($row->filter('td')->eq(3)->text()));
        self::assertSame('root', trim($row->filter('td')->eq(2)->text()));
        self::assertSame('2', trim($row->filter('td')->eq(5)->text()), 'number of photos');
        self::assertStringNotContainsString('<strong>', $crawler->filter('table.table')->html());
        self::assertStringNotContainsString('alert(1)', $crawler->filter('table.table')->text());
        $this->assertNoEmailAddress();
    }

    public function testTheListDoesNotQueryPerRow(): void
    {
        $admin = $this->loginAdmin();
        $topic = $this->createTopic($this->createForum(), $admin);
        $this->createComment($topic, $admin);
        $this->createComment($topic, $admin);

        $this->queryCount(); // warm-up: the first request's profile also counts the test's own setup queries
        $few = $this->queryCount();

        for ($i = 0; $i < 10; ++$i) {
            $comment = $this->createComment($this->createTopic($this->createForum('f'.$i, 'Forum '.$i), $this->createUser('user'.$i)), $this->createUser('writer'.$i));
            $this->attachToComment($comment, $this->photoRow());
        }
        $this->queryCount();
        $many = $this->queryCount();

        self::assertSame($few, $many, 'the number of queries does not depend on the number of rows');
    }

    private function queryCount(): int
    {
        $this->client->enableProfiler();
        $this->client->request('GET', '/admin/comments/');
        self::assertResponseIsSuccessful();

        return $this->client->getProfile()->getCollector('db')->getQueryCount();
    }

    public function testFiltersOnTextAuthorTopicAndDate(): void
    {
        $alice = $this->loginAdmin('alice');
        $bob = $this->createUser('bob');
        $forum = $this->createForum();
        $holidays = $this->createTopic($forum, $alice, 'Vacances');
        $music = $this->createTopic($forum, $alice, 'Concerts');
        $a = $this->commentAt($holidays, $alice, '<p>La plage est belle</p>', '2026-01-10 10:00');
        $b = $this->commentAt($music, $bob, '<p>Un super concert</p>', '2026-02-10 10:00');
        $c = $this->commentAt($music, $alice, '<p>Merci pour la plage</p>', '2026-03-10 10:00');

        $filter = fn (array $filters) => $this->ids($this->client->request('GET', '/admin/comments/', ['form' => ['filters' => $filters]]));
        self::assertEqualsCanonicalizing([$a->getId(), $c->getId()], $filter(['content' => 'plage']));
        self::assertSame([$b->getId()], $filter(['author' => 'bo']));
        self::assertEqualsCanonicalizing([$b->getId(), $c->getId()], $filter(['topic' => 'concert']));
        self::assertSame([$b->getId()], $filter(['createdAt' => ['startDate' => '2026-02-01', 'endDate' => '2026-02-28']]));
    }

    public function testSortingIsRestrictedToTheAllowList(): void
    {
        $alice = $this->loginAdmin('alice');
        $zed = $this->createUser('zed');
        $forum = $this->createForum();
        $topic = $this->createTopic($forum, $alice, 'Sujet');
        $first = $this->commentAt($topic, $zed, '<p>Un</p>', '2026-01-01 10:00');
        $second = $this->commentAt($topic, $alice, '<p>Deux</p>', '2026-02-01 10:00');
        $this->em()->flush();

        $sort = fn (string $column, string $direction) => $this->ids($this->client->request('GET', '/admin/comments/', ['form' => ['sortable' => ['config' => ['column' => $column, 'direction' => $direction]]]]));
        self::assertSame([$first->getId(), $second->getId()], $sort('createdAt', '0'));
        self::assertSame([$second->getId(), $first->getId()], $sort('author.username', '0'));
        self::assertSame([$first->getId(), $second->getId()], $sort('author.username', '1'));
        // not an allowed column: replaced by the default one (latest first), never used as ORDER BY
        self::assertSame([$second->getId(), $first->getId()], $sort('content', '0'));
        self::assertSame([$second->getId(), $first->getId()], $sort('author.password', '0'));
    }

    public function testRemovalAsksForConfirmationThenRemovesTheMessageItsCounterAndTheOrphanPhotos(): void
    {
        $admin = $this->loginAdmin();
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Sujet');
        $keep = $this->createComment($topic, $alice, '<p>Reste</p>');
        $comment = $this->createComment($topic, $alice, '<p>À modérer</p>');
        $uploader = static::getContainer()->get(PhotoUploader::class);
        $orphan = $uploader->store($this->jpegUpload('seul.jpg'), $this->em()->getReference(User::class, $alice->getId()));
        $shared = $uploader->store($this->jpegUpload('partage.jpg'), $this->em()->getReference(User::class, $alice->getId()));
        $this->em()->persist($orphan);
        $this->em()->persist($shared);
        $this->em()->flush();
        $this->attachToComment($comment, $orphan);
        $this->attachToComment($comment, $shared);
        $this->attachToComment($keep, $shared);
        $id = $comment->getId();
        $orphanFile = $orphan->getFilename();
        $sharedFile = $shared->getFilename();
        self::assertSame(2, $topic->getCommentCount());

        $this->em()->clear();
        $crawler = $this->client->request('GET', '/admin/comments/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('alice', $crawler->filter('.alert-body')->text());
        self::assertStringContainsString('Sujet', $crawler->filter('.alert-body')->text());
        self::assertStringContainsString('2 photos', $crawler->filter('.alert-body')->text());
        self::assertSame(2, $this->em()->getRepository(Comment::class)->count([]), 'the confirmation page changes nothing');

        $this->client->request('POST', '/admin/comments/'.$id.'/delete');
        self::assertSame(2, $this->em()->getRepository(Comment::class)->count([]), 'no token, no removal');

        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());

        self::assertResponseRedirects('/admin/comments/');
        $this->em()->clear();
        self::assertNull($this->em()->find(Comment::class, $id));
        self::assertNotNull($this->em()->find(Comment::class, $keep->getId()));
        self::assertSame(1, $this->em()->find(Topic::class, $topic->getId())->getCommentCount());
        self::assertNull($this->em()->find(Photo::class, $orphan->getId()), 'a photo used nowhere else goes with the message');
        self::assertFileDoesNotExist($this->photoDir.'/'.$orphanFile);
        self::assertNotNull($this->em()->find(Photo::class, $shared->getId()), 'a photo still used by another message stays');
        self::assertFileExists($this->photoDir.'/'.$sharedFile);
        self::assertNotNull($this->em()->find(User::class, $admin->getId()));
    }

    public function testRemovingTheFirstMessageOfATopicLeavesTheTopicAndTheOthers(): void
    {
        $this->loginAdmin();
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $first = $this->createComment($topic, $alice, '<p>Premier</p>', new \DateTime('2026-01-01'));
        $second = $this->createComment($topic, $alice, '<p>Second</p>', new \DateTime('2026-01-02'));

        $crawler = $this->client->request('GET', '/admin/comments/'.$first->getId().'/delete');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());

        $this->em()->clear();
        self::assertNull($this->em()->find(Comment::class, $first->getId()));
        self::assertNotNull($this->em()->find(Comment::class, $second->getId()));
        self::assertSame(1, $this->em()->find(Topic::class, $topic->getId())->getCommentCount());
    }

    public function testThePublicDeletionUsesTheSameRemover(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $comment = $this->createComment($topic, $alice);

        static::getContainer()->get(CommentRemover::class)->remove($comment);

        self::assertSame(0, $this->em()->getRepository(Comment::class)->count([]));
        self::assertSame(0, $topic->getCommentCount());
    }

    public function testNothingIsRemovedOrListedForAMember(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice);
        $this->loginAs($alice);

        $this->client->request('GET', '/admin/comments/');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/admin/comments/'.$comment->getId().'/delete');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->em()->getRepository(Comment::class)->count([]));
    }
}
