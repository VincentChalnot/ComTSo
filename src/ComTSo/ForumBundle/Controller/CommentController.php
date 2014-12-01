<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Comment;
use ComTSo\ForumBundle\Entity\Topic;
use ComTSo\ForumBundle\Form\Type\TopicType;
use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CommentController extends BaseController
{

    /**
     * @Template()
     */
    public function showAction(Comment $comment)
    {
        $topic = $comment->getTopic();
        $this->setActiveMenu('forums');
        $this->viewParameters['comment'] = $comment;
        $this->viewParameters['topic'] = $topic;
        $this->viewParameters['forum'] = $topic->getForum();
        $this->viewParameters['title'] = (string) $comment->getTopic();
        $this->viewParameters['forums'] = $this->getRepository('Forum')->findAll();
        $this->viewParameters['topics'] = $this->getRepository('Topic')->findByForum($topic->getForum(), ['updatedAt' => 'DESC'], 10);
        return $this->viewParameters;
    }

}
