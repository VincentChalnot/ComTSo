<?php

namespace App\Chat;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/** Members seen recently on the forum (`fos_user.last_activity`, refreshed by `ActivitySubscriber`). */
class OnlineMembers
{
    /** A member counts as online when active within this interval. */
    public const WINDOW = 'PT5M';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<User> enabled members active during the last five minutes, alphabetically */
    public function list(?\DateTimeImmutable $now = null): array
    {
        $since = ($now ?? new \DateTimeImmutable())->sub(new \DateInterval(self::WINDOW));

        return $this->em->createQueryBuilder()
            ->select('u', 'a')
            ->from(User::class, 'u')
            ->leftJoin('u.avatar', 'a')
            ->where('u.enabled = true')
            ->andWhere('u.lastActivity >= :since')
            ->setParameter('since', $since, 'datetime_immutable')
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
