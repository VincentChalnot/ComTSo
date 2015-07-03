<?php

namespace ComTSo\ForumBundle\Controller;

use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class HomeController
 * @package ComTSo\ForumBundle\Controller
 */
class HomeController extends BaseController
{

    /**
     * @Template()
     * @param Request $request
     * @return array
     */
    public function indexAction(Request $request)
    {
        $yesterday = new DateTime();
        $yesterday->setTime(0, 0);
        $since = $this->getUser()->getPreviousLogin();
        if ($since > $yesterday) {
            $since = $yesterday;
        }

        $form = $this->buildForm($since);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $since = $form->get('since')->getData();
        }
        
        $this->viewParameters['form'] = $form->createView();
        $this->viewParameters['since'] = $since;
        $this->viewParameters['topics'] = $this->loadTopics($since);
        $this->viewParameters['forums'] = $this->getRepository('ComTSoForumBundle:Forum')->findAll();

        return $this->viewParameters;
    }

    protected function loadTopics(DateTime $since)
    {
        $topics = [];
        foreach ($this->getRepository('ComTSoForumBundle:Topic')->findLastsModified(100, $since) as $topic) {
            $topic->lastComments = [];
            $topic->lastPhotos = [];
            $topics[$topic->getId()] = $topic;
        }

        $comments = $this->getRepository('ComTSoForumBundle:Comment')->findLastsCreated(100, $since, $this->getUserMessageOrder());
        foreach ($comments as $comment) {
            $topic = $comment->getTopic();
            $topic->lastComments[] = $comment;
            $topics[$topic->getId()] = $topic;
        }
        $photos = $this->getRepository('ComTSoForumBundle:PhotoTopic')->findLastsCreated(100, $since, $this->getUserMessageOrder());
        foreach ($photos as $photo) {
            $topic = $photo->getTopic();
            $topic->lastPhotos[] = $photo;
            $topics[$topic->getId()] = $topic;
        }
        return $topics;
    }

    protected function buildForm(DateTime $since)
    {
        $formBuilder = $this->createFormBuilder(['since' => $since], ['show_legend' => false, 'method' => 'get', 'csrf_protection' => false]);
        $formBuilder->add('since', 'date', [
            'label' => 'Affichage des nouveautés depuis le',
            'widget' => 'single_text',
            'horizontal_label_class' => 'col-xs-5',
            'horizontal_input_wrapper_class' => 'col-xs-3',
        ]);
        return $formBuilder->getForm();
    }
}
