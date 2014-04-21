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

	public function getFunctions() {
		return array(
			'random_quote' => new Twig_Function_Method($this, 'getRandomQuote'),
		);
	}

	public function getRandomQuote() {
		return $this->getDoctrine()->getRepository('ComTSoForumBundle:Quote')->findRandom();
	}

	/**
	 * 
	 * @return Registry
	 */
	public function getDoctrine() {
		return $this->container->get('doctrine');
	}
}