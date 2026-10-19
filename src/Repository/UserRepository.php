<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /** Active accounts holding ROLE_ADMIN (the roles column is a JSON array of strings). */
    public function countEnabledAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.enabled = true')
            ->andWhere('u.roles LIKE :admin')
            ->setParameter('admin', '%"ROLE_ADMIN"%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<User> one page of the enabled members whose pseudo, name or activities match */
    public function search(string $term, int $limit, int $offset = 0): array
    {
        return $this->searchBuilder($term)
            ->addSelect('av')
            ->leftJoin('u.avatar', 'av') // shown next to every result: no lazy load per member
            ->orderBy('u.username')
            ->addOrderBy('u.id')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** How many members {@see self::search()} finds, whatever the page. */
    public function countSearch(string $term): int
    {
        return (int) $this->searchBuilder($term)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
    }

    private function searchBuilder(string $term): QueryBuilder
    {
        return $this->createQueryBuilder('u')
            ->where('u.enabled = true')
            ->andWhere('u.username LIKE :q OR u.name LIKE :q OR u.surname LIKE :q OR u.activities LIKE :q')
            ->setParameter('q', '%'.addcslashes($term, '%_\\').'%');
    }
}
