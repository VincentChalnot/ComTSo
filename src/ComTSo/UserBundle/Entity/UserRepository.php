<?php

namespace ComTSo\UserBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * UserRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class UserRepository extends EntityRepository
{
    public function findConnected()
    {
        $lastMinute = new \DateTime();
        $lastMinute->sub(new \DateInterval('PT2M'));
        $qb = $this->createQueryBuilder('u')
                ->where('u.lastActivity > :date')
                ->setParameter('date', $lastMinute)
                ->addOrderBy('u.lastActivity', 'DESC');

        return $qb->getQuery()
                        ->getResult();
    }

}
