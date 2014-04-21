<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * TopicRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class TopicRepository extends EntityRepository
{
	public function findLastModified($limit) {
		$qb = $this->createQueryBuilder('t')
				->addOrderBy('t.updatedAt', 'DESC')
				->setMaxResults($limit);
		return $qb->getQuery()
						->getResult();
	}
}
