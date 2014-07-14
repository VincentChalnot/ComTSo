<?php

namespace ComTSo\ForumBundle\Entity\Behavior;

trait Titleable {

	/**
	 * Title of the entity
	 *
	 * @var string
	 * @Doctrine\ORM\Mapping\Column(type="string", length=128)
	 */
	protected $title = '';

	public function setTitle($text) {
		$this->title = $text;
		return $this;
	}

	public function getTitle() {
		return $this->title;
	}
	
	public function __toString() {
		return $this->getTitle();
	}
}
