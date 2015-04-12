<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Comment;
use ComTSo\ForumBundle\Entity\Topic;
use ComTSo\ForumBundle\Form\Type\CommentType;
use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

class CommentController extends BaseController
{
    protected function initComment(Comment $comment)
    {
        $topic = $comment->getTopic();
        $this->setActiveMenu('forums');
        $this->viewParameters['comment'] = $comment;
        $this->viewParameters['topic'] = $topic;
        $this->viewParameters['forum'] = $topic->getForum();
        $this->viewParameters['title'] = (string) $comment->getTopic();
        $this->viewParameters['forums'] = $this->getRepository('ComTSoForumBundle:Forum')->findAll();
        $this->viewParameters['topics'] = $this->getRepository('ComTSoForumBundle:Topic')->findByForum($topic->getForum(), ['updatedAt' => 'DESC'], 10);
    }

    /**
     * @Template()
     * @param Comment $comment
     * @return array
     */
    public function showAction(Comment $comment)
    {
        $this->initComment($comment);
        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param Comment $comment
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function editAction(Request $request, Comment $comment)
    {
        $this->initComment($comment);

        $form = $this->createForm(new CommentType(), $comment, ['label' => 'Édition du commentaire']);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $comment->setUpdatedAt(new DateTime());
                $comment->setContent($this->cleanHtml($comment->getContent()));

                $em = $this->getManager();
                $em->persist($comment);
                $em->flush();

                $this->addFlashMsg('success', 'Commentaire mis à jour');
                return $this->redirectToTopic($comment->getTopic());
            }
        }

        $this->viewParameters['form'] = $form->createView();
        
        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param Comment $comment
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction(Request $request, Comment $comment)
    {
        $this->initComment($comment);

        $builder = $this->createFormBuilder($comment, ['label' => 'Suppression du commentaire']);
        $form = $builder->getForm();

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $em = $this->getManager();
                $em->remove($comment);
                $em->flush();

                $this->addFlashMsg('success', 'Commentaire supprimé');
                return $this->redirectToTopic($comment->getTopic());
            }
        }

        $this->viewParameters['form'] = $form->createView();

        return $this->viewParameters;
    }

    protected function redirectToTopic(Topic $topic, $code = 302)
    {
        return $this->redirect($this->generateUrl('comtso_topic_show', $topic->getRoutingParameters()), $code);
    }
}
