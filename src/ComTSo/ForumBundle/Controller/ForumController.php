<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Forum;
use ComTSo\ForumBundle\Entity\Topic;
use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

class ForumController extends BaseController
{
    /**
     * @Template()
     */
    public function showAction(Request $request, Forum $forum)
    {
        $this->setActiveMenu('forums');
        $this->viewParameters['forum'] = $forum;
        $this->viewParameters['title'] = (string) $forum;
        $this->viewParameters['forums'] = $this->getRepository('Forum')->findAll();

        $qb = $this->getRepository('Topic')->getQBForForumList($forum);
        $topics = $this->createPager($qb, $request, 'updatedAt', 'd')->initialize();
        $this->viewParameters['topics'] = $topics;

        return $this->viewParameters;
    }

    /**
     * @Template()
     */
    public function editAction(Request $request, Forum $forum)
    {
        $this->showAction($request, $forum);

        $builder = $this->createFormBuilder($forum, ['show_legend' => false]);
        $builder->add('title', 'text', ['horizontal' => false, 'label_render' => false, 'attr' => ['placeholder' => 'Titre']]);
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
     */
    public function addTopicAction(Request $request, Forum $forum)
    {
        $this->showAction($request, $forum);

        $topic = new Topic();
        $topic->setAuthor($this->getUser());
        $topic->setForum($forum);

        $form = $this->createForm(new \ComTSo\ForumBundle\Form\Type\TopicType(), $topic, ['label' => 'Nouveau topic']);

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
