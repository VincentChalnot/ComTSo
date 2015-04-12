<?php

namespace ComTSo\ForumBundle\Controller;

use ComTSo\ForumBundle\Entity\Comment;
use ComTSo\ForumBundle\Entity\PhotoTopic;
use ComTSo\ForumBundle\Entity\Topic;
use ComTSo\ForumBundle\Form\Type\TopicType;
use DateTime;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TopicController extends BaseController
{
    protected function initTopic(Topic $topic, $forumId)
    {
        $forum = $topic->getForum();
        if ($forum->getId() != $forumId) {
            return $this->redirectToTopic($topic, 304);
        }
        $topic->getPhotos()->initialize();
        $this->setActiveMenu('forums');
        $this->viewParameters['topic'] = $topic;
        $this->viewParameters['title'] = (string) $topic;
        $this->viewParameters['forum'] = $forum;
        $this->viewParameters['forums'] = $this->getRepository('ComTSoForumBundle:Forum')->findAll();
        $this->viewParameters['topics'] = $this->getRepository('ComTSoForumBundle:Topic')->findByForum($forum, ['updatedAt' => 'DESC'], 10);
    }

    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function showAction(Request $request, Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        $comment = new Comment();
        $comment->setAuthor($this->getUser());
        $comment->setTopic($topic);

        $builder = $this->createFormBuilder($comment);
        $builder->add('content', 'textarea', ['horizontal' => false, 'label_render' => false, 'attr' => ['placeholder' => 'Nouveau commentaire…']]);

        $form = $builder->getForm();
        if ($request->isMethod('POST')) {
            $form->handleRequest($this->getRequest());
            if ($form->isValid()) {
                $topic->setUpdatedAt(new DateTime());
                $comment->setContent($this->cleanHtml($comment->getContent()));
                
                // Saving object
                $em = $this->getManager();
                $em->persist($comment, $topic);
                $em->flush();

                $this->addFlashMsg('success', 'Commentaire enregistré');
                return $this->redirectToTopic($topic);
            }
        }

        $this->viewParameters['form'] = $form->createView();

        $qb = $this->getRepository('ComTSoForumBundle:Comment')->getQBForTopic($topic, $this->getUserMessageOrder());
        $comments = $this->createPager($qb, $request, 20, $this->getUserMessageOrder() === 'DESC' ? 1 : -1);
        $this->viewParameters['comments'] = $comments;

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function editAction(Request $request, Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        $form = $this->createForm(new TopicType(), $topic, ['label' => 'Édition du topic']);

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $topic->setUpdatedAt(new DateTime());
                $topic->setContent($this->cleanHtml($topic->getContent()));
                
                $em = $this->getManager();
                $em->persist($topic);
                $em->flush();

                $this->addFlashMsg('success', 'Topic mis à jour');
                return $this->redirectToTopic($topic);
            }
        }

        $this->viewParameters['form'] = $form->createView();

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Topic $topic
     * @param $forumId
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function managePhotosAction(Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        return $this->viewParameters;
    }


    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function addPhotosAction(Request $request, Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        $ids = [];
        foreach ($topic->getPhotos() as $photo) {
            $ids[] = $photo->getPhoto()->getId();
        }
        $qb = $this->getRepository('ComTSoForumBundle:Photo')->createQueryBuilder('e');
        if ($ids) {
            $qb->andWhere('e.id NOT IN (:ids)')
                    ->setParameter('ids', $ids);
        }
        $qb->andWhere('e.author = :user')
            ->setParameter('user', $this->getUser())
            ->addOrderBy('e.createdAt', 'DESC');

        $photos = $this->createPager($qb, $request);
        $this->viewParameters['photos'] = $photos;

        if ($request->isXmlHttpRequest()) {
            return $this->render('ComTSoForumBundle:Photo:selector.html.twig', $this->viewParameters);
        }

        return $this->viewParameters;
    }

    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @return Response
     */
    public function addPhotoAction(Request $request, Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        if (!$request->query->has('add')) {
            throw new NotFoundHttpException("Missing 'add' parameter");
        }
        $photo = $this->getRepository('ComTSoForumBundle:Photo')->find($request->query->get('add'));
        if (!$photo) {
            throw new NotFoundHttpException("Photo not found : {$request->query->get('add')}");
        }

        $lastPhoto = $this->getRepository('ComTSoForumBundle:PhotoTopic')->findLast($topic);
        $order = $lastPhoto ? $lastPhoto->getOrder() + 1 : 0;

        $em = $this->getManager();
        $photoTopic = new PhotoTopic();
        $photoTopic->setPhoto($photo)
                ->setTopic($topic)
                ->setOrder($order)
                ->setAuthor($this->getUser());
        $em->persist($photoTopic);
        $em->flush();
        $this->viewParameters['photoTopic'] = $photoTopic;

        return $this->render('ComTSoForumBundle:Topic:add_confirmed.html.twig', $this->viewParameters);
    }

    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function removePhotoAction(Request $request, Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        if (!$request->query->has('remove')) {
            throw new NotFoundHttpException("Missing 'remove' parameter");
        }
        $photoTopic = $this->getRepository('ComTSoForumBundle:PhotoTopic')->find($request->query->get('remove'));
        if (!$photoTopic) {
            throw new NotFoundHttpException("PhotoTopic not found : {$request->query->get('remove')}");
        }

        $em = $this->getManager();
        $em->remove($photoTopic);
        $em->flush();

        return new Response();
    }

    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function orderPhotosAction(Request $request, Topic $topic, $forumId)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        if (!$request->request->has('order')) {
            throw new NotFoundHttpException("Missing 'order' in request body");
        }
        $order = $request->request->get('order');
        $em = $this->getManager();
        foreach ($topic->getPhotos() as $photoTopic) {
            if (!isset($order[$photoTopic->getPhoto()->getId()])) {
                continue; // Throw error ?
            }
            $photoTopic->setOrder($order[$photoTopic->getPhoto()->getId()]);
            $em->persist($photoTopic);
        }
        $em->flush();

        return $this->render('ComTSoForumBundle:Topic:order_confirmed.html.twig', $this->viewParameters);
    }

    /**
     * @Template()
     * @param Request $request
     * @param Topic $topic
     * @param $forumId
     * @param bool $star
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function starAction(Request $request, Topic $topic, $forumId, $star = true)
    {
        $response = $this->initTopic($topic, $forumId);
        if ($response instanceof Response) {
            return $response;
        }

        $user = $this->getUser();
        if ($star) {
            $user->addStarredTopic($topic);
        } else {
            $user->removeStarredTopic($topic);
        }
        $em = $this->getManager();
        $em->persist($user);
        $em->flush();
        
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(true);
        }

        $this->addFlashMsg('success', 'Topic ajouté aux favoris');
        return $this->redirectToTopic($topic);
    }
    
    protected function redirectToTopic(Topic $topic, $code = 302)
    {
        return $this->redirect($this->generateUrl('comtso_topic_show', $topic->getRoutingParameters()), $code);
    }
}
