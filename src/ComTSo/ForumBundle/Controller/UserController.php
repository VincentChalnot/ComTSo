<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Message;
use ComTSo\ForumBundle\Form\Type\ConfigType;
use ComTSo\UserBundle\Entity\User;
use ComTSo\UserBundle\Form\Type\UserType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;

class UserController extends BaseController
{
    
    protected function preExecute($title = null)
    {
        $this->setActiveMenu('people');
        $this->viewParameters['users'] = $this->getRepository('ComTSoUserBundle:User')->findByEnabled(true);
        $this->viewParameters['title'] = (string) $title;
    }

    /**
     * @Template()
     * @param Request $request
     * @param User $user
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function showAction(Request $request, User $user)
    {
        $this->preExecute($user);
        $this->viewParameters['user'] = $user;
        $this->viewParameters['messages'] = $this->getRepository('ComTSoForumBundle:Message')->findConversation($this->getUser(), $user);

        $message = new Message();
        $message->setAuthor($this->getUser());
        $message->setRecipient($user);

        $builder = $this->createFormBuilder($message);
        $builder->add('content', 'textarea', ['horizontal' => false, 'label_render' => false]);

        $form = $builder->getForm();
        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
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
     * @param Request $request
     * @param User $user
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function editAction(Request $request, User $user)
    {
        $this->preExecute($user);
        $this->viewParameters['user'] = $user;

        $form = $this->createForm(new UserType(), $user);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
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
    public function listAction()
    {
        $this->preExecute('Membres');

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param User $user
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function configAction(Request $request, User $user)
    {
        $this->preExecute('Configuration');

        $form = $this->createForm(new ConfigType(), $user->getConfig());

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $user->setConfig($form->getData());
                // Saving object
                $em = $this->getManager();
                $em->persist($user);
                $em->flush();

                $this->addFlashMsg('success', 'Configuration sauvegardé');

                return $this->redirect($this->generateUrl('comtso_user_config', $user->getRoutingParameters()));
            }
        }

        $this->viewParameters['form'] = $form->createView();

        return $this->viewParameters;
    }

}
