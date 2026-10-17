<?php

namespace App\Tests\Forum;

use App\Tests\Support\AppWebTestCase;

class FavoritesTest extends AppWebTestCase
{
    private function csrf(string $id): string
    {
        // Rendering the topic page puts the token into the session and the star form.
        return $this->client->getCrawler()->filter('form[data-star-form] input[name=_token]')->attr('value');
    }

    public function testStarAndUnstarATopic(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice, 'Sujet favori');
        $this->loginAs($alice);

        $this->client->request('GET', "/forum/general/{$topic->getId()}");
        $token = $this->csrf('star');
        $this->client->xmlHttpRequest('POST', "/forum/general/{$topic->getId()}/star", ['_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"starred":true}', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/');
        self::assertStringContainsString('Sujet favori', $this->client->getCrawler()->filter('aside')->text(), 'Favourites are listed in the sidebar.');

        $this->client->request('GET', "/forum/general/{$topic->getId()}");
        $this->client->xmlHttpRequest('POST', "/forum/general/{$topic->getId()}/unstar", ['_token' => $this->csrf('star')]);
        self::assertJsonStringEqualsJsonString('{"starred":false}', $this->client->getResponse()->getContent());

        $this->em()->clear();
        self::assertCount(0, $this->em()->find($alice::class, $alice->getId())->getStarredTopics());
    }

    public function testStarringRequiresAValidCsrfToken(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->loginAs($alice);

        $this->client->request('POST', "/forum/general/{$topic->getId()}/star", ['_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        $this->em()->clear();
        self::assertCount(0, $this->em()->find($alice::class, $alice->getId())->getStarredTopics());
    }

    public function testStarringIsNotPossibleWithAGetRequest(): void
    {
        $alice = $this->createUser('alice');
        $topic = $this->createTopic($this->createForum(), $alice);
        $this->loginAs($alice);

        $this->client->request('GET', "/forum/general/{$topic->getId()}/star");

        // Links must never change state: GET does not match the POST-only route (the fallback answers 404).
        self::assertResponseStatusCodeSame(404);
        $this->em()->clear();
        self::assertCount(0, $this->em()->find($alice::class, $alice->getId())->getStarredTopics());
    }
}
