<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * ForumRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ForumRepository extends EntityRepository
{
	public function findAll() {
		return $this->findBy([], ['order' => 'ASC']);
	}
}
