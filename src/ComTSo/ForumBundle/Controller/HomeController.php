<?php

namespace ComTSo\ForumBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * Class HomeController
 * @package ComTSo\ForumBundle\Controller
 */
class HomeController extends BaseController
{

    /**
     * @Template()
     */
    public function indexAction()
    {
        $yesterday = new \DateTime('yesterday');
        $yesterday->setTime(0, 0);
        $since = $this->getUser()->getPreviousLogin();
        if ($since > $yesterday) {
            $since = $yesterday;
        }
        $this->viewParameters['since'] = $since;
        $comments = $this->getRepository('Comment')->findLastsCreated(100, $since);
        $topics = [];
        foreach ($comments as $comment) {
            $topic = $comment->getTopic();
            $topic->lastComments[] = $comment;
            $topics[$topic->getId()] = $topic;
        }

        $this->viewParameters['topics'] = $topics;
        $this->viewParameters['forums'] = $this->getRepository('Forum')->findAll();

        return $this->viewParameters;
    }

}
