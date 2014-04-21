<?php

namespace ComTSo\ForumBundle\Entity\Behavior;

trait Timestampable {

	/**
	 * Creation date of the entity
	 * 
	 * @var \DateTime
	 * @Gedmo\Mapping\Annotation\Timestampable(on="create")
	 * @Doctrine\ORM\Mapping\Column(name="created_at", type="datetime")
	 */
	protected $createdAt;

	/**
	 * Date of last update of the entity
	 * 
	 * @var \DateTime
	 * @Gedmo\Mapping\Annotation\Timestampable(on="update")
	 * @Doctrine\ORM\Mapping\Column(name="updated_at", type="datetime")
	 */
	protected $updatedAt;

	/**
	 * @var \DateTime
	 */
	public function getCreatedAt($format = null) {
		if ($format && $this->createdAt) {
			return $this->createdAt->format($format);
		}
		return $this->createdAt;
	}

	/**
	 * @var \DateTime
	 */
	public function getUpdatedAt($format = null) {
		if ($format && $this->updatedAt) {
			return $this->updatedAt->format($format);
		}
		return $this->updatedAt;
	}
	
	/**
	 * @var \DateTime
	 */
	public function setCreatedAt(\DateTime $date) {
		$this->createdAt = $date;
		return $this;
	}

	/**
	 * @var \DateTime
	 */
	public function setUpdatedAt(\DateTime $date) {
		$this->updatedAt = $date;
		return $this;
	}

}
