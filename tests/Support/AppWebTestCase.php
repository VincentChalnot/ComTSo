<?php

namespace App\Tests\Support;

use App\Entity\Comment;
use App\Entity\Forum;
use App\Entity\Topic;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base class of the database-enabled functional tests.
 *
 * Every test runs inside a transaction that DAMA rolls back, so helpers can freely persist rows.
 * Prepare the test database once with: APP_ENV=test bin/console doctrine:migrations:migrate -n
 */
abstract class AppWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $username = 'alice', array $roles = [], string $password = 'password'): User
    {
        $user = (new User())
            ->setUsername($username)
            ->setEmail($username.'@example.test')
            ->setRoles($roles);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function createForum(string $id = 'general', string $title = 'Général'): Forum
    {
        $forum = (new Forum())->setId($id)->setTitle($title);
        $this->em()->persist($forum);
        $this->em()->flush();

        return $forum;
    }

    protected function createTopic(Forum $forum, User $author, string $title = 'Un sujet', ?\DateTimeInterface $createdAt = null): Topic
    {
        $topic = (new Topic())->setForum($forum)->setAuthor($author)->setTitle($title);
        if ($createdAt) {
            $topic->setCreatedAt($createdAt)->setUpdatedAt($createdAt)->setLastActivityAt($createdAt);
        }
        $this->em()->persist($topic);
        $this->em()->flush();

        return $topic;
    }

    protected function createComment(Topic $topic, User $author, string $html = '<p>Bonjour</p>', ?\DateTimeInterface $createdAt = null): Comment
    {
        $comment = (new Comment())->setTopic($topic)->setAuthor($author)->setContent($html);
        if ($createdAt) {
            $comment->setCreatedAt($createdAt)->setUpdatedAt($createdAt);
        }
        $topic->setCommentCount($topic->getCommentCount() + 1);
        $this->em()->persist($comment);
        $this->em()->flush();

        return $comment;
    }

    protected function loginAs(User $user): void
    {
        $this->client->loginUser($user);
    }

    /** Logs out the way a member does: through the masthead link, which carries the CSRF token of the logout. */
    protected function logOut(): void
    {
        $this->client->request('GET', '/');
        $this->client->click($this->client->getCrawler()->filter('.masthead-logout')->link());
    }
}
