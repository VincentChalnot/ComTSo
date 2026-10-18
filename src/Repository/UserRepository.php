<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', get_class($user)));
        }

        $user->setPassword($newHashedPassword);
        $user->setSalt(null);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /** A member by pseudo, else by e-mail address (both are unique). */
    public function findOneByUsernameOrEmail(string $identifier): ?User
    {
        return $this->findOneBy(['username' => $identifier]) ?? $this->findOneBy(['email' => $identifier]);
    }

    /** @return list<User> enabled members whose pseudo, name or activities match */
    public function search(string $term, int $limit = 30): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.enabled = true')
            ->andWhere('u.username LIKE :q OR u.name LIKE :q OR u.surname LIKE :q OR u.activities LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('u.username')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
