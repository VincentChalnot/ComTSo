<?php

namespace App\Tests\Admin;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every admin page: anonymous visitors are sent to the login, members are refused, administrators get the page. Each screen
 * adds its GET pages to {@see pages()}; the mutating actions have their own tests (and CsrfTest knows every route).
 */
class AdminPagesTest extends AdminWebTestCase
{
    /** @return iterable<string, array{string}> label => path (ids are filled in by {@see path()}) */
    public static function pages(): iterable
    {
        yield 'overview' => ['/admin'];
        yield 'users list' => ['/admin/users/'];
        yield 'user edit' => ['/admin/users/{user}/edit'];
        yield 'user delete confirmation' => ['/admin/users/{user}/delete'];
    }

    #[DataProvider('pages')]
    public function testAnonymousVisitorsAreSentToTheLogin(string $path): void
    {
        $this->client->request('GET', $this->path($path));

        self::assertResponseRedirects('/login');
    }

    #[DataProvider('pages')]
    public function testMembersAreRefused(string $path): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', $this->path($path));

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('pages')]
    public function testAdministratorsGetThePageWithoutAnyAddress(string $path): void
    {
        $this->loginAdmin();

        $this->client->request('GET', $this->path($path));

        self::assertResponseIsSuccessful();
        $this->assertNoEmailAddress();
    }

    /** Creates what the placeholders point to (the same rows for every request of a test). */
    private function path(string $path): string
    {
        $target = $this->createUser('target');

        return strtr($path, ['{user}' => (string) $target->getId()]);
    }
}
