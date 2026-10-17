<?php

namespace App\Tests\Forum;

use App\Entity\Comment;
use App\Tests\Support\AppWebTestCase;

class CommentPermissionsTest extends AppWebTestCase
{
    private function deleteToken(Comment $comment): string
    {
        $crawler = $this->client->request('GET', "/comment/{$comment->getId()}");

        return $crawler->filter('form[action$="/delete"] input[name=_token]')->attr('value');
    }

    public function testAuthorCanDeleteTheirMessageAndTheCounterFollows(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $comment = $this->createComment($topic, $alice);
        $this->loginAs($alice);

        $this->client->request('POST', "/comment/{$comment->getId()}/delete", ['_token' => $this->deleteToken($comment)]);

        self::assertResponseRedirects();
        $this->em()->clear();
        self::assertNull($this->em()->find(Comment::class, $comment->getId()));
        self::assertSame(0, $this->em()->find($topic::class, $topic->getId())->getCommentCount());
    }

    public function testAnotherMemberCannotEditOrDeleteTheMessage(): void
    {
        $alice = $this->createUser('alice');
        $mallory = $this->createUser('mallory');
        $topic = $this->createTopic($this->createForum(), $alice);
        $comment = $this->createComment($topic, $alice);
        $this->loginAs($alice);
        $token = $this->deleteToken($comment);

        $this->loginAs($mallory);
        $this->client->request('GET', "/comment/{$comment->getId()}/edit");
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', "/comment/{$comment->getId()}/delete", ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);

        $this->em()->clear();
        self::assertNotNull($this->em()->find(Comment::class, $comment->getId()));
    }

    public function testAdministratorCanModerate(): void
    {
        $alice = $this->createUser('alice');
        $admin = $this->createUser('root', ['ROLE_ADMIN']);
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice);
        $this->loginAs($admin);

        $this->client->request('GET', "/comment/{$comment->getId()}/edit");

        self::assertResponseIsSuccessful();
    }

    public function testDeleteNeedsAValidCsrfToken(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice);
        $this->loginAs($alice);

        $this->client->request('POST', "/comment/{$comment->getId()}/delete", ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertNotNull($this->em()->find(Comment::class, $comment->getId()));
    }

    public function testEmptyMessageIsRejected(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', "/forum/general/{$topic->getId()}");
        $form = $crawler->selectButton('Envoyer')->form();
        $form['comment[content]'] = '<p></p><p> </p>';
        $this->client->submit($form);

        $this->em()->clear();
        self::assertSame(0, $this->em()->find($topic::class, $topic->getId())->getCommentCount());
        self::assertCount(0, $this->em()->getRepository(Comment::class)->findAll());
    }

    public function testPostedMessageUpdatesTopicActivityAndIsSanitized(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Vieux sujet', new \DateTime('2020-01-01'));
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', "/forum/general/{$topic->getId()}");
        $form = $crawler->selectButton('Envoyer')->form();
        $form['comment[content]'] = '<p>salut</p><script>alert(1)</script><img src=x onerror=alert(2)>';
        $this->client->submit($form);

        $this->em()->clear();
        $reloaded = $this->em()->find($topic::class, $topic->getId());
        self::assertSame(1, $reloaded->getCommentCount());
        self::assertGreaterThan(new \DateTime('-1 minute'), $reloaded->getLastActivityAt());
        $stored = $this->em()->getRepository(Comment::class)->findOneBy([])->getContent();
        self::assertStringNotContainsString('<script', $stored);
        self::assertStringNotContainsString('onerror', $stored);
        self::assertStringContainsString('salut', $stored);
    }
}
