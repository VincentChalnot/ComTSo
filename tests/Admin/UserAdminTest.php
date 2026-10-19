<?php

namespace App\Tests\Admin;

use App\Entity\Comment;
use App\Entity\Forum;
use App\Entity\Topic;
use App\Entity\User;
use Symfony\Component\DomCrawler\Crawler;

class UserAdminTest extends AdminWebTestCase
{
    public function testTheListShowsTheMembersByPseudoWithoutAnyAddress(): void
    {
        $this->loginAdmin('root');
        $this->createUser('zoe');
        $this->createUser('anna', password: 'x');

        $crawler = $this->client->request('GET', '/admin/users/');

        self::assertResponseIsSuccessful();
        self::assertSame(['anna', 'root', 'zoe'], $this->pseudos($crawler));
        self::assertSelectorTextContains('.list-meta', '3 résultats');
        $this->assertNoEmailAddress();
    }

    public function testFiltersNarrowTheList(): void
    {
        $this->loginAdmin('root');
        $this->createUser('camille');
        $inactive = $this->createUser('inactif');
        $inactive->setEnabled(false);
        $recent = $this->createUser('recent');
        $recent->setLastActivity(new \DateTime('-20 days'));
        $old = $this->createUser('ancien');
        $old->setLastActivity(new \DateTime('2020-01-01 12:00'));
        $this->em()->flush();

        self::assertSame(['camille'], $this->pseudos($this->client->request('GET', '/admin/users/', ['form' => ['filters' => ['username' => 'cam']]])));
        self::assertSame(['inactif'], $this->pseudos($this->client->request('GET', '/admin/users/', ['form' => ['filters' => ['enabled' => '0']]])));
        self::assertSame(['root'], $this->pseudos($this->client->request('GET', '/admin/users/', ['form' => ['filters' => ['role' => 'admin']]])));
        self::assertSame(['ancien', 'camille', 'inactif', 'recent'], $this->pseudos($this->client->request('GET', '/admin/users/', ['form' => ['filters' => ['role' => 'member']]])));
        self::assertSame(['recent'], $this->pseudos($this->client->request('GET', '/admin/users/', ['form' => ['filters' => ['lastActivity' => ['startDate' => (new \DateTime('-30 days'))->format('Y-m-d'), 'endDate' => (new \DateTime('-10 days'))->format('Y-m-d')]]]])));
    }

    public function testASortColumnThatIsNotConfiguredIsIgnored(): void
    {
        $this->loginAdmin('root');
        // Password hashes in the reverse order of the pseudos: sorting on them would reverse the list.
        foreach (['alice' => 'c', 'bob' => 'b', 'carl' => 'a'] as $name => $hash) {
            $this->createUser($name)->setPassword($hash);
        }
        $this->em()->flush();

        foreach (['password', 'email', 'nope.nope', 'username'] as $column) {
            $crawler = $this->client->request('GET', '/admin/users/', ['form' => ['sortable' => ['config' => ['column' => $column, 'direction' => '1']]]]);

            self::assertResponseIsSuccessful($column);
            $expected = 'username' === $column ? ['root', 'carl', 'bob', 'alice'] : ['alice', 'bob', 'carl', 'root'];
            self::assertSame($expected, $this->pseudos($crawler), 'sort column '.$column);
        }
    }

