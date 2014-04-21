<?php

namespace ComTSo\ForumBundle\Entity\Behavior;

trait ContentEditable {

	/**
	 * Body of the content
	 *
	 * @var string
	 * @Doctrine\ORM\Mapping\Column(type="text")
	 */
	protected $content;

	public function setContent($text = null) {
		$this->content = $text;
		return $this;
	}

	public function getContent() {
		return $this->content;
	}

}
