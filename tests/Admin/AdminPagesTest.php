<?php

namespace App\Tests\Admin;

use App\Tests\Support\PhotoRowsTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every admin page: anonymous visitors are sent to the login, members are refused, administrators get the page. Each screen
 * adds its GET pages to {@see pages()}; the mutating actions have their own tests (and CsrfTest knows every route).
 */
class AdminPagesTest extends AdminWebTestCase
{
    use PhotoRowsTrait;

    /**
     * @return iterable<string, array{string, bool}> label => [path (ids are filled in by {@see path()}), whether the page may show members' addresses]
     */
    public static function pages(): iterable
    {
        yield 'overview' => ['/admin', false];
        yield 'users list' => ['/admin/users/', true];
        yield 'user edit' => ['/admin/users/{user}/edit', true];
        yield 'user delete confirmation' => ['/admin/users/{user}/delete', true];
        yield 'forums list' => ['/admin/forums/', false];
        yield 'forum create' => ['/admin/forums/new', false];
        yield 'forum edit' => ['/admin/forums/{forum}/edit', false];
        yield 'forum delete confirmation' => ['/admin/forums/{forum}/delete', false];
        yield 'topics list' => ['/admin/topics/', false];
        yield 'topic delete confirmation' => ['/admin/topics/{topic}/delete', false];
        yield 'comments list' => ['/admin/comments/', false];
        yield 'comment delete confirmation' => ['/admin/comments/{comment}/delete', false];
        yield 'photos list' => ['/admin/photos/', false];
        yield 'photo delete confirmation' => ['/admin/photos/{photo}/delete', false];
        yield 'quotes list' => ['/admin/quotes/', false];
        yield 'quote create' => ['/admin/quotes/new', false];
        yield 'quote edit' => ['/admin/quotes/{quote}/edit', false];
        yield 'quote delete confirmation' => ['/admin/quotes/{quote}/delete', false];
    }

    #[DataProvider('pages')]
    public function testAnonymousVisitorsAreSentToTheLogin(string $path, bool $member): void
    {
        $this->client->request('GET', $this->path($path));

        self::assertResponseRedirects('/login');
    }

    #[DataProvider('pages')]
    public function testMembersAreRefused(string $path, bool $member): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', $this->path($path));

        self::assertResponseStatusCodeSame(403);
    }

    /** The member screens are the only admin pages that may show an e-mail address: every other page never does. */
    #[DataProvider('pages')]
    public function testAdministratorsGetThePageAndOnlyTheMemberScreensMayShowAddresses(string $path, bool $member): void
    {
        $this->loginAdmin();

        $this->client->request('GET', $this->path($path));

        self::assertResponseIsSuccessful();
        if (!$member) {
            $this->assertNoEmailAddress();
        }
    }

    /** Creates what the placeholders point to (the same rows for every request of a test). */
    private function path(string $path): string
    {
        $target = $this->createUser('target');
        $forum = $this->createForum('vide', 'Vide');
        $topic = $this->createTopic($this->createForum('general', 'Général'), $target);
        $comment = $this->createComment($topic, $target);
        $quote = (new \App\Entity\Quote())->setContent('Une citation')->setOriginalAuthor('Quelqu’un');
        $this->em()->persist($quote);
        $this->em()->flush();

        return strtr($path, ['{user}' => (string) $target->getId(), '{forum}' => (string) $forum->getId(), '{quote}' => (string) $quote->getId(), '{topic}' => (string) $topic->getId(), '{comment}' => (string) $comment->getId(), '{photo}' => (string) $this->photoRow()->getId()]);
    }
}
