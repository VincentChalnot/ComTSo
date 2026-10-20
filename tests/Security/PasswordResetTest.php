<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** "Mot de passe oublié ?": e-mailed, signed, expiring (1 h), single-use link; the same neutral answer whoever asks for what. */
class PasswordResetTest extends AppWebTestCase
{
    private const NEW = 'A-brand-new-pass-2';

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        unset($_SERVER['DEFAULT_URI'], $_ENV['DEFAULT_URI']);
        parent::tearDown();
    }

    private function legacyHash(string $password, string $salt): string
    {
        $salted = $password.'{'.$salt.'}';
        $digest = hash('sha512', $salted, true);
        for ($i = 1; $i < 5000; ++$i) {
            $digest = hash('sha512', $digest.$salted, true);
        }

        return base64_encode($digest);
    }

    private function askForALink(string $identifier, array $server = []): void
    {
        $crawler = $this->client->request('GET', '/mot-de-passe/oublie', server: $server);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form(['form[identifier]' => $identifier]);
        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), server: ['HTTP_ORIGIN' => 'http://localhost'] + $server);
    }

    private function sentEmail(): Email
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    /** Path and query of the reset link of the e-mail (the host is the forum's public address, not the test client's). */
    private function resetLinkOf(Email $email): string
    {
        self::assertSame(1, preg_match('~https?://\S+~', (string) $email->getTextBody(), $m), 'the text version carries the link');
        self::assertSame(1, preg_match('~href="(https?://[^"]+/mot-de-passe/reinitialiser[^"]*)"~', (string) $email->getHtmlBody(), $h), 'the HTML version carries the link');
        self::assertSame(html_entity_decode($h[1]), $m[0], 'both versions carry the same link');

        return parse_url($m[0], \PHP_URL_PATH).'?'.parse_url($m[0], \PHP_URL_QUERY);
    }

    private function legacyUser(string $username = 'marie'): User
    {
        $user = $this->createUser($username, password: 'unused');
        $user->setSalt('abcsalt123')->setPassword($this->legacyHash('Old-pass-1', 'abcsalt123'));
        $this->em()->flush();

        return $user;
    }

    private function resetThrough(string $link, string $password = self::NEW): void
    {
        $crawler = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->form();
        $values = $form->getPhpValues();
        $values['change_password']['plainPassword'] = ['first' => $password, 'second' => $password];
        $this->client->request('POST', $form->getUri(), $values, server: ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function canLogIn(string $username, string $password): bool
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => $username, '_password' => $password], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);
        $loggedIn = '/' === parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH);
        $this->client->getCookieJar()->clear();

        return $loggedIn;
    }

    private function assertLinkIsRefused(string $link): void
    {
        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTheLoginPageLeadsToIt(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('a[href="/mot-de-passe/oublie"]'));
    }

    public function testBothPartsOfTheMailAreNotEmptyAndCarryThePseudoAndTheLink(): void
    {
        $this->createUser('alice');

        $this->askForALink('alice');

        $email = $this->sentEmail();
        $link = $this->resetLinkOf($email);
        foreach (['text' => (string) $email->getTextBody(), 'HTML' => (string) $email->getHtmlBody()] as $part => $body) {
            self::assertGreaterThan(200, \strlen(trim(strip_tags($body))), "the $part part has a real body");
            self::assertStringContainsString('alice', $body, "the $part part greets the member");
            self::assertStringContainsString(html_entity_decode(htmlspecialchars($link)), html_entity_decode($body), "the $part part carries the link");
        }
    }

    public function testAMemberAsksByPseudoAndGetsASignedLink(): void
    {
        $this->createUser('alice');

        $this->askForALink('alice');

        self::assertEmailCount(1);
        $email = $this->sentEmail();
        self::assertEmailAddressContains($email, 'to', 'alice@example.test');
        self::assertNotEmpty($email->getSubject());
        $this->client->request('GET', $this->resetLinkOf($email));
        self::assertResponseIsSuccessful();
    }

    public function testAMemberCanAskByEmailAddressToo(): void
    {
        $this->createUser('alice');

        $this->askForALink('alice@example.test');

        self::assertEmailCount(1);
    }

    public function testTheSenderIsTheConfiguredAddress(): void
    {
        $this->createUser('alice');

        $this->askForALink('alice');

        self::assertEmailAddressContains($this->sentEmail(), 'from', 'no-reply@localhost');
    }

    public function testTheLinkPointsAtTheConfiguredPublicAddressWhateverTheHostHeader(): void
    {
        $_SERVER['DEFAULT_URI'] = $_ENV['DEFAULT_URI'] = 'https://forum.example.test';
        $this->createUser('alice');

        $this->askForALink('alice', ['HTTP_HOST' => 'evil.example']);

        $text = (string) $this->sentEmail()->getTextBody();
        self::assertStringContainsString('https://forum.example.test/mot-de-passe/reinitialiser?', $text);
        self::assertStringNotContainsString('evil.example', $text);
    }

    public function testTheAnswerIsTheSameWhetherTheAccountExistsOrNot(): void
    {
        $this->createUser('alice');
        $this->client->disableReboot();

        $this->askForALink('alice');
        $known = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Location')];
        $this->client->followRedirect();
        $knownPage = $this->client->getCrawler()->filter('.alert')->text();

        $this->askForALink('nobody-by-that-name');
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->headers->get('Location')];
        $this->client->followRedirect();
        $unknownPage = $this->client->getCrawler()->filter('.alert')->text();

        self::assertSame($known, $unknown);
        self::assertSame($knownPage, $unknownPage, 'same message');
    }

    public function testNothingIsSentToUnknownDisabledOrPlaceholderAccounts(): void
    {
        $this->createUser('ghost')->setEmail('ghost@legacy.invalid');
        $this->createUser('retired')->setEnabled(false);
        $this->createUser('nomail')->setEmail('');
        $this->em()->flush();

        foreach (['nobody', 'ghost', 'ghost@legacy.invalid', 'retired', 'nomail'] as $identifier) {
            $this->askForALink($identifier);

            self::assertResponseRedirects('/login');
            self::assertEmailCount(0, message: $identifier.' got mail');
        }
    }

    public function testAMemberStillOnTheLegacyHashCanResetTheirPassword(): void
    {
        $this->legacyUser('marie');
        $this->askForALink('marie');
        $link = $this->resetLinkOf($this->sentEmail());

        $this->resetThrough($link);

        self::assertResponseRedirects('/login');
        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'marie']);
        $this->em()->refresh($user);
        self::assertNull($user->getSalt());
        self::assertStringStartsWith('$', $user->getPassword());
        self::assertTrue($this->canLogIn('marie', self::NEW));
        self::assertFalse($this->canLogIn('marie', 'Old-pass-1'));
    }

    public function testALinkWorksOnlyOnce(): void
    {
        $this->createUser('alice');
        $this->askForALink('alice');
        $link = $this->resetLinkOf($this->sentEmail());

        $this->resetThrough($link);
        self::assertResponseRedirects('/login');

        $this->assertLinkIsRefused($link);
    }

    public function testALinkDiesWhenThePasswordChangesByOtherMeans(): void
    {
        $alice = $this->createUser('alice');
        $this->askForALink('alice');
        $link = $this->resetLinkOf($this->sentEmail());

        $alice = $this->em()->find(User::class, $alice->getId());
        $alice->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($alice, 'Chosen-meanwhile-3'));
        $this->em()->flush();

        $this->assertLinkIsRefused($link);
    }

    public function testALinkExpiresAfterOneHour(): void
    {
        $clock = new MockClock();
        Clock::set($clock);
        $this->createUser('alice');
        $this->askForALink('alice');
        $link = $this->resetLinkOf($this->sentEmail());

        $clock->modify('+59 minutes');
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();

        $clock->modify('+2 minutes');
        $this->assertLinkIsRefused($link);
    }

    public function testATamperedOrUnsignedLinkIsRefused(): void
    {
        $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->askForALink('alice');
        $link = $this->resetLinkOf($this->sentEmail());
        parse_str((string) parse_url($link, \PHP_URL_QUERY), $query);

        $this->assertLinkIsRefused(parse_url($link, \PHP_URL_PATH).'?'.http_build_query(['u' => $bob->getId()] + $query), 'another member');
        $this->assertLinkIsRefused(parse_url($link, \PHP_URL_PATH).'?'.http_build_query(array_diff_key($query, ['_hash' => 1])));
        $this->assertLinkIsRefused(parse_url($link, \PHP_URL_PATH).'?'.http_build_query(['_expiration' => time() + 99999] + $query));
        $this->assertLinkIsRefused('/mot-de-passe/reinitialiser');
    }

    public function testTheNewPasswordFollowsTheSameRulesAsAChange(): void
    {
        $alice = $this->createUser('alice', password: 'Old-password-1');
        $this->askForALink('alice');
        $link = $this->resetLinkOf($this->sentEmail());

        $this->resetThrough($link, 'short');

        self::assertResponseStatusCodeSame(422);
        $alice = $this->em()->find(User::class, $alice->getId());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($alice, 'Old-password-1'));
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful('the link is still good after a refused form');
    }

    public function testANotificationFloodIsLimitedPerAccountButTheAnswerStaysTheSame(): void
    {
        $this->createUser('alice');
        $this->client->disableReboot();

        $sent = [];
        for ($i = 0; $i < 5; ++$i) {
            $this->askForALink('alice');
            self::assertResponseRedirects('/login', null, 'the answer never changes');
            $sent[] = \count(self::getMailerMessages());
        }

        self::assertSame([1, 1, 1, 0, 0], $sent, 'at most 3 per hour, silently');
    }

    public function testTheRequestsOfOneClientAreLimited(): void
    {
        $this->client->disableReboot();

        for ($i = 0; $i < 10; ++$i) {
            $this->askForALink('nobody'.$i);
            self::assertResponseRedirects('/login');
        }
        $this->askForALink('one-more');

        self::assertResponseStatusCodeSame(429);
    }

    public function testAMemberAlreadyLoggedInIsSentToTheirSettings(): void
    {
        $this->loginAs($this->createUser('alice'));

        $this->client->request('GET', '/mot-de-passe/oublie');

        self::assertResponseRedirects('/gens/alice/parametres/securite');
    }
}
