<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Lib\Utils;
use ComTSo\UserBundle\Entity\User;
use DateInterval;
use DateTime;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;

class BaseController extends Controller
{
    protected $viewParameters = [];

    /**
     * Get the current session or create a new one
     * @return Session $session
     */
    protected function getSession()
    {
        $session = $this->get('session');
        if (!$session) {
            $session = new Session();
            $session->start();
        }

        return $session;
    }

    /**
     * Add a new flash message
     * @param string $name
     * @param mixed  $value
     */
    protected function addFlashMsg($name, $value)
    {
        $this->getSession()->getFlashBag()->add($name, $value);
    }

    /**
     * Alias to get a doctrine repository, automatically use the current bundle if not specified
     * @param  string           $persistentObjectName
     * @param  string           $persistentManagerName
     * @return EntityRepository
     */
    protected function getRepository($persistentObjectName, $persistentManagerName = null)
    {
        return $this->getManager()->getRepository($persistentObjectName, $persistentManagerName);
    }

    /**
     * Alias to return the entity manager
     * @return EntityManager
     */
    protected function getManager()
    {
        return $this->getDoctrine()->getManager();
    }

    /**
     * Return current environment dev/prod/test
     * @return string
     */
    protected function getEnv()
    {
        return $this->container->getParameter('kernel.environment');
    }

    /**
     * Set the active main menu tab
     * @param string $menuId
     */
    protected function setActiveMenu($menuId = null)
    {
        $this->viewParameters['activeMenu'] = $menuId;
    }

    /**
     *
     * @return User
     */
    public function getUser()
    {
        $user = parent::getUser();
        $lastMinute = new DateTime();
        $lastMinute->sub(new DateInterval('PT1M'));
        if ($user->getLastActivity() < $lastMinute) {
            $user->setLastActivity(new DateTime());
            $this->getManager()->persist($user);
            $this->getManager()->flush();
        }

        return $user;
    }

    protected function getConfigParameter($name, $default = null)
    {
        if ($this->container->hasParameter($name)) {
            return $this->container->getParameter($name);
        }

        return $default;
    }

    /**
     * @param  QueryBuilder $qb
     * @param Request $request
     * @param int $defaultLimit
     * @param int $defaultPage
     * @return Pagerfanta
     */
    protected function createPager(QueryBuilder $qb, Request $request, $defaultLimit = 10, $defaultPage = 1)
    {
        $adapter = new DoctrineORMAdapter($qb);
        $pager = new Pagerfanta($adapter);

        $pager->setMaxPerPage($defaultLimit);

        if ($defaultPage < 0) {
            $defaultPage = $pager->getNbPages() + $defaultPage + 1;
        }
        if ($request->query->has('page')) {
            $pager->setCurrentPage($request->query->get('page'));
        } else {
            $pager->setCurrentPage($defaultPage);
        }

        return $pager;
    }

    protected function cleanHtml($html)
    {
        $html = $this->get('joli_typo.fixer.fr')->fix($html);
        $html = $this->get('exercise_html_purifier.default')->purify($html);
        return $html;
    }

    protected function cleanText($html, $cut = null)
    {
        $html = $this->cleanHtml($html);
        $txt = Utils::convertToText($html);
        if ($cut) {
            $txt = Utils::shorten($txt, $cut);
        }

        return str_replace("\n", ' ', $txt);
    }

    protected function getUserMessageOrder()
    {
        return $this->get('comtso.config.handler')->getUserMessageOrder();
    }
}
