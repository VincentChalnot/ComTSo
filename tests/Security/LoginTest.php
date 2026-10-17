<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

class LoginTest extends AppWebTestCase
{
    /** FOSUserBundle hash: base64(sha512 x5000 over "password{salt}"). */
    private function legacyHash(string $password, string $salt): string
    {
        $salted = $password.'{'.$salt.'}';
        $digest = hash('sha512', $salted, true);
        for ($i = 1; $i < 5000; ++$i) {
            $digest = hash('sha512', $digest.$salted, true);
        }

        return base64_encode($digest);
    }

    private function logIn(string $username, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $username, '_password' => $password], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function legacyUser(string $username, bool $enabled = true): User
    {
        $user = $this->createUser($username);
        $user->setSalt('abcsalt123')->setPassword($this->legacyHash('Old-pass-1', 'abcsalt123'))->setEnabled($enabled);
        $this->em()->flush();

        return $user;
    }

    public function testMemberImportedFromTheOldForumCanLogInAndIsRehashed(): void
    {
        $user = $this->legacyUser('marie');

        $this->logIn('marie', 'Old-pass-1');

        self::assertResponseRedirects('/');
        $this->em()->clear();
        $reloaded = $this->em()->find(User::class, $user->getId());
        self::assertNull($reloaded->getSalt(), 'The legacy salt is dropped once the password has been upgraded.');
        self::assertStringStartsWith('$', $reloaded->getPassword(), 'The legacy hash is replaced by a modern one.');

        // ...and the new hash keeps working
        $this->client->request('GET', '/logout');
        $this->logIn('marie', 'Old-pass-1');
        self::assertResponseRedirects('/');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $this->legacyUser('marie');

        $this->logIn('marie', 'wrong');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'Identifiants invalides');
    }

    public function testAccountDisabledInTheOldForumCannotLogIn(): void
    {
        $this->legacyUser('arthur', enabled: false);

        $this->logIn('arthur', 'Old-pass-1');

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role=alert]', 'désactivé');
    }
}
