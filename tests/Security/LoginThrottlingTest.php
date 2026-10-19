<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

/**
 * 5 failed attempts per username + client address within 15 minutes, then even the right password is refused.
 * The limiter counts in memory in the test environment (config/packages/framework.yaml): the throttling tests keep one
 * kernel alive with disableReboot(), every other test starts with empty counters.
 */
class LoginThrottlingTest extends AppWebTestCase
{
    private function attempt(string $username, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $username, '_password' => $password], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function failTimes(string $username, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->attempt($username, 'wrong-password');
            self::assertResponseRedirects('/login');
        }
    }

    private function assertLoggedIn(bool $loggedIn): void
    {
        $this->client->request('GET', '/');
        $loggedIn ? self::assertResponseIsSuccessful() : self::assertResponseRedirects('/login');
    }

    public function testTheRightPasswordIsRefusedOnceTheAttemptsAreUsedUp(): void
    {
        $this->createUser('alice', password: 'Correct-horse-1');
        $this->client->disableReboot();

        $this->failTimes('alice', 5);
        $this->attempt('alice', 'Correct-horse-1');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorExists('[role=alert]');
        self::assertSelectorTextNotContains('[role=alert]', 'Identifiants invalides', 'the lockout is announced, it is not mistaken for a wrong password');
        $this->assertLoggedIn(false);
    }

    public function testFewerFailuresDoNotLockTheMemberOut(): void
    {
        $this->createUser('alice', password: 'Correct-horse-1');
        $this->client->disableReboot();

        $this->failTimes('alice', 4);
        $this->attempt('alice', 'Correct-horse-1');

        self::assertResponseRedirects('/');
        $this->assertLoggedIn(true);
    }

    public function testAnotherMemberIsNotAffected(): void
    {
        $this->createUser('alice', password: 'Correct-horse-1');
        $this->createUser('bob', password: 'Another-horse-2');
        $this->client->disableReboot();

        $this->failTimes('alice', 6);
        $this->attempt('bob', 'Another-horse-2');

        self::assertResponseRedirects('/');
    }

    public function testASuccessfulLoginStartsTheCountAgain(): void
    {
        $this->createUser('alice', password: 'Correct-horse-1');
        $this->client->disableReboot();

        $this->failTimes('alice', 4);
        $this->attempt('alice', 'Correct-horse-1');
        self::assertResponseRedirects('/');
        $this->logOut();

        $this->failTimes('alice', 4);
        $this->attempt('alice', 'Correct-horse-1');

        self::assertResponseRedirects('/');
    }

    /** The upgrade of the old forum hash (migrate_from) is part of a successful login: the throttling must not get in its way. */
    public function testAMemberStillOnALegacyHashCanLogInAfterAFewMistakes(): void
    {
        $user = $this->createUser('marie');
        $salted = 'Old-pass-1{abcsalt123}';
        $digest = hash('sha512', $salted, true);
        for ($i = 1; $i < 5000; ++$i) {
            $digest = hash('sha512', $digest.$salted, true);
        }
        $user->setSalt('abcsalt123')->setPassword(base64_encode($digest));
        $this->em()->flush();
        $this->client->disableReboot();

        $this->failTimes('marie', 3);
        $this->attempt('marie', 'Old-pass-1');

        self::assertResponseRedirects('/');
        $this->em()->clear();
        self::assertNull($this->em()->find(User::class, $user->getId())->getSalt(), 'the legacy hash was upgraded');
    }
}
