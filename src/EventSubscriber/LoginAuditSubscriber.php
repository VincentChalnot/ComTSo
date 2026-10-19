<?php

namespace App\EventSubscriber;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Failed sign-ins (wrong password, unknown pseudo, throttled) on the `audit` log channel, which production always writes:
 * the pseudo as typed (never the password), the client address and the reason class. Successful logins are not logged.
 */
#[WithMonologChannel('audit')]
#[AsEventListener(event: LoginFailureEvent::class)]
final class LoginAuditSubscriber
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        $passport = $event->getPassport();
        $badge = $passport?->hasBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class) ? $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class) : null;

        $this->logger->notice('Login failed', [
            'username' => null === $badge ? null : mb_substr($badge->getUserIdentifier(), 0, 80),
            'ip' => $event->getRequest()->getClientIp(),
            'reason' => $event->getException()::class,
        ]);
    }
}
