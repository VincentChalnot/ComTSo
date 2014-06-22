<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Photo;
use ComTSo\ForumBundle\Lib\Utils;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class PhotoController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(Photo $photo) {
		$this->viewParameters['photo'] = $photo;
		$this->viewParameters['title'] = (string) $photo;
		return $this->viewParameters;
	}

	public function sourceAction(Photo $photo) {
		$response = new BinaryFileResponse("{$this->getConfigParameter('comtso.photo_dir')}/originals/{$photo->getFilename()}");
		if ($this->getRequest()->get('download')) {
			$filename = $photo->getTitle() ? Utils::slugify($photo->getTitle()).'.'.$photo->getFileType() : $photo->getFilename();
			$response->setContentDisposition('attachment', $filename);
		}
		return $response;
	}

	public function sourceCacheAction(Request $request, Photo $photo, $filter) {
		$filePath = "{$this->getConfigParameter('comtso.photo_dir')}/cache/{$filter}/{$photo->getFilename()}";
		if (!file_exists($filePath)) {
			return $this->container->get('liip_imagine.controller')->filterAction($request, $photo->getFilename(), $filter);
		}
		return new BinaryFileResponse($filePath);
	}

	/**
	 * @Template()
	 */
	public function listAction() {
		$this->viewParameters['photos'] = $this->getRepository('Photo')->findAll();
		$this->viewParameters['title'] = 'Photos';
		return $this->viewParameters;
	}

}
