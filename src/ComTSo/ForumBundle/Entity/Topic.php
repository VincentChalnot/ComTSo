<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_topic")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\TopicRepository")
 */
class Topic {

	use Behavior\Authorable;
	use Behavior\Timestampable;
	use Behavior\Titleable;
	use Behavior\ContentEditable;

	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	protected $id;

	/**
	 * Forum of this topic
	 * @var Forum
	 * @ORM\ManyToOne(targetEntity="ComTSo\ForumBundle\Entity\Forum")
	 */
	protected $forum;

	/**
	 * Comments associated to this topic
	 * @var Comment[]
	 * @ORM\OneToMany(targetEntity="ComTSo\ForumBundle\Entity\Comment", mappedBy="topic")
	 * @ORM\OrderBy({"createdAt" = "DESC"})
	 */
	protected $comments;

	/**
	 * @ORM\Column(type="integer")
	 */
	protected $views;

	/**
	 * @ORM\Column(name="comment_count", type="integer")
	 */
	protected $commentCount;

	/**
	 * @param int $id
	 * @return Topic
	 */
	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	/**
	 * @return int 
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * @param Forum $forum
	 * @return Topic
	 */
	public function setForum(Forum $forum) {
		$this->forum = $forum;
		return $this;
	}

	/**
	 * @return Forum 
	 */
	public function getForum() {
		return $this->forum;
	}

	public function getViews() {
		return $this->views;
	}

	public function setViews($views) {
		$this->views = $views;
		return $this;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->comments = new \Doctrine\Common\Collections\ArrayCollection();
	}

	/**
	 * Add comments
	 *
	 * @param \ComTSo\ForumBundle\Entity\Comment $comments
	 * @return Topic
	 */
	public function addComment(\ComTSo\ForumBundle\Entity\Comment $comments) {
		$this->comments[] = $comments;
		return $this;
	}

	/**
	 * Remove comments
	 *
	 * @param \ComTSo\ForumBundle\Entity\Comment $comments
	 */
	public function removeComment(\ComTSo\ForumBundle\Entity\Comment $comments) {
		$this->comments->removeElement($comments);
	}

	/**
	 * Get comments
	 *
	 * @return \Doctrine\Common\Collections\Collection 
	 */
	public function getComments() {
		return $this->comments;
	}

	public function getCommentCount() {
		return $this->commentCount;
	}

	public function setCommentCount($commentCount) {
		$this->commentCount = $commentCount;
		return $this;
	}
}
