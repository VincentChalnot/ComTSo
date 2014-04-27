<?php

namespace ComTSo\ForumBundle\Service;

use ComTSo\ForumBundle\Entity\Photo;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Exception;
use Imagine\Image\ImagineInterface;
use PHPExiftool\Driver\Value\ValueInterface;
use PHPExiftool\Reader;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ImageUploader {

	/**
	 * @var ContainerInterface
	 */
	protected $container;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
	}

	public function handleFile($filePath, $copy = false) {
		if (!file_exists($filePath)) {
			throw new \Exception("File not found {$filePath}");
		}
		$image = $this->getImagine()->open($filePath);
		$metadatas = $this->getExifReader()->files($filePath)->first();
		$exif = [];
		foreach ($metadatas as $metadata) {
			if (ValueInterface::TYPE_BINARY !== $metadata->getValue()->getType() && $metadata->getTag() != 'Composite:ThumbnailImage') {
				$exif[(string) $metadata->getTag()] = $metadata->getValue()->asString();
			}
		}
		$modifiedAt = DateTime::createFromFormat('Y:m:d H:i:se', $exif['System:FileModifyDate']);
		$dates = [
			$modifiedAt,
			DateTime::createFromFormat('Y:m:d H:i:se', $exif['System:FileInodeChangeDate']),
		];
		foreach(['IFD0:ModifyDate', 'ExifIFD:DateTimeOriginal'] as $key) {
			if (isset($exif[$key])) {
				$dates[] = DateTime::createFromFormat('Y:m:d H:i:s', $exif[$key]);
			}
		}
		
		if (isset($exif['File:FileType'])) {
			$fileType = strtolower($exif['File:FileType']);
		} else {
			$fileType = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
		}
		$filename = uniqid().'.'.$fileType;

		$photo = new Photo;
		$photo->setFileModifiedAt($modifiedAt);
		$photo->setTakenAt(min($dates));
		$photo->setFileSize(filesize($filePath));
		$photo->setFilename($filename);
		$photo->setFileType($fileType);
		$photo->setHeight($image->getSize()->getHeight());
		$photo->setWidth($image->getSize()->getWidth());
		$photo->setExif($exif);
		
		$folder = $this->container->getParameter('comtso.photo_dir').'/originals';
		if (!file_exists($folder)) {
			if(!mkdir($folder, 0777, true)) {
				throw new Exception('Unable to create image directory');
			}
		}
		$newfilePath = $folder.'/'.$filename;
		if ($copy) {
			$success = copy($filePath, $newfilePath);
		} else {
			$success = rename($filePath, $newfilePath);
		}
		if (!$success) {
			throw new Exception('Unable to move or copy image');
		}
		return $photo;
	}

	/**
	 * 
	 * @return Registry
	 */
	protected function getDoctrine() {
		return $this->container->get('doctrine');
	}

	/**
	 * 
	 * @return ImagineInterface
	 */
	protected function getImagine() {
		return $this->container->get('liip_imagine');
	}

	/**
	 * 
	 * @return Reader
	 */
	protected function getExifReader() {
		return $this->container->get('phpexiftool.reader');
	}

}
