<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Controller\BaseController;
use ComTSo\ForumBundle\Entity\Comment;
use ComTSo\ForumBundle\Entity\Topic;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class TopicController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(Topic $topic, $forumId) {
		$forum = $topic->getForum();
		if ($forum->getId() != $forumId) {
			return $this->redirect($this->generateUrl('comtso_topic_show', ['id' => $topic->getId(), 'forumId' => $forum->getId()]), 304);
		}
		$topic->getPhotos()->initialize();
		$this->setActiveMenu('forums');
		$this->viewParameters['topic'] = $topic;
		$this->viewParameters['title'] = (string) $topic;
		$this->viewParameters['forum'] = $forum;
		$this->viewParameters['forums'] = $this->getRepository('Forum')->findAll();
		$this->viewParameters['topics'] = $this->getRepository('Topic')->findByForum($forum, ['updatedAt' => 'DESC']);

		$comment = new Comment;
		$comment->setAuthor($this->getUser());
		$comment->setTopic($topic);

		$builder = $this->createFormBuilder($comment);
		$builder->add('content', 'textarea', ['horizontal' => false, 'label_render' => false]);

		$form = $builder->getForm();
		if ($this->getRequest()->isMethod('POST')) {
			$form->handleRequest($this->getRequest());
			if ($form->isValid()) {
				$topic->setUpdatedAt(new \DateTime());
				// Saving object
				$em = $this->getDoctrine()->getManager();
				$em->persist($comment, $topic);
				$em->flush();

				$this->addFlashMsg('success', 'Commentaire enregistré');
				return $this->redirect($this->generateUrl('comtso_topic_show', ['id' => $topic->getId(), 'forumId' => $forum->getId()]));
			}
		}

		$this->viewParameters['form'] = $form->createView();
		return $this->viewParameters;
	}

}
