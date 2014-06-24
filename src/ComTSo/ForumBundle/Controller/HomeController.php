<?php

namespace ComTSo\ForumBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class HomeController extends BaseController
{

    /**
     * @Template()
     */
    public function indexAction()
    {
		$since = new \DateTime();
		$since->sub(new \DateInterval('P6D'));
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
