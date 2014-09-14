<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Table(name="ctso_topic")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\TopicRepository")
 */
class Topic implements Routable {

	use Behavior\Authorable,
	 Behavior\Timestampable,
	 Behavior\Titleable,
	 Behavior\ContentEditable;

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
	 * Comments associated to this topic
	 * @var PhotoTopic[]
	 * @ORM\OneToMany(targetEntity="ComTSo\ForumBundle\Entity\PhotoTopic", mappedBy="topic", cascade={"persist"})
	 * @ORM\OrderBy({"order" = "ASC"})
	 */
	protected $photos;

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
		$this->comments = new ArrayCollection();
		$this->photos = new ArrayCollection();
	}

	/**
	 * Add comments
	 *
	 * @param Comment $comment
	 * @return Topic
	 */
	public function addComment(Comment $comment) {
		$this->comments[] = $comment;
		return $this;
	}

	/**
	 * Remove comments
	 *
	 * @param Comment $comment
	 */
	public function removeComment(Comment $comment) {
		$this->comments->removeElement($comment);
	}

	/**
	 * Get comments
	 *
	 * @return ArrayCollection 
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

	/**
	 * Add photos
	 *
	 * @param Photo $photo
	 * @return Topic
	 */
	public function addPhoto(Photo $photo) {
		$this->photos[] = $photo;
		return $this;
	}

	/**
	 * Remove photos
	 *
	 * @param Photo $photo
	 */
	public function removePhoto(Photo $photo) {
		$this->photos->removeElement($photo);
	}

	/**
	 * Get photos
	 *
	 * @return ArrayCollection 
	 */
	public function getPhotos() {
		return $this->photos;
	}

	public function getRoutingParameters() {
		return ['id' => $this->getId(), 'forumId' => $this->getForum()->getId()];
	}

}
