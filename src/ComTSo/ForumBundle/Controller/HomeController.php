<?php

namespace ComTSo\ForumBundle\Controller;

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
     */
    public function indexAction(Request $request)
    {
        $yesterday = new \DateTime();
        $yesterday->setTime(0, 0);
        $since = $this->getUser()->getPreviousLogin();
        if ($since > $yesterday) {
            $since = $yesterday;
        }
        $formBuilder = $this->createFormBuilder(['since' => $since], ['show_legend' => false, 'method' => 'get', 'csrf_protection' => false]);
        $formBuilder->add('since', 'date', [
            'label' => 'Affichage des nouveautés depuis le',
            'widget' => 'single_text',
            'horizontal_label_class' => 'col-xs-5',
            'horizontal_input_wrapper_class' => 'col-xs-3',
        ]);
        $form = $formBuilder->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            $since = $form->get('since')->getData();
        }
        $this->viewParameters['form'] = $form->createView();
        $this->viewParameters['since'] = $since;

        $topics = [];
        foreach ($this->getRepository('Topic')->findLastsModified(100, $since) as $topic) {
            $topic->lastComments = [];
            $topics[$topic->getId()] = $topic;
        }

        $comments = $this->getRepository('Comment')->findLastsCreated(100, $since, $this->getUserMessageOrder());
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
