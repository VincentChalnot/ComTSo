<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;

class SearchTest extends AppWebTestCase
{
    private function search(string $term): string
    {
        $this->client->request('GET', '/recherche', ['q' => $term]);

        return $this->client->getResponse()->getContent();
    }

    public function testFindsMessagesIgnoringCaseAndAccents(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Vacances');
        $this->createComment($topic, $alice, '<p>On est allés à la Chapelle cet été</p>');
        $this->loginAs($alice);

        self::assertStringContainsString('<mark', $this->search('chapelle'));
        self::assertStringContainsString('<mark', $this->search('ETE'));
    }

    public function testFindsTextStoredWithHtmlEntities(): void
    {
        $alice = $this->createUser('alice');
        $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '<p>Qu&#39;est-ce qu&#39;on mange &amp; boit ?</p>');
        $this->loginAs($alice);

        self::assertStringContainsString('<mark', $this->search("qu'est-ce"));
        self::assertStringContainsString('<mark', $this->search('mange & boit'));
    }

    public function testSearchTermIsEscapedInResults(): void
    {
        $alice = $this->createUser('alice');
        $this->loginAs($alice);

        $html = $this->search('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testVeryShortTermsAreNotSearched(): void
    {
        $alice = $this->createUser('alice');
        $this->createComment($this->createTopic($this->createForum(), $alice), $alice, '<p>a</p>');
        $this->loginAs($alice);

        self::assertStringContainsString('au moins deux caractères', $this->search('a'));
    }

    public function testDisabledMembersAreNotListed(): void
    {
        $this->createUser('visible');
        $this->createUser('ghost')->setEnabled(false);
        $this->em()->flush();
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/gens');
        $html = $this->client->getResponse()->getContent();
        self::assertStringContainsString('visible', $html);
        self::assertStringNotContainsString('ghost', $html);
    }
}
