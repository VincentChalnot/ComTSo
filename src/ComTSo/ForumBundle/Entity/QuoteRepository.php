<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;

/**
 * QuoteRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class QuoteRepository extends EntityRepository {

	public function findRandom() {
		$count = $this->createQueryBuilder('q')
				->select('COUNT(q)')
				->getQuery()
				->getSingleResult(Query::HYDRATE_SINGLE_SCALAR);
		$qb = $this->createQueryBuilder('q')
				->setFirstResult(rand(0, $count - 1))
				->setMaxResults(1);
		return $qb->getQuery()
						->getSingleResult();
	}

}
