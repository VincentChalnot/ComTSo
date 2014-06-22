<?php

namespace ComTSo\ForumBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Table(name="ctso_photo")
 * @ORM\Entity(repositoryClass="ComTSo\ForumBundle\Entity\PhotoRepository")
 */
class Photo implements Routable {

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
	 * Original filename
	 * @var string
	 * @ORM\Column(type="string", length=128, unique=true)
	 */
	protected $filename;

	/**
	 * Original filename from upload or import script
	 * @var string
	 * @ORM\Column(name="original_filename", type="string", length=128)
	 */
	protected $originalFilename;

	/**
	 * @var int
	 * @ORM\Column(type="integer")
	 */
	protected $fileSize;

	/**
	 * Mime type
	 * @var string
	 * @ORM\Column(type="string", length=128)
	 */
	protected $fileType;

	/**
	 * File's last modification date
	 * @var \DateTime
	 * @ORM\Column(name="file_modified_at", type="datetime", nullable=true)
	 */
	protected $fileModifiedAt;

	/**
	 * Picture's date
	 * @var \DateTime
	 * @ORM\Column(name="taken_at", type="datetime", nullable=true)
	 */
	protected $takenAt;

	/**
	 * @var int
	 * @ORM\Column(type="integer", nullable=true)
	 */
	protected $width;

	/**
	 * @var int
	 * @ORM\Column(type="integer", nullable=true)
	 */
	protected $height;

	/**
	 * Exif data
	 * @var string
	 * @ORM\Column(type="array", nullable=true)
	 */
	protected $exif;
	
	public function getId() {
		return $this->id;
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	public function getFilename() {
		return $this->filename;
	}

	public function getFileSize() {
		return $this->fileSize;
	}

	public function getFileType() {
		return $this->fileType;
	}

	/**
	 * @return \DateTime
	 */
	public function getFileModifiedAt($format = null) {
		if ($format && $this->fileModifiedAt) {
			return $this->fileModifiedAt->format($format);
		}
		return $this->fileModifiedAt;
	}

	public function getTakenAt($format = null) {
		if ($format && $this->takenAt) {
			return $this->takenAt->format($format);
		}
		return $this->takenAt;
	}

	public function getWidth() {
		return $this->width;
	}

	public function getHeight() {
		return $this->height;
	}

	public function getExif() {
		return $this->exif;
	}

	public function setFilename($filename) {
		$this->filename = $filename;
		return $this;
	}

	public function setFileSize($fileSize) {
		$this->fileSize = $fileSize;
		return $this;
	}

	public function setFileType($fileType) {
		$this->fileType = $fileType;
		return $this;
	}

	public function setFileModifiedAt(\DateTime $fileModifiedAt) {
		$this->fileModifiedAt = $fileModifiedAt;
		return $this;
	}

	public function setTakenAt(\DateTime $takenAt) {
		$this->takenAt = $takenAt;
		return $this;
	}

	public function setWidth($width) {
		$this->width = $width;
		return $this;
	}

	public function setHeight($height) {
		$this->height = $height;
		return $this;
	}

	public function setExif($exif) {
		$this->exif = $exif;
		return $this;
	}
	
	public function getOriginalFilename() {
		return $this->originalFilename;
	}

	public function setOriginalFilename($originalFilename) {
		$this->originalFilename = $originalFilename;
		return $this;
	}
	
	public function getRoutingParameters() {
		return ['id' => $this->getId()];
	}
	
}
