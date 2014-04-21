<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Photo;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class PhotoController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(Photo $photo) {
		$this->viewParameters['photo'] = $photo;
		return $this->viewParameters;
	}
	
	public function sourceAction(Photo $photo) {
		return new \Symfony\Component\HttpFoundation\BinaryFileResponse("{$this->container->getParameter('kernel.root_dir')}/data/photos/{$photo->getFilename()}");
	}
	
}
