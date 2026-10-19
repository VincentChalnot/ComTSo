<?php

namespace App\Tests\Content;

use App\Entity\ChatMessage;
use App\Entity\Comment;
use App\Tests\Chat\RecordingHub;
use App\Tests\Support\AppWebTestCase;

/**
 * Emojis are 4-byte UTF-8: they must be stored (the text columns are utf8mb4) and shown back unchanged by every write
 * path, the rich-text ones (message, topic, private message) and the plain-text chat.
 */
class EmojiStorageTest extends AppWebTestCase
{
    /** Plain emoji, skin tone, ZWJ family, flag, variation selector, rare ideograph. */
    private const TEXT = 'Bravo 😀 👍🏽 👨‍👩‍👧 🇫🇷 ❤️ 𠀀';

    /** What the database really holds (not what the entity manager still has in memory). */
    private function stored(string $sql): string
    {
        return (string) $this->em()->getConnection()->fetchOne($sql);
    }

    /**
     * The reason emojis can be stored at all: a single `utf8` (3-byte) column would make MariaDB refuse or truncate the
     * message. A new table or column must keep the default character set of the connection (utf8mb4).
     */
    public function testNoTextColumnOfTheSchemaIsBelowUtf8mb4(): void
    {
        $connection = $this->em()->getConnection();

        $narrow = $connection->fetchAllAssociative(
            'SELECT table_name AS t, column_name AS c, character_set_name AS cs FROM information_schema.columns
             WHERE table_schema = DATABASE() AND character_set_name IS NOT NULL AND character_set_name <> :charset',
            ['charset' => 'utf8mb4'],
        );

        self::assertSame([], $narrow, 'Text columns that cannot store emojis.');
        self::assertSame('utf8mb4', $connection->fetchOne('SELECT @@character_set_client'), 'The connection itself must speak utf8mb4 (DATABASE_URL charset).');
    }

    public function testAMessageWithEmojisIsStoredAndShownIntact(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Sujet');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/forum/general/'.$topic->getId());
        $form = $crawler->filter('form[action$="/comment"]')->form();
        $values = $form->getPhpValues();
        $values['comment']['content'] = '<p>'.self::TEXT.'</p>';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        self::assertSame('<p>'.self::TEXT.'</p>', $this->stored('SELECT content FROM ctso_comment ORDER BY id DESC LIMIT 1'));
        $this->client->followRedirect();
        self::assertStringContainsString(self::TEXT, $this->client->getResponse()->getContent());

        $this->client->request('GET', '/forums/general');
        self::assertStringContainsString('😀', $this->client->getResponse()->getContent(), 'the forum page excerpts it too');
    }

    public function testEditingAMessageKeepsItsEmojis(): void
    {
        $alice = $this->createUser('alice');
        $comment = $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '<p>Avant</p>');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/comment/'.$comment->getId().'/edit');
        $form = $crawler->filter('form')->reduce(static fn ($node) => $node->filter('[name="comment[content]"]')->count() > 0)->form();
        $values = $form->getPhpValues();
        $values['comment']['content'] = '<p>Après '.self::TEXT.'</p>';
        $this->client->request('POST', $form->getUri(), $values);

        self::assertSame('<p>Après '.self::TEXT.'</p>', $this->stored('SELECT content FROM ctso_comment WHERE id = '.$comment->getId()));
    }

    public function testATopicTitleAndDescriptionKeepTheirEmojis(): void
    {
        $this->createForum();
        $this->loginAs($this->createUser('alice'));

        $crawler = $this->client->request('GET', '/forums/general/nouveau');
        $form = $crawler->filter('form')->reduce(static fn ($node) => $node->filter('[name="topic[title]"]')->count() > 0)->form();
        $values = $form->getPhpValues();
        $values['topic']['title'] = 'Fête 🎉 '.self::TEXT;
        $values['topic']['content'] = '<p>On se retrouve '.self::TEXT.'</p>';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects();

        self::assertSame('Fête 🎉 '.self::TEXT, $this->stored('SELECT title FROM ctso_topic ORDER BY id DESC LIMIT 1'));
        self::assertSame('<p>On se retrouve '.self::TEXT.'</p>', $this->stored('SELECT content FROM ctso_topic ORDER BY id DESC LIMIT 1'));
        $this->client->request('GET', '/forums/general');
        self::assertStringContainsString('Fête 🎉 '.self::TEXT, $this->client->getResponse()->getContent());
    }

    public function testAPrivateMessageKeepsItsEmojis(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('bob');
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/gens/bob');
        $form = $crawler->selectButton('Envoyer')->form();
        $values = $form->getPhpValues();
        $values['message']['content'] = '<p>Coucou '.self::TEXT.'</p>';
        $this->client->request('POST', $form->getUri(), $values);
        self::assertResponseRedirects('/gens/bob');

        self::assertSame('<p>Coucou '.self::TEXT.'</p>', $this->stored('SELECT content FROM ctso_message ORDER BY id DESC LIMIT 1'));
        $this->client->request('GET', '/gens/bob');
        self::assertStringContainsString(self::TEXT, $this->client->getResponse()->getContent());
    }

    public function testAChatMessageKeepsItsEmojisInTheDatabaseTheHistoryAndTheLiveUpdate(): void
    {
        $this->loginAs($this->createUser('alice'));
        $crawler = $this->client->request('GET', '/chat');
        $csrf = json_decode($crawler->filter('[data-vue-component="ChatRoom"]')->attr('data-props'), true, flags: \JSON_THROW_ON_ERROR)['csrf'];

        $this->client->jsonRequest('POST', '/chat/send', ['content' => self::TEXT], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(201);
        $sent = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(self::TEXT, $sent['content']);
        self::assertSame(self::TEXT, $this->stored('SELECT content FROM ctso_chat_message ORDER BY id DESC LIMIT 1'));
        $updates = static::getContainer()->get(RecordingHub::class)->updates;
        self::assertSame(self::TEXT, json_decode($updates[0]->getData(), true)['message']['content']);

        $this->client->request('GET', '/chat/messages', server: ['HTTP_ACCEPT' => 'application/json']);
        $history = json_decode($this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(self::TEXT, $history['messages'][0]['content']);
    }

    public function testEmojisAreSearchable(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->createComment($topic, $alice, '<p>Bravo 👏 à tous</p>');
        $this->createComment($topic, $alice, '<p>Bravo 😀 à tous</p>');
        $this->em()->persist((new ChatMessage())->setContent('Bravo 👏 dans le chat')->setAuthor($alice));
        $this->em()->flush();
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', '/recherche', ['q' => 'Bravo 👏']);

        self::assertSame(1, $crawler->filter('ol.result-list > li')->count(), 'one message with the applause, not the smiley');
        self::assertStringContainsString('<mark class="search-hit">Bravo 👏</mark>', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Chat 1 résultat', preg_replace('/\s+/', ' ', $crawler->filter('nav.tabs')->text()));
    }
}
