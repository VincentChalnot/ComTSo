<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class PhotoLikeTest extends AppWebTestCase
{
    private int $counter = 0;

    private function photo(?User $author = null): Photo
    {
        $name = 'like-'.++$this->counter.'-'.bin2hex(random_bytes(3)).'.webp';
        $photo = (new Photo())->setFilename($name)->setOriginalFilename($name)->setFileSize(1000)->setFileType('image/webp')->setAuthor($author);
        $this->em()->persist($photo);
        $this->em()->flush();

        return $photo;
    }

    private function likeCount(Photo $photo): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ctso_photo_like WHERE photo_id = ?', [$photo->getId()]);
    }

    /** @return array{liked: bool, count: int, token: string} */
    private function state(Photo $photo): array
    {
        $this->client->request('GET', "/photos/{$photo->getId()}/like");
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> the JSON answer of a fetch() toggle */
    private function toggle(Photo $photo, ?string $token = null): array
    {
        $this->client->request('POST', "/photos/{$photo->getId()}/like", ['_token' => $token ?? $this->state($photo)['token']], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testLikeThenUnlikeTogglesAndCounts(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->photo();
        $this->loginAs($alice);

        self::assertSame(['liked' => false, 'count' => 0], array_intersect_key($this->state($photo), ['liked' => 1, 'count' => 1]));
        self::assertSame(['liked' => true, 'count' => 1], $this->toggle($photo));
        self::assertTrue($this->state($photo)['liked']);
        self::assertSame(['liked' => false, 'count' => 0], $this->toggle($photo));
        self::assertSame(['liked' => true, 'count' => 1], $this->toggle($photo));
        self::assertSame(1, $this->likeCount($photo), 'Liking again never makes a second row.');
    }

    public function testEachMemberCountsOnceAndAnyoneMayLikeTheirOwnPhoto(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $photo = $this->photo($alice);

        $this->loginAs($alice);
        self::assertSame(1, $this->toggle($photo)['count'], 'Own photo.');
        $this->loginAs($bob);
        self::assertSame(2, $this->toggle($photo)['count']);
        self::assertSame(['liked' => true, 'count' => 2], array_intersect_key($this->state($photo), ['liked' => 1, 'count' => 1]));
        $this->loginAs($alice);
        self::assertSame(1, $this->toggle($photo)['count'], 'Alice taking her like back keeps Bob\'s.');
    }

    public function testJsonShapeIsLikedAndCountOnly(): void
    {
        $this->loginAs($this->createUser('alice'));

        $answer = $this->toggle($this->photo());

        self::assertEqualsCanonicalizing(['liked', 'count'], array_keys($answer));
        self::assertIsBool($answer['liked']);
        self::assertIsInt($answer['count']);
    }

    public function testWithoutJavaScriptTheToggleRedirectsBackToThePhoto(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->photo();
        $this->loginAs($alice);

        $crawler = $this->client->request('GET', "/photos/{$photo->getId()}");
        self::assertSame('false', $crawler->filter('.like-button')->attr('aria-pressed'));
        self::assertSame('J\'aime', trim($crawler->filter('.like-button span')->first()->text()));
        $this->client->submit($crawler->filter('form.like')->form());

        self::assertResponseRedirects("/photos/{$photo->getId()}#likes");
        $crawler = $this->client->followRedirect();
        self::assertSame('true', $crawler->filter('.like-button')->attr('aria-pressed'));
        self::assertSame('Je n\'aime plus', trim($crawler->filter('.like-button span')->first()->text()));
        self::assertSame('1', trim($crawler->filter('.like-count')->text()));
        self::assertStringContainsString('alice', $crawler->filter('.like-likers')->text());
    }

    public function testAnonymousVisitorsAreRefused(): void
    {
        $photo = $this->photo();

        $this->client->request('GET', "/photos/{$photo->getId()}/like");
        self::assertResponseRedirects('/login');
        $this->client->request('POST', "/photos/{$photo->getId()}/like");
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->likeCount($photo));
    }

    public function testAMissingOrForeignCsrfTokenIsRefused(): void
    {
        $this->loginAs($this->createUser('alice'));
        $photo = $this->photo();
        $other = $this->photo();
        $otherToken = $this->state($other)['token'];

        $this->client->request('POST', "/photos/{$photo->getId()}/like");
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', "/photos/{$photo->getId()}/like", ['_token' => 'nope']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', "/photos/{$photo->getId()}/like", ['_token' => $otherToken]);
        self::assertResponseStatusCodeSame(403, 'The token belongs to another photo.');

        self::assertSame(0, $this->likeCount($photo));
    }

    public function testUnknownPhotoIs404(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/photos/999999/like');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheDatabaseRefusesASecondLikeOfTheSameMember(): void
    {
        $alice = $this->createUser('alice');
        $photo = $this->photo();
        $connection = $this->em()->getConnection();
        $row = ['photo_id' => $photo->getId(), 'user_id' => $alice->getId(), 'created_at' => '2026-01-01 10:00:00'];
        $connection->insert('ctso_photo_like', $row);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('ctso_photo_like', $row);
    }

    public function testLikesVanishWithThePhotoAndWithTheMember(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $photo = $this->photo();
        $kept = $this->photo();
        $this->loginAs($alice);
        $this->toggle($photo);
        $this->toggle($kept);
        $this->loginAs($bob);
        $this->toggle($photo);
        self::assertSame(2, $this->likeCount($photo));

        $this->em()->remove($this->em()->find(User::class, $bob->getId()));
        $this->em()->flush();
        self::assertSame(1, $this->likeCount($photo), 'Deleting a member removes his likes.');

        $this->em()->remove($this->em()->find(Photo::class, $photo->getId()));
        $this->em()->flush();
        self::assertSame(0, $this->likeCount($photo), 'Deleting a photo removes its likes.');
        self::assertSame(1, $this->likeCount($kept));
    }

    public function testPhotoPageNamesWhoLikedIt(): void
    {
        $photo = $this->photo();
        foreach (['alice', 'bob', 'carol'] as $name) {
            $this->loginAs($this->createUser($name));
            $this->toggle($photo);
        }

        $crawler = $this->client->request('GET', "/photos/{$photo->getId()}");

        self::assertSame('3', trim($crawler->filter('.like-count')->text()));
        $likers = $crawler->filter('.like-likers')->text();
        foreach (['alice', 'bob', 'carol'] as $name) {
            self::assertStringContainsString($name, $likers);
        }
    }
}
