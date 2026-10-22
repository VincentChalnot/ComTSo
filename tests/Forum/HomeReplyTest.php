<?php

namespace App\Tests\Forum;

use App\Entity\Comment;
use App\Entity\Topic;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;
use Symfony\Component\DomCrawler\Crawler;

/** Replying to a topic straight from the home page: same write path as the topic page, but the member stays on the home page. */
class HomeReplyTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    private User $alice;
    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
        $this->alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->topic = $this->createTopic($this->createForum(), $bob, 'Sujet animé', new \DateTime('2026-01-01'));
        $this->createComment($this->topic, $bob, '<p>dernier mot de bob</p>', new \DateTime('2026-03-10 10:00'));
        $this->topic->setLastActivityAt(new \DateTime('2026-03-10 10:00'));
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    private function formName(): string
    {
        return 'reply_'.$this->topic->getId();
    }

    private function home(string $url = '/?since=2026-03-01'): Crawler
    {
        $this->loginAs($this->alice);

        return $this->client->request('GET', $url);
    }

    private function replyCount(): int
    {
        return $this->em()->getRepository(Comment::class)->count(['author' => $this->alice]);
    }

    private function submit(Crawler $home, string $html): void
    {
        $this->client->submit($home->filter('form[name="'.$this->formName().'"]')->form(), [$this->formName().'[content]' => $html]);
    }

    public function testAReplyIsPostedAndTheMemberGoesBackToTheHomePage(): void
    {
        $home = $this->home();
        $topicId = $this->topic->getId();

        $this->submit($home, '<p>Je suis partant</p>');

        self::assertResponseRedirects('/?since=2026-03-01#topic-'.$topicId);
        $this->em()->clear();
        $reply = $this->em()->getRepository(Comment::class)->findOneBy(['author' => $this->alice]);
        self::assertNotNull($reply);
        self::assertSame($topicId, $reply->getTopic()->getId());
        self::assertSame('<p>Je suis partant</p>', $reply->getContent());
        self::assertSame(2, $reply->getTopic()->getCommentCount(), 'the topic counter follows');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Je suis partant');
        self::assertSelectorExists('.alert-ok');
    }

    public function testTheFormIsTheTopicPageFormFoldedBehindAButton(): void
    {
        $home = $this->home();

        self::assertCount(1, $home->filter('details.reply-fold:not([open]) > summary'), 'folded by default, native details');
        $form = $home->filter('details.reply-fold form[name="'.$this->formName().'"]');
        self::assertSame('multipart/form-data', $form->attr('enctype'));
        self::assertCount(1, $form->filter('input[type=file][name="'.$this->formName().'[photos][]"]'), 'photo upload like the topic page');
        self::assertCount(1, $form->filter('textarea[name="'.$this->formName().'[content]"]'));
    }

    public function testAReplyWithPhotosIsStoredAndTheMemberStaysOnTheHomePage(): void
    {
        $home = $this->home();
        $form = $home->filter('form[name="'.$this->formName().'"]')->form([$this->formName().'[content]' => '<p>Voir photo</p>']);

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [$this->formName() => ['photos' => [$this->jpegUpload('p.jpg', 40, 30)]]]);

        self::assertResponseRedirects('/?since=2026-03-01#topic-'.$this->topic->getId());
        $this->em()->clear();
        $reply = $this->em()->getRepository(Comment::class)->findOneBy(['author' => $this->alice]);
        self::assertCount(1, $reply->getPhotos());
        self::assertCount(1, $this->storedFiles());
    }

    public function testARefusedPhotoRefusesTheWholeReply(): void
    {
        $home = $this->home();
        $form = $home->filter('form[name="'.$this->formName().'"]')->form([$this->formName().'[content]' => '<p>Voir photo</p>']);

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [$this->formName() => ['photos' => [$this->fakeImageUpload()]]]);

        self::assertResponseRedirects('/?since=2026-03-01#topic-'.$this->topic->getId());
        self::assertSame(0, $this->replyCount());
        self::assertSame([], $this->storedFiles());
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testAPhotoAloneIsAValidReply(): void
    {
        $home = $this->home();
        $form = $home->filter('form[name="'.$this->formName().'"]')->form();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [$this->formName() => ['photos' => [$this->jpegUpload('p.jpg', 40, 30)]]]);

        self::assertSame(1, $this->replyCount());
    }

    public function testTheReplyIsSanitizedLikeEveryOtherMessage(): void
    {
        $home = $this->home();

        $this->submit($home, '<p onclick="x()">Salut</p><script>alert(1)</script>');

        self::assertResponseRedirects();
        $this->em()->clear();
        $content = $this->em()->getRepository(Comment::class)->findOneBy(['author' => $this->alice])->getContent();
        self::assertStringContainsString('Salut', $content);
        self::assertStringNotContainsString('<script', $content);
        self::assertStringNotContainsString('onclick', $content);
    }

    public function testThePageOfTheListIsKept(): void
    {
        for ($i = 0; $i < 9; ++$i) {
            $topic = $this->createTopic($this->em()->getRepository(\App\Entity\Forum::class)->find('general'), $this->alice, 'Autre '.$i, new \DateTime('2026-03-12'));
        }
        $home = $this->home('/?since=2026-03-01&page=2');

        $form = $home->filter('form[name^="reply_"]')->form();

        self::assertStringContainsString('since=2026-03-01', $form->getUri());
        self::assertStringContainsString('page=2', $form->getUri());
    }

    public function testTheFormFollowsTheMessageOrder(): void
    {
        $posts = '//div[contains(@class, "digest-posts")]';
        $form = '//form[@name="'.$this->formName().'"]';

        $asc = $this->home();
        self::assertSame(1, $asc->filterXPath($posts.'/following::'.substr($form, 2))->count(), 'oldest first: the form is under the latest messages');
        self::assertSame(0, $asc->filterXPath($form.'/following::'.substr($posts, 2))->count());

        $this->alice->setMessageOrder('desc');
        $this->em()->flush();
        $desc = $this->client->request('GET', '/?since=2026-03-01');
        self::assertSame(1, $desc->filterXPath($form.'/following::'.substr($posts, 2))->count(), 'most recent first: the form is above the latest messages');
        self::assertSame(0, $desc->filterXPath($posts.'/following::'.substr($form, 2))->count());
    }

    public function testEveryTopicCardHasItsOwnForum(): void
    {
        $other = $this->createTopic($this->em()->getRepository(\App\Entity\Forum::class)->find('general'), $this->alice, 'Deuxième', new \DateTime('2026-03-11'));
        $this->createComment($other, $this->alice, '<p>autre</p>', new \DateTime('2026-03-11'));
        $other->setLastActivityAt(new \DateTime('2026-03-11'));
        $this->em()->flush();

        $home = $this->home();

        self::assertCount(2, $home->filter('form[name^="reply_"]'));
        self::assertCount(2, array_unique($home->filter('form[name^="reply_"] textarea')->extract(['id'])), 'no duplicated id');
    }

    public function testAnEmptyReplyIsRefusedWithAMessageAndNothingIsSaved(): void
    {
        $home = $this->home();

        $this->submit($home, '<p></p>');

        self::assertResponseRedirects('/?since=2026-03-01#topic-'.$this->topic->getId());
        self::assertSame(0, $this->replyCount());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'vide');
    }

    public function testAReplyWithoutTheFormTokenIsRefused(): void
    {
        $this->loginAs($this->alice);

        $this->client->request('POST', '/forum/general/'.$this->topic->getId().'/reply', [$this->formName() => ['content' => '<p>Forgé</p>']]);

        self::assertResponseRedirects('/#topic-'.$this->topic->getId());
        self::assertSame(0, $this->replyCount());
        $this->client->followRedirect();
        self::assertSelectorExists('.alert-danger');
    }

    public function testAnAnonymousVisitorIsSentToTheLogin(): void
    {
        $this->client->request('POST', '/forum/general/'.$this->topic->getId().'/reply', [$this->formName() => ['content' => '<p>Intrus</p>']]);

        self::assertResponseRedirects('http://localhost/login');
        self::assertSame(0, $this->em()->getRepository(Comment::class)->count(['content' => '<p>Intrus</p>']));
    }

    public function testTheRedirectCannotLeaveTheHomePage(): void
    {
        $home = $this->home();
        $form = $home->filter('form[name="'.$this->formName().'"]')->form([$this->formName().'[content]' => '<p>ok</p>']);

        $this->client->request('POST', '/forum/general/'.$this->topic->getId().'/reply?since=//evil.example&page=-3', $form->getPhpValues());

        self::assertResponseRedirects('/#topic-'.$this->topic->getId());
    }

    public function testTheHomePageStaysPrivateAndUncached(): void
    {
        $this->home();

        $cacheControl = $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
    }

    public function testTheReplyFormsAddNoQueryPerCard(): void
    {
        $count = function (): int {
            $this->home();
            $this->client->enableProfiler();
            $this->client->request('GET', '/?since=2026-03-01');

            return $this->client->getProfile()->getCollector('db')->getQueryCount();
        };
        $perCard = [];
        $previous = $count();
        for ($i = 0; $i < 3; ++$i) {
            $this->alice = $this->em()->find(User::class, $this->alice->getId());
            $topic = $this->createTopic($this->em()->find(\App\Entity\Forum::class, 'general'), $this->alice, 'Autre '.$i, new \DateTime('2026-03-12'));
            $this->createComment($topic, $this->alice, '<p>x</p>', new \DateTime('2026-03-12'));
            $now = $count();
            $perCard[] = $now - $previous;
            $previous = $now;
        }

        // 3 per card are the digest's own queries (new count, latest messages, topic photos); the forms must add none.
        self::assertSame([3, 3, 3], $perCard);
    }
}
