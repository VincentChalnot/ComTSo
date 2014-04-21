<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Lib\Utils;
use ComTSo\UserBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;

class BaseController extends Controller {

	protected $viewParameters = [];
	
	/**
	 * Get the current session or create a new one
	 * @return Session $session
	 */
	public function getSession() {
		$session = $this->get('session');
		if (!$session) {
			$session = new Session;
			$session->start();
		}
		return $session;
	}

	/**
	 * Add a new flash message
	 * @param string $name
	 * @param mixed $value
	 */
	public function addFlashMsg($name, $value) {
		$this->getSession()->getFlashBag()->add($name, $value);
	}

	/**
	 * Get current request parameter
	 * @param string $path
	 * @param mixed $default
	 * @param bool $deep
	 * @return mixed
	 */
	public function getRequestParameter($path, $default = null, $deep = false) {
		return $this->getRequest()->get($path, $default, $deep);
	}

	/**
	 * Alias to get a doctrine repository, automatically use the current bundle if not specified
	 * @param string $persistentObjectName
	 * @param string $persistentManagerName
	 * @return EntityRepository
	 */
	public function getRepository($persistentObjectName, $persistentManagerName = null) {
		$infos = explode(':', $persistentObjectName);
		if (count($infos) == 1) {
			$persistentObjectName = "{$this->getBundleName()}:{$persistentObjectName}";
		}
		return $this->getDoctrine()->getManager()->getRepository($persistentObjectName, $persistentManagerName);
	}

	/**
	 * Alias to return the entity manager
	 * @return EntityManagerInterface
	 */
	public function getManager() {
		return $this->getDoctrine()->getManager();
	}

	/**
	 * Return current environment dev/prod/test
	 * @return string 
	 */
	protected function getEnv() {
		return $this->container->getParameter('kernel.environment');
	}

	/**
	 * Set the active main menu tab
	 * @param string $menuId
	 */
	protected function setActiveMenu($menuId = null) {
		$this->viewParameters['activeMenu'] = $menuId;
	}
	
	protected function getBundleName() {
		return $this->getRequest()->attributes->get('_template')->get('bundle');
	}
	
	protected function cleanHtml($html) {
		$html = $this->get('joli_typo.fixer.fr')->fix($html);
		$html = $this->get('exercise_html_purifier.default')->purify($html);
		return $html;
	}

	protected function cleanText($html, $cut = null) {
		$html = $this->cleanHtml($html);
		$txt = Utils::convertToText($html);
		if ($cut) {
			$txt = Utils::shorten($txt, $cut);
		}
		return str_replace("\n", ' ', $txt);
	}
	
	/**
	 * 
	 * @return User
	 */
	public function getUser() {
		$user = parent::getUser();
		$lastMinute = new \DateTime;
		$lastMinute->sub(new \DateInterval('PT1M'));
		if ($user->getLastActivity() < $lastMinute) {
			$user->setLastActivity(new \DateTime);
			$this->getManager()->persist($user);
			$this->getManager()->flush();
		}
		return $user;
	}
}
