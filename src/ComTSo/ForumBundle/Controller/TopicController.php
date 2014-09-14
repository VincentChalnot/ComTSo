<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Controller\BaseController;
use ComTSo\ForumBundle\Entity\Comment;
use ComTSo\ForumBundle\Entity\Topic;
use ComTSo\ForumBundle\Form\Type\TopicType;
use Doctrine\Common\Collections\ArrayCollection;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TopicController extends BaseController {

	protected function initTopic(Topic $topic, $forumId) {
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
	}
	
	/**
	 * @Template()
	 */
	public function showAction(Topic $topic, $forumId) {
		$response = $this->initTopic($topic, $forumId);
		if ($response instanceof Response) {
			return $response;
		}

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
				$em = $this->getManager();
				$em->persist($comment, $topic);
				$em->flush();

				$this->addFlashMsg('success', 'Commentaire enregistré');
				return $this->redirect($this->generateUrl('comtso_topic_show', ['id' => $topic->getId(), 'forumId' => $forum->getId()]));
			}
		}

		$this->viewParameters['form'] = $form->createView();
		return $this->viewParameters;
	}

	/**
	 * @Template()
	 */
	public function editAction(Request $request, Topic $topic, $forumId) {
		$response = $this->initTopic($topic, $forumId);
		if ($response instanceof Response) {
			return $response;
		}
		
		$form = $this->createForm(new TopicType(), $topic);
		
		$originalPhotos = new ArrayCollection();
		foreach ($topic->getPhotos() as $photo) {
			$originalPhotos->add($photo);
		}
		
		if ($this->getRequest()->isMethod('POST')) {
			$form->handleRequest($request);
			if ($form->isValid()) {
				$em = $this->getManager();
				
				$topic->setUpdatedAt(new \DateTime());
				
				foreach ($originalPhotos as $photo) {
					if (false === $topic->getPhotos()->contains($photo)) {
						$topic->getPhotos()->removeElement($photo);
						$em->remove($photo);
					}
				}
				
				foreach ($topic->getPhotos() as $photo) {
					if ($photo->getPhoto()) {
						$photo->setAuthor($this->getUser());
						$photo->setTopic($topic);
						$em->persist($photo);
					} else {
						$topic->getPhotos()->removeElement($photo);
						$em->remove($photo);
					}
				}

				$em->persist($topic);
				$em->flush();

				$this->addFlashMsg('success', 'Topic mis à jour');
				return $this->redirect($this->generateUrl('comtso_topic_show', ['id' => $topic->getId(), 'forumId' => $forum->getId()]));
			}
		}

		$this->viewParameters['form'] = $form->createView();
		return $this->viewParameters;
	}
	
	/**
	 * @Template()
	 */
	public function addPhotosAction(Request $request, Topic $topic, $forumId) {
		$response = $this->initTopic($topic, $forumId);
		if ($response instanceof Response) {
			return $response;
		}
		
		$ids = [];
		foreach ($topic->getPhotos() as $photo) {
			$ids[] = $photo->getPhoto()->getId();
		}
		$qb = $this->getRepository('Photo')->createQueryBuilder('e');
		$qb->where('e.id NOT IN (:ids)')
				->setParameter('ids', $ids);
		
		$request->query->set('forumId', $forumId);
		$request->query->set('id', $topic->getId());
		
		$photos = $this->createPager($qb, $request, 'createdAt', 'd')->initialize();
		$this->viewParameters['photos'] = $photos;
		
		if ($request->isXmlHttpRequest()) {
			return $this->render('ComTSoForumBundle:Photo:selector.html.twig', $this->viewParameters);
		}
		return $this->viewParameters;
	}
}
