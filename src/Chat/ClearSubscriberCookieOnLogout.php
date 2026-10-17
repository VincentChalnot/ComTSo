<?php

namespace App\Chat;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\Exception\ExceptionInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Logging out also drops the Mercure subscriber cookie: a former member must stop receiving chat updates. */
#[AsEventListener]
class ClearSubscriberCookieOnLogout
{
    public function __construct(private readonly Authorization $authorization)
    {
    }

    public function __invoke(LogoutEvent $event): void
    {
        try {
            $this->authorization->clearCookie($event->getRequest());
        } catch (ExceptionInterface) {
            // Unusual host: no cookie was ever issued for it, nothing to clear.
        }
    }
}
