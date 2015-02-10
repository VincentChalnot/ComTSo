<?php

namespace ComTSo\ForumBundle\Service;

use ComTSo\UserBundle\Entity\User;
use Symfony\Component\DependencyInjection\ContainerAware;

class ConfigHandler extends ContainerAware {

    public function getUserMessageOrder()
    {
        $user = $this->getUser();
        $order = null;
        if ($user instanceof User) {
            $order = $user->getConfigValue('message_order');
        }
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = $this->container->getParameter('default_message_order');
        }
        if (!in_array($order, ['ASC', 'DESC'])) {
            $order = 'DESC';
        }
        return $order;
    }

    public function getUserTheme()
    {
        $user = $this->getUser();
        if ($user instanceof User && $theme = $user->getConfigValue('bootstrap_theme')) {
            return $theme;
        }
        return $this->container->getParameter('default_bootstrap_theme');
    }

    /**
     * @return null|User
     * @see TokenInterface::getUser()
     */
    protected function getUser()
    {
        if (null === $token = $this->container->get('security.token_storage')->getToken()) {
            return;
        }

        if (!is_object($user = $token->getUser())) {
            return;
        }

        return $user;
    }
}