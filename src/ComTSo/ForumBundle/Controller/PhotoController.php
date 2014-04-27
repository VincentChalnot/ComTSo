<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Photo;
use ComTSo\ForumBundle\Lib\Utils;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PhotoController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(Photo $photo) {
		$this->viewParameters['photo'] = $photo;
		return $this->viewParameters;
	}

	public function sourceAction(Photo $photo) {
		$response = new BinaryFileResponse("{$this->container->getParameter('comtso.photo_dir')}/originals/{$photo->getFilename()}");
		if ($this->getRequest()->get('download')) {
			$filename = $photo->getTitle() ? Utils::slugify($photo->getTitle()).'.'.$photo->getFileType() : $photo->getFilename();
			$response->setContentDisposition('attachment', $filename);
		}
		return $response;
	}

	public function sourceCacheAction(Photo $photo, $filter) {
		return new BinaryFileResponse("{$this->container->getParameter('comtso.photo_dir')}/cache/{$filter}/{$photo->getFilename()}");
	}

	/**
	 * @Template()
	 */
	public function listAction() {
		$this->viewParameters['photos'] = $this->getRepository('Photo')->findAll();
		return $this->viewParameters;
	}

}
