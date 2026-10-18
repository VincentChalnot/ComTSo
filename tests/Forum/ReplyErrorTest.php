<?php

namespace App\Tests\Forum;

use App\Entity\Comment;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use App\Tests\Support\PhotoFilesTrait;

/** A refused reply must not eat what the member typed: the topic page is shown again with the bound form. */
class ReplyErrorTest extends AppWebTestCase
{
    use PhotoFilesTrait;

    private User $alice;
    private string $topicUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePhotoDirectory();
        $this->alice = $this->createUser('alice');
        $this->topicUrl = '/forum/general/'.$this->createTopic($this->createForum(), $this->alice, 'Sujet')->getId();
        $this->loginAs($this->alice);
    }

    protected function tearDown(): void
    {
        $this->cleanPhotoDirectory();
        parent::tearDown();
    }

    private function reply(string $html, array $files = []): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', $this->topicUrl);
        $form = $crawler->filter('form[action$="/comment"]')->form();
        $values = $form->getPhpValues();
        $values['comment']['content'] = $html;

        return $this->client->request('POST', $form->getUri(), $values, ['comment' => ['photos' => $files]]);
    }

    private function commentCount(): int
    {
        return $this->em()->getRepository(Comment::class)->count([]);
    }

    public function testARefusedFileKeepsTheTypedTextAndExplainsWhy(): void
    {
        $crawler = $this->reply('<p>Un long message <strong>que je ne veux pas retaper</strong></p>', [$this->fakeImageUpload()]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('que je ne veux pas retaper', $crawler->filter('textarea[name="comment[content]"]')->text(), 'the editor is pre-filled with what was typed');
        self::assertGreaterThan(0, $crawler->filter('.field-error')->count(), 'the reason is shown');
        self::assertSame(0, $this->commentCount());
    }

    public function testTooManyPhotosKeepTheTypedText(): void
    {
        $files = array_map(fn (int $i) => $this->jpegUpload("p$i.jpg", 40, 30), range(1, 11));

        $crawler = $this->reply('<p>Mes vacances</p>', $files);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Mes vacances', $crawler->filter('textarea[name="comment[content]"]')->text());
        self::assertSame(0, $this->commentCount());
        self::assertSame([], $this->storedFiles());
    }

    public function testAnEmptyReplyIsRefusedOnTheTopicPage(): void
    {
        $crawler = $this->reply('<p></p>');

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThan(0, $crawler->filter('.field-error')->count());
        self::assertSelectorExists('h1', 'the topic page, not a bare error');
        self::assertSame(0, $this->commentCount());
    }

    public function testAValidReplyStillRedirectsToItsMessage(): void
    {
        $this->reply('<p>Bonjour</p>');

        self::assertResponseRedirects();
        self::assertSame(1, $this->commentCount());
    }

    public function testRefreshingTheErrorPageGoesBackToTheTopic(): void
    {
        $this->client->request('GET', $this->topicUrl.'/comment');

        self::assertResponseRedirects($this->topicUrl);
    }
}
