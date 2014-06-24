<?php

namespace ComTSo\ForumBundle\Entity;

use ComTSo\ForumBundle\Entity\Topic;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_photo_topic")
 * @ORM\Entity()
 */
class PhotoTopic {

	use Behavior\Authorable,
	 Behavior\Timestampable;

	/**
	 * @ORM\Id
	 * @ORM\Column(type="integer")
	 * @ORM\GeneratedValue(strategy="AUTO")
	 */
	protected $id;
	
	/**
	 * @ORM\Column(name="ord", type="integer", nullable=true)
	 */
	protected $order;
	
	/**
	 * @var Topic
	 * @ORM\ManyToOne(targetEntity="ComTSo\ForumBundle\Entity\Topic", inversedBy="photos")
	 */
	protected $topic;

	/**
	 * @var Photo
	 * @ORM\ManyToOne(targetEntity="ComTSo\ForumBundle\Entity\Photo", inversedBy="topics")
	 */
	protected $photo;

	public function getId() {
		return $this->id;
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	/**
	 * @return Topic 
	 */
	public function getTopic() {
		return $this->topic;
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
	 * @return Photo
	 */
	public function getPhoto() {
		return $this->photo;
	}

	/**
	 * 
	 * @param Photo $photo
	 * @return PhotoTopic
	 */
	public function setPhoto(Photo $photo) {
		$this->photo = $photo;
		return $this;
	}

}
