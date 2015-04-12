<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Forum;
use ComTSo\ForumBundle\Entity\Topic;
use ComTSo\ForumBundle\Form\Type\TopicType;
use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

class ForumController extends BaseController
{
    /**
     * @Template()
     * @return array
     */
    public function listAction()
    {
        $this->setActiveMenu('forums');
        $this->viewParameters['title'] = 'Les Forums';
        $this->viewParameters['forums'] = $this->getRepository('ComTSoForumBundle:Forum')->findAll();

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param Forum $forum
     * @return array
     */
    public function showAction(Request $request, Forum $forum)
    {
        $this->setActiveMenu('forums');
        $this->viewParameters['forum'] = $forum;
        $this->viewParameters['title'] = (string) $forum;
        $this->viewParameters['forums'] = $this->getRepository('ComTSoForumBundle:Forum')->findAll();

        $qb = $this->getRepository('ComTSoForumBundle:Topic')->getQBForForumList($forum);
        $topics = $this->createPager($qb, $request);
        $this->viewParameters['topics'] = $topics;

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createAction(Request $request)
    {
        return $this->editAction($request, new Forum);
    }

    /**
     * @Template()
     * @param Request $request
     * @param Forum $forum
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function editAction(Request $request, Forum $forum)
    {
        $this->showAction($request, $forum);

        $builder = $this->createFormBuilder($forum, ['show_legend' => false]);
        $builder->add('title', 'text', ['horizontal' => false, 'label_render' => false, 'attr' => ['placeholder' => 'Titre']]);
        $builder->add('order', 'number', ['horizontal' => false, 'label_render' => false, 'attr' => ['placeholder' => "Numéro d'ordre pour l'affichage"]]);
        $builder->add('content', 'textarea', ['horizontal' => false, 'label_render' => false]);

        $form = $builder->getForm();
        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $forum->setUpdatedAt(new DateTime());
                // Saving object
                $em = $this->getManager();
                $em->persist($forum);
                $em->flush();

                $this->addFlashMsg('success', 'Modifications enregistrées');

                return $this->redirect($this->generateUrl('comtso_forum_show', ['id' => $forum->getId()]));
            }
        }

        $this->viewParameters['form'] = $form->createView();

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param Forum $forum
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function addTopicAction(Request $request, Forum $forum)
    {
        $this->showAction($request, $forum);

        $topic = new Topic();
        $topic->setAuthor($this->getUser());
        $topic->setForum($forum);

        $form = $this->createForm(new TopicType(), $topic, ['label' => 'Nouveau topic']);

        if ($this->getRequest()->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $forum->setUpdatedAt(new DateTime());
                // Saving object
                $em = $this->getManager();
                $em->persist($topic, $forum);
                $em->flush();

                $this->addFlashMsg('success', 'Nouveau topic enregistré');

                return $this->redirect($this->generateUrl('comtso_topic_show', ['id' => $topic->getId(), 'forumId' => $forum->getId()]));
            }
        }

        $this->viewParameters['form'] = $form->createView();

        return $this->viewParameters;
    }

}
