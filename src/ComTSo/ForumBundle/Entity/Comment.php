<?php

namespace ComTSo\ForumBundle\Entity;

use ComTSo\ForumBundle\Entity\Topic;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_comment")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\CommentRepository")
 */
class Comment implements Routable {

	use Behavior\Authorable,
	 Behavior\Timestampable,
	 Behavior\ContentEditable;

	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	protected $id;

	/**
	 * Topic of this comment
	 * @var Topic
	 * @ORM\ManyToOne(targetEntity="ComTSo\ForumBundle\Entity\Topic", inversedBy="comments")
	 */
	protected $topic;

	public function getId() {
		return $this->id;
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	/**
	 * @param Topic $topic
	 * @return Comment
	 */
	public function setTopic(Topic $topic) {
		$this->topic = $topic;
		return $this;
	}

	/**
	 * @return Topic 
	 */
	public function getTopic() {
		return $this->topic;
	}

	public function getRoutingParameters() {
		return ['id' => $this->getId()];
	}

}
