<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ChatMessageRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ChatMessageRepository extends EntityRepository
{
	public function findLasts($limit = 20) {
		$qb = $this->createQueryBuilder('c')
				->addOrderBy('c.createdAt', 'DESC')
				->setMaxResults($limit);
		return $qb->getQuery()
						->getResult();
	}
}
