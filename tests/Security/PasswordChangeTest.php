<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordChangeTest extends AppWebTestCase
{
    private const CURRENT = 'Current-pass-1';
    private const NEW = 'A-brand-new-pass-2';

    private function logInThroughTheForm(string $username, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $username, '_password' => $password], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    /** Fills and submits the password form of the member's configuration page. */
    private function changePassword(string $username, string $current, string $new, ?string $confirmation = null, bool $withToken = true): void
    {
        $crawler = $this->client->request('GET', '/gens/'.$username.'/config');
        $form = $crawler->filter('form[action$="/gens/'.$username.'/mot-de-passe"]')->form();
        $values = $form->getPhpValues();
        $values['change_password']['currentPassword'] = $current;
        $values['change_password']['plainPassword'] = ['first' => $new, 'second' => $confirmation ?? $new];
        if (!$withToken) {
            unset($values['change_password']['_token']);
        }
        $this->client->request('POST', $form->getUri(), $values, server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function reload(User $user): User
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $user->getId());
    }

    private function hasher(): UserPasswordHasherInterface
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class);
    }

    public function testAMemberChangesTheirPasswordAndStaysLoggedIn(): void
    {
        $user = $this->createUser('alice', password: self::CURRENT);
        $this->loginAs($user);

        $this->changePassword('alice', self::CURRENT, self::NEW);

        self::assertResponseRedirects('/gens/alice/config');
        $user = $this->reload($user);
        self::assertTrue($this->hasher()->isPasswordValid($user, self::NEW));
        self::assertFalse($this->hasher()->isPasswordValid($user, self::CURRENT));
        $this->client->request('GET', '/gens/alice/config');
        self::assertResponseIsSuccessful('this device is signed in again on a fresh session');

        $this->logOut();
        $this->logInThroughTheForm('alice', self::NEW);
        self::assertResponseRedirects('/');
    }

    public function testAMemberStillOnTheLegacyHashChangesItAndLosesTheSalt(): void
    {
        $user = $this->createUser('marie');
        $salted = 'Old-pass-1{abcsalt123}';
        $digest = hash('sha512', $salted, true);
        for ($i = 1; $i < 5000; ++$i) {
            $digest = hash('sha512', $digest.$salted, true);
        }
        $user->setSalt('abcsalt123')->setPassword(base64_encode($digest));
        $this->em()->flush();
        $this->loginAs($user);

        $this->changePassword('marie', 'Old-pass-1', self::NEW);

        self::assertResponseRedirects('/gens/marie/config');
        $user = $this->reload($user);
        self::assertNull($user->getSalt());
        self::assertStringStartsWith('$', $user->getPassword(), 'rehashed with the native hasher');
        self::assertTrue($this->hasher()->isPasswordValid($user, self::NEW));
    }

    public function testTheCurrentPasswordIsRequired(): void
    {
        $user = $this->createUser('alice', password: self::CURRENT);
        $this->loginAs($user);

        $this->changePassword('alice', 'not-my-password', self::NEW);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->hasher()->isPasswordValid($this->reload($user), self::CURRENT), 'unchanged');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unacceptablePasswords')]
    public function testAnUnacceptableNewPasswordIsRefused(string $new, ?string $confirmation): void
    {
        $user = $this->createUser('alice', password: self::CURRENT);
        $this->loginAs($user);

        $this->changePassword('alice', self::CURRENT, $new, $confirmation);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->hasher()->isPasswordValid($this->reload($user), self::CURRENT), 'unchanged');
    }

    public static function unacceptablePasswords(): iterable
    {
        yield 'too short' => ['short-9ch', null];
        yield 'confirmation differs' => ['A-brand-new-pass-2', 'A-brand-new-pass-3'];
        yield 'empty' => ['', ''];
    }

    public function testTheFormNeedsItsCsrfToken(): void
    {
        $user = $this->createUser('alice', password: self::CURRENT);
        $this->loginAs($user);

        $this->changePassword('alice', self::CURRENT, self::NEW, withToken: false);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->hasher()->isPasswordValid($this->reload($user), self::CURRENT), 'unchanged');
    }

    public function testNobodyChangesSomebodyElsesPasswordNotEvenAnAdministrator(): void
    {
        $bob = $this->createUser('bob', password: 'Bob-password-1');
        $this->loginAs($this->createUser('root', ['ROLE_ADMIN'], 'Root-password-1'));

        $crawler = $this->client->request('GET', '/gens/bob/config');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action$="/gens/bob/mot-de-passe"]'), 'the form is not offered');

        $this->client->request('POST', '/gens/bob/mot-de-passe', ['change_password' => ['plainPassword' => ['first' => self::NEW, 'second' => self::NEW]]], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->hasher()->isPasswordValid($this->reload($bob), 'Bob-password-1'));
    }

    public function testRememberMeCookiesIssuedBeforeTheChangeStopWorking(): void
    {
        $user = $this->createUser('alice', password: self::CURRENT);
        $this->logInThroughTheForm('alice', self::CURRENT);
        $jar = $this->client->getCookieJar();
        $before = $jar->get('REMEMBERME');
        self::assertNotNull($before, 'members are remembered on their device');

        $this->changePassword('alice', self::CURRENT, self::NEW);
        self::assertResponseRedirects('/gens/alice/config');
        $after = $jar->get('REMEMBERME');
        self::assertNotNull($after, 'this device gets a fresh remember-me cookie');

        $jar->clear();
        $jar->set($before);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login', null, 'the old cookie of another device is dead');

        $jar->clear();
        $jar->set($after);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful('the new cookie works');
    }

    public function testTheRememberMeCookieIsNotReadableFromJavaScriptAndStaysOnTheSite(): void
    {
        $this->createUser('alice', password: self::CURRENT);

        $this->logInThroughTheForm('alice', self::CURRENT);

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if ('REMEMBERME' === $candidate->getName()) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
    }
}
