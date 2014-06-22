<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_quote")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\QuoteRepository")
 */
class Quote implements Routable {

	use Behavior\Authorable;
	use Behavior\Timestampable;
	use Behavior\ContentEditable;

	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	protected $id;
	
	/**
	 * Original author of the quote (author is the user who posted it)
	 *
	 * @var string
	 * @ORM\Column(name="original_author", type="string", length=128)
	 */
	protected $originalAuthor;

	public function getId() {
		return $this->id;
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}
	
	public function setOriginalAuthor($text = null) {
		$this->originalAuthor = $text;
		return $this;
	}

	public function getOriginalAuthor() {
		return $this->originalAuthor;
	}
	
	public function getRoutingParameters() {
		return ['id' => $this->getId()];
	}
}
