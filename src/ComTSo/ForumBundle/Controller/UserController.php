<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Message;
use ComTSo\UserBundle\Entity\User;
use ComTSo\UserBundle\Form\Type\UserType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class UserController extends BaseController {

	/**
	 * @Template()
	 */
	public function showAction(User $user) {
		$this->setActiveMenu('people');
		$this->viewParameters['user'] = $user;
		$this->viewParameters['title'] = (string) $user;
		$this->viewParameters['users'] = $this->getRepository('ComTSoUserBundle:User')->findAll();
		$this->viewParameters['messages'] = $this->getRepository('Message')->findConversation($this->getUser(), $user);
        
        $message = new Message;
		$message->setAuthor($this->getUser());
		$message->setRecipient($user);
		
		$builder = $this->createFormBuilder($message);
		$builder->add('content', 'textarea', ['horizontal' => false, 'label_render' => false]);
		
		$form = $builder->getForm();
		if ($this->getRequest()->isMethod('POST')) {
			$form->handleRequest($this->getRequest());
			if ($form->isValid()) {
				// Saving object
				$em = $this->getManager();
				$em->persist($message);
				$em->flush();

				$this->addFlashMsg('success', 'Message envoyé');
				return $this->redirect($this->generateUrl('comtso_user_show', [
					'usernameCanonical' => $user->getUsernameCanonical(),
				]));
			}
		}

		$this->viewParameters['form'] = $form->createView();
		return $this->viewParameters;
	}
	
	/**
	 * @Template()
	 */
	public function editAction(User $user) {
		$this->setActiveMenu('people');
		$this->viewParameters['user'] = $user;
		$this->viewParameters['title'] = (string) $user;
		
		$form = $this->createForm(new UserType(), $user);
		
		if ($this->getRequest()->isMethod('POST')) {
			$form->handleRequest($this->getRequest());
			if ($form->isValid()) {
				// Saving object
				$em = $this->getManager();
				$em->persist($user);
				$em->flush();

				$this->addFlashMsg('success', 'Profil sauvegardé');
				return $this->redirect($this->generateUrl('comtso_user_show', [
					'usernameCanonical' => $user->getUsernameCanonical(),
				]));
			}
		}

		$this->viewParameters['form'] = $form->createView();
		return $this->viewParameters;
	}
	
	/**
	 * @Template()
	 */
	public function listAction() {
		$this->setActiveMenu('people');
		$this->viewParameters['users'] = $this->getRepository('ComTSoUserBundle:User')->findAll();
		$this->viewParameters['title'] = 'Membres';
		return $this->viewParameters;
	}

}