    public function testASortButtonFlipsTheDirection(): void
    {
        $this->loginAdmin('root');
        $this->createUser('alice');

        $crawler = $this->client->request('GET', '/admin/users/');
        self::assertSame('ascending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));

        $crawler = $this->client->submit($crawler->filter('button.table-sort')->first()->form());

        self::assertSame(['root', 'alice'], $this->pseudos($crawler));
        self::assertSame('descending', $crawler->filter('th[aria-sort]')->attr('aria-sort'));
    }

    public function testPaginationKeepsTheSortAndTheFilters(): void
    {
        $this->loginAdmin('root');
        for ($i = 10; $i < 40; ++$i) {
            $this->createUser('m'.$i);
        }

        $crawler = $this->client->request('GET', '/admin/users/', ['form' => ['filters' => ['role' => 'member'], 'sortable' => ['config' => ['column' => 'username', 'direction' => '1']]]]);
        self::assertCount(25, $this->pseudos($crawler));
        $next = $crawler->filter('.pager a[rel="next"]')->attr('href');
        parse_str((string) parse_url($next, \PHP_URL_QUERY), $query);

        self::assertSame('member', $query['form']['filters']['role']);
        self::assertSame('1', $query['form']['sortable']['config']['direction']);
        self::assertArrayNotHasKey('0', $query['form']['sortable'], 'a page link must not press a sort button');
        $crawler = $this->client->request('GET', $next);
        self::assertSame(['m14', 'm13', 'm12', 'm11', 'm10'], $this->pseudos($crawler));
    }

    public function testAnAdministratorCanDisableAndPromoteAMember(): void
    {
        $this->loginAdmin('root');
        $alice = $this->createUser('alice');

        $crawler = $this->client->request('GET', '/admin/users/'.$alice->getId().'/edit');
        self::assertResponseIsSuccessful();
        $this->assertNoEmailAddress();
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['form_user_edit[enabled]']->untick();
        $form['form_user_edit[role]']->select('admin');
        $this->client->submit($form);

        self::assertResponseRedirects();
        $alice = $this->reloadUser($alice->getId());
        self::assertFalse($alice->isEnabled());
        self::assertContains('ROLE_ADMIN', $alice->getRoles());
        $crawler = $this->client->followRedirect();
        self::assertSelectorExists('.alert-ok');
        $this->assertNoEmailAddress();
    }

    public function testTheEditFormChangesNothingWithoutItsToken(): void
    {
        $this->loginAdmin('root');
        $alice = $this->createUser('alice');

        $this->client->request('POST', '/admin/users/'.$alice->getId().'/edit', ['form_user_edit' => ['role' => 'admin']]);

        self::assertResponseIsSuccessful(); // the form is shown again with its CSRF error
        $alice = $this->reloadUser($alice->getId());
        self::assertTrue($alice->isEnabled());
        self::assertNotContains('ROLE_ADMIN', $alice->getRoles());
    }

    public function testAnAdministratorCannotDisableOrDemoteThemselves(): void
    {
        $root = $this->loginAdmin('root');
        $this->createUser('other', ['ROLE_ADMIN']);

        foreach (['disable' => fn ($form) => $form['form_user_edit[enabled]']->untick(), 'demote' => fn ($form) => $form['form_user_edit[role]']->select('member')] as $name => $change) {
            $crawler = $this->client->request('GET', '/admin/users/'.$root->getId().'/edit');
            $form = $crawler->selectButton('Enregistrer')->form();
            $change($form);
            $crawler = $this->client->submit($form);

            self::assertResponseIsSuccessful($name);
            self::assertStringContainsString('votre propre compte', $crawler->filter('.admin-form')->text(), $name);
            $root = $this->reloadUser($root->getId());
            self::assertTrue($root->isEnabled(), $name);
            self::assertContains('ROLE_ADMIN', $root->getRoles(), $name);
        }
    }

    public function testTheLastActiveAdministratorIsProtectedFromEveryoneAndTheOthersAreNot(): void
    {
        // The actor is an administrator whose account was disabled meanwhile: still signed in, no longer counted.
        $actor = $this->loginAdmin('actor');
        $last = $this->createUser('last', ['ROLE_ADMIN']);
        $actor->setEnabled(false);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/users/'.$last->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['form_user_edit[role]']->select('member');
        $crawler = $this->client->submit($form);

        self::assertStringContainsString('dernier compte administrateur', $crawler->filter('.admin-form')->text());
        self::assertContains('ROLE_ADMIN', $this->reloadUser($last->getId())->getRoles());

        $this->client->request('GET', '/admin/users/'.$last->getId().'/delete');
        self::assertResponseRedirects('/admin/users/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'dernier compte administrateur');
        self::assertNotNull($this->reloadUser($last->getId()));
    }

    public function testAnAdministratorCanDemoteAnotherAdministrator(): void
    {
        $this->loginAdmin('root');
        $other = $this->createUser('other', ['ROLE_ADMIN']);

        $crawler = $this->client->request('GET', '/admin/users/'.$other->getId().'/edit');
        $form = $crawler->selectButton('Enregistrer')->form();
        $form['form_user_edit[role]']->select('member');
        $this->client->submit($form);

        self::assertResponseRedirects();
        self::assertNotContains('ROLE_ADMIN', $this->reloadUser($other->getId())->getRoles());
    }

    public function testRemovalAsksForConfirmationThenGoesThroughTheUserRemover(): void
    {
        $this->loginAdmin('root');
        $forum = $this->createForum();
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $aliceTopic = $this->createTopic($forum, $alice, 'Sujet d’Alice');
        $this->createComment($aliceTopic, $bob, '<p>Réponse de Bob</p>');
        $bobTopic = $this->createTopic($forum, $bob, 'Sujet de Bob');
        $this->createComment($bobTopic, $alice, '<p>Message d’Alice chez Bob</p>');
        $forumId = $forum->getId();
        $forum->setAuthor($alice);
        $this->em()->flush();

        // Confirmation page: changes nothing, says what goes along, shows no address.
        $crawler = $this->client->request('GET', '/admin/users/'.$alice->getId().'/delete');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 sujet', $crawler->filter('.admin-details')->text());
        self::assertStringContainsString('1 message dans les sujets', $crawler->filter('.admin-details')->text());
        $this->assertNoEmailAddress();
        self::assertNotNull($this->reloadUser($alice->getId()));

        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());

        self::assertResponseRedirects('/admin/users/');
        self::assertNull($this->reloadUser($alice->getId()));
        $titles = $this->em()->getConnection()->fetchFirstColumn('SELECT title FROM ctso_topic');
        self::assertSame(['Sujet de Bob'], $titles, 'her topic is gone');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_comment'), 'her messages and the ones in her topic are gone');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT comment_count FROM ctso_topic'), 'the counter of the other topic follows');
        self::assertNull($this->em()->find(Forum::class, $forumId)->getAuthor(), 'what is still referenced is kept and loses its author');
    }

    public function testRemovalNeedsTheFormToken(): void
    {
        $this->loginAdmin('root');
        $alice = $this->createUser('alice');

        $this->client->request('POST', '/admin/users/'.$alice->getId().'/delete');
        self::assertResponseIsSuccessful(); // the confirmation page again
        self::assertNotNull($this->reloadUser($alice->getId()));

        $this->client->request('POST', '/admin/users/'.$alice->getId().'/delete', ['form_user_delete' => ['_token' => 'forged']]);
        self::assertNotNull($this->reloadUser($alice->getId()));
    }

    public function testOwnAccountCannotBeRemoved(): void
    {
        $root = $this->loginAdmin('root');
        $this->createUser('other', ['ROLE_ADMIN']);

        $this->client->request('GET', '/admin/users/'.$root->getId().'/delete');

        self::assertResponseRedirects('/admin/users/');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('votre propre compte', $crawler->filter('.alert-danger')->text());
        self::assertSame(0, $crawler->filter('a[href$="/'.$root->getId().'/delete"]')->count(), 'no delete link on the own row');
        self::assertNotNull($this->reloadUser($root->getId()));
    }

    /** @return list<string> the pseudos of the rows, in order */
    private function pseudos(Crawler $crawler): array
    {
        return $crawler->filter('table.table tbody tr td:first-child a')->each(fn (Crawler $a) => trim($a->text()));
    }
}
