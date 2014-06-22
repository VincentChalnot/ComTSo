<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_forum")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\ForumRepository")
 */
class Forum implements Routable {

	use Behavior\Titleable;
	use Behavior\Timestampable;

	/**
	 * @ORM\Id
	 * @ORM\Column(type="string", length=32)
	 */
	protected $id;

	/**
	 * @ORM\Column(type="integer")
	 */
	protected $order;

	/**
	 * @param string $id
	 * @return Forum
	 */
	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	/**
	 * @return string 
	 */
	public function getId() {
		return $this->id;
	}

	public function getOrder() {
		return $this->order;
	}

	public function setOrder($order) {
		$this->order = $order;
		return $this;
	}
	
	public function getRoutingParameters() {
		return ['id' => $this->getId()];
	}

}
