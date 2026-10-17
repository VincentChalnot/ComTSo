<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tracks when members are active. `last_activity` is refreshed at most once a minute; when the previous
 * activity is older than a visit gap, a new visit starts and `previous_login` keeps the end of the
 * previous one: it is what "what's new since your last visit" on the home page is relative to.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 0)]
class ActivitySubscriber
{
    public const VISIT_GAP = 'PT30M';
    public const REFRESH = 60;

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'XMLHttpRequest' === $request->headers->get('X-Requested-With') || str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->touch($user, new \DateTimeImmutable());
    }

    public function touch(User $user, \DateTimeImmutable $now): void
    {
        $last = $user->getLastActivity();
        if ($last && $now->getTimestamp() - $last->getTimestamp() < self::REFRESH) {
            return;
        }

        $newVisit = null === $last || $now > \DateTimeImmutable::createFromInterface($last)->add(new \DateInterval(self::VISIT_GAP));
        // The columns are DATETIME_MUTABLE: the managed entity must hold plain DateTime objects.
        $mutableNow = \DateTime::createFromImmutable($now);
        if ($newVisit) {
            $user->setPreviousLogin($last ? \DateTime::createFromInterface($last) : $mutableNow);
        }
        $user->setLastActivity($mutableNow);

        // Direct UPDATE: persists even when the request never flushes, without Gedmo "updated" side effects.
        $this->em->createQueryBuilder()
            ->update(User::class, 'u')
            ->set('u.lastActivity', ':now')
            ->set('u.previousLogin', ':previous')
            ->where('u.id = :id')
            ->setParameter('now', $mutableNow, 'datetime')
            ->setParameter('previous', $user->getPreviousLogin(), 'datetime')
            ->setParameter('id', $user->getId())
            ->getQuery()
            ->execute();
    }
}
