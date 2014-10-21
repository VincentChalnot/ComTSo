<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Forum;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class ForumController extends BaseController
{
    /**
     * @Template()
     */
    public function showAction(Forum $forum)
    {
        $this->setActiveMenu('forums');
        $this->viewParameters['forum'] = $forum;
        $this->viewParameters['title'] = (string) $forum;
        $this->viewParameters['forums'] = $this->getRepository('Forum')->findAll();
        $this->viewParameters['topics'] = $this->getRepository('Topic')->findForForumList($forum);

        return $this->viewParameters;
    }

    /**
     * @Template()
     */
    public function editAction(Forum $forum)
    {
        $this->setActiveMenu('forums');
        $this->viewParameters['forum'] = $forum;
        $this->viewParameters['title'] = (string) $forum;
        $this->viewParameters['forums'] = $this->getRepository('Forum')->findAll();
        $this->viewParameters['topics'] = $this->getRepository('Topic')->findForForumList($forum);

        return $this->viewParameters;
    }

}
