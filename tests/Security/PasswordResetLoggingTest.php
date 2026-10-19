<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Mail\TemplatedMailFactory;
use App\Repository\UserRepository;
use App\Security\PasswordReset;
use App\Tests\Support\AppWebTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Why a password-reset mail did (not) leave is readable in the logs, without leaking addresses or credentials. */
class PasswordResetLoggingTest extends AppWebTestCase
{
    private function reset(MailerInterface $mailer, LoggerInterface $logger): PasswordReset
    {
        return new PasswordReset(new UriSigner('secret'), $this->createStub(UrlGeneratorInterface::class), $this->createStub(UserRepository::class), $mailer, static::getContainer()->get(TemplatedMailFactory::class), 'secret', 'https://forum.test', $logger);
    }

    private function message(User $user): \Symfony\Component\Mime\Email
    {
        return static::getContainer()->get(TemplatedMailFactory::class)->create('password_reset', 'Sujet', new \Symfony\Component\Mime\Address((string) $user->getEmail()), ['username' => $user->getUsername(), 'link' => 'https://forum.test/link', 'lifetime_minutes' => 60]);
    }

    public function testATransportFailureIsLoggedAsAnErrorWithTheRecipientDomainOnly(): void
    {
        $handler = new TestHandler();
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('Failed to authenticate on SMTP server with username "SCW-SECRET-USER" using the following authenticators'));
        $user = (new User())->setUsername('alice')->setEmail('alice@mail.example.org');

        $this->reset($mailer, new Logger('audit', [$handler]))->send($user, $this->message($user));

        self::assertTrue($handler->hasErrorRecords(), 'a failed send must be an error');
        $record = $handler->getRecords()[0];
        self::assertSame('mail.example.org', $record->context['recipient_domain']);
        self::assertSame(TransportException::class, $record->context['exception']);
        $dump = json_encode([$record->message, $record->context]);
        self::assertStringNotContainsString('alice@', $dump, 'no e-mail address in the logs');
        self::assertStringNotContainsString('SCW-SECRET-USER', $dump, 'the SMTP user name is masked');
        self::assertStringContainsString('failed to authenticate', strtolower($dump), 'the reason stays readable');
    }

    public function testASuccessfulHandOverIsLoggedAtNoticeLevel(): void
    {
        $handler = new TestHandler();
        $user = (new User())->setUsername('alice')->setEmail('alice@mail.example.org');

        $this->reset($this->createStub(MailerInterface::class), new Logger('audit', [$handler]))->send($user, $this->message($user));

        self::assertTrue($handler->hasNoticeThatContains('handed to the mail transport'));
    }

    public function testEveryReasonForNotSendingLeavesAnAuditLine(): void
    {
        $handler = new TestHandler();
        $audit = static::getContainer()->get('monolog.logger.audit');
        $audit->pushHandler($handler);
        $this->createUser('alice');
        $this->em()->persist($this->createUser('placeholder')->setEmail('placeholder@legacy.invalid'));
        $this->em()->flush();
        $this->client->disableReboot();

        foreach (['nobody', 'placeholder', 'alice', 'alice', 'alice', 'alice'] as $identifier) {
            $crawler = $this->client->request('GET', '/mot-de-passe/oublie');
            $this->client->submit($crawler->filter('form')->form(['form[identifier]' => $identifier]));
            self::assertResponseRedirects('/login', null, 'the visitor always gets the same answer');
        }

        self::assertTrue($handler->hasNoticeThatContains('no matching account'));
        self::assertTrue($handler->hasNoticeThatContains('without a usable address'));
        self::assertTrue($handler->hasNoticeThatContains('per-account limit'), 'the silent 4th request of the hour is visible in the logs');
        self::assertTrue($handler->hasNoticeThatContains('handed to the mail transport'));
    }

    public function testAFailedLoginIsAuditedWithoutThePassword(): void
    {
        $handler = new TestHandler();
        static::getContainer()->get('monolog.logger.audit')->pushHandler($handler);
        $this->createUser('alice');
        $this->client->disableReboot();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Se connecter', ['_username' => 'alice', '_password' => 'wrong-pass-123'], serverParameters: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertTrue($handler->hasNoticeThatContains('Login failed'));
        $record = $handler->getRecords()[0];
        self::assertSame('alice', $record->context['username']);
        self::assertStringNotContainsString('wrong-pass-123', json_encode($record->context));
    }
}
