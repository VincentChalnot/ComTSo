<?php

namespace ComTSo\ForumBundle\Twig;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig_Extension;
use Twig_Function_Method;

class ForumExtension extends Twig_Extension {

	/**
	 * @var ContainerInterface
	 */
	protected $container;

	public function __construct(ContainerInterface $container) {
		$this->container = $container;
	}

	public function getName() {
		return 'comtso_forum';
	}
	
	public function getFilters() {
		return [
			'file_size' => new \Twig_Filter_Method($this, 'fileSizeFormat'),
		];
	}

	public function getFunctions() {
		return [
			'random_quote' => new Twig_Function_Method($this, 'getRandomQuote'),
			'image_size_attrs' => new Twig_Function_Method($this, 'getImageSizeAttrs', ['is_safe' => ['html']]),
		];
	}

	public function getRandomQuote() {
		return $this->getDoctrine()->getRepository('ComTSoForumBundle:Quote')->findRandom();
	}
	
	public function getImageSizeAttrs(\ComTSo\ForumBundle\Entity\Photo $photo, $filter) {
		$config = $this->container->get('liip_imagine.filter.configuration')->get($filter);
		$width = $photo->getWidth();
		$height = $photo->getHeight();
		if (isset($config['filters']['thumbnail'])) {
			$width = $config['filters']['thumbnail']['size'][0];
			$height = $config['filters']['thumbnail']['size'][1];
			if ($config['filters']['thumbnail']['mode'] == 'inset') {
				if ($photo->getWidth() > $photo->getHeight()) {
					$height = floor($width / $photo->getWidth() * $photo->getHeight());
				}
				if ($photo->getWidth() < $photo->getHeight()) {
					$width = floor($height / $photo->getHeight() * $photo->getWidth());
				}
			}
		}
		return "width=\"{$width}\" height=\"{$height}\"";
	}

	/**
	 * 
	 * @return Registry
	 */
	public function getDoctrine() {
		return $this->container->get('doctrine');
	}
	
	public function fileSizeFormat($size, $decimals = 1) {
		return \ComTSo\ForumBundle\Lib\Utils::filesizeFormat($size, $decimals);
	}
}