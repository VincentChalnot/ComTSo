<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Photo;
use ComTSo\ForumBundle\Lib\Utils;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class PhotoController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(Photo $photo) {
		$this->viewParameters['photo'] = $photo;
		$this->viewParameters['title'] = (string) $photo;
		return $this->viewParameters;
	}

	public function sourceAction(Request $request, Photo $photo) {
		$filePath = "{$this->getConfigParameter('comtso.photo_dir')}/originals/{$photo->getFilename()}";
		return $this->createImageResponse($request, $filePath, $photo, $this->getRequest()->get('download') ? 'attachment' : 'inline');
	}

	public function sourceCacheAction(Request $request, Photo $photo, $filter) {
		$filePath = "{$this->getConfigParameter('comtso.photo_dir')}/cache/{$filter}/{$photo->getFilename()}";
		if (!file_exists($filePath)) {
			return $this->container->get('liip_imagine.controller')->filterAction($request, $photo->getFilename(), $filter);
		}
		return $this->createImageResponse($request, $filePath, $photo);
	}

	/**
	 * @Template()
	 */
	public function listAction() {
		$this->viewParameters['photos'] = $this->getRepository('Photo')->findAll();
		$this->viewParameters['title'] = 'Photos';
		return $this->viewParameters;
	}

	protected function createImageResponse(Request $request, $filePath, Photo $photo = null, $contentDisposition = 'inline') {
		$response = new BinaryFileResponse($filePath, 200, [], true, $contentDisposition, false, true);
		$date = new \DateTime();
		$date->add(new \DateInterval('P1Y'));
		$date->setTime(0, 0, 0);
		
		if ($response->isNotModified($request)) {
			$response = new Response(null, Response::HTTP_NOT_MODIFIED);
			$response->setPublic();
			$response->setExpires($date);
			return $response;
		}
		
		$response->setExpires($date);
		
		if ($photo) {
			$filename = $photo->getTitle() ? Utils::slugify($photo->getTitle()).'.'.$photo->getFileType() : $photo->getFilename();
			$response->setContentDisposition($this->getRequest()->get('download') ? 'attachment' : 'inline', $filename);
		}
		
		return $response;
	}
}
