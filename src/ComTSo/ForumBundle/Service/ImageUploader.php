<?php

namespace ComTSo\ForumBundle\Service;

use ComTSo\ForumBundle\Entity\Photo;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Exception;
use Imagine\Image\ImagineInterface;
use Oneup\UploaderBundle\Event\PostPersistEvent;
use PHPExiftool\Driver\Value\ValueInterface;
use PHPExiftool\Reader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Router;
use Symfony\Component\Security\Core\User\UserInterface;

class ImageUploader {

	/**
	 * @var ContainerInterface
	 */
	protected $container;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
	}

	/**
	 * 
	 * @param string $filePath
	 * @param bool $copy
	 * @return Photo
	 * @throws \Exception
	 */
	public function handleFile($filePath, $copy = false) {
		if ($filePath instanceof File) {
			$filePath = $filePath->getRealPath();
		}
		
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
		
		foreach ($exif as $key => $value) {
			if (strlen($value) > 1000) {
				unset($exif[$key]);
			}
		}

		$photo = new Photo;
		$photo->setFileModifiedAt($modifiedAt);
		$photo->setTakenAt(min($dates));
		$photo->setFileSize(filesize($filePath));
		$photo->setFilename($filename);
		$photo->setOriginalFilename(basename($filePath));
		$photo->setFileType($fileType);
		$photo->setHeight($image->getSize()->getHeight());
		$photo->setWidth($image->getSize()->getWidth());
		$photo->setExif($exif);
		
		$folder = $this->container->getParameter('comtso.photo_dir').'/originals';
		if (!file_exists($folder)) {
			if(!mkdir($folder, 0777, true)) {
				throw new \Exception('Unable to create image directory');
			}
		}
		$newfilePath = $folder.'/'.$filename;
		if ($copy) {
			$success = copy($filePath, $newfilePath);
			if (!$success) {
				throw new Exception("Unable to copy image: {$filePath} => {$newfilePath}");
			}
		} else {
			$success = rename($filePath, $newfilePath);
			if (!$success) {
				throw new Exception("Unable to move image: {$filePath} => {$newfilePath}");
			}
		}
		return $photo;
	}
	
	public function onUpload(PostPersistEvent $event)
    {
		$photo = $this->handleFile($event->getFile());
		try {
			$originalFiles = $event->getRequest()->files->all()['files'];
			$photo->setOriginalFilename(array_pop($originalFiles)->getClientOriginalName());
		} catch (\Exception $e) {
			// Woops ?
		}	
		$photo->setAuthor($this->getUser());
		$em = $this->getDoctrine()->getManager();
		$em->persist($photo);
		$em->flush();
		
        $response = $event->getResponse();
        $response['files'] = [
            [
                'name' => $photo->getOriginalFilename(),
                'href' => $this->getPhotoUrl($photo, 'show'),
                'url' => $this->getPhotoUrl($photo, 'large'),
                'thumbnailUrl' => $this->getPhotoUrl($photo, 'thumbnail'),
				'size' => $photo->getFileSize(),
			]
		];
    }

	/**
	 * @return Registry
	 */
	protected function getDoctrine() {
		return $this->container->get('doctrine');
	}

	/**
	 * @return ImagineInterface
	 */
	protected function getImagine() {
		return $this->container->get('liip_imagine');
	}

	/**
	 * @return Reader
	 */
	protected function getExifReader() {
		return $this->container->get('phpexiftool.reader');
	}
	
	/**
	 * @return UserInterface
	 */
	protected function getUser() {
		return $this->container->get('security.context')->getToken()->getUser();
	}
	
	/**
	 * @return Router
	 */
	protected function getPhotoUrl(Photo $photo, $filter = 'preview') {
		$router = $this->container->get('router');
		if (in_array($filter, ['show', 'edit', 'delete', 'source_original'])) {
			return $router->generate("comtso_photo_{$filter}", ['id' => $photo->getId()]);
		}
		return $router->generate('comtso_photo_source_cache', ['filter' => $filter, 'filename' => $photo->getFilename()]);
	}
}
