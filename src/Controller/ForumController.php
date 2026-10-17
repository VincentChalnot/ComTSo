<?php

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Topic;
use App\Entity\User;
use App\Form\TopicType;
use App\Forum\ThreadPreviewBuilder;
use App\Pagination\Pager;
use App\Photo\PostPhotos;
use App\Repository\ForumRepository;
use App\Repository\TopicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ForumController extends AbstractController
{
    public const TOPICS_PER_PAGE = 12;

    #[Route('/forums/', name: 'forum_index')]
    public function index(ForumRepository $forums, TopicRepository $topics): Response
    {
        $stats = [];
        foreach ($forums->findAllOrdered() as $forum) {
            $stats[$forum->getId()] = $topics->countInForum($forum);
        }

        return $this->render('forum/index.html.twig', ['stats' => $stats]);
    }

    #[Route('/forums/{forumId}', name: 'forum_show', requirements: ['forumId' => '[a-z0-9-]+'])]
    public function show(
        Request $request,
        #[MapEntity(id: 'forumId')] Forum $forum,
        #[CurrentUser] User $user,
        TopicRepository $topics,
        ThreadPreviewBuilder $previews,
    ): Response {
        $pager = new Pager($topics->countInForum($forum), self::TOPICS_PER_PAGE, $request->query->getInt('page', 1));

        return $this->render('forum/show.html.twig', [
            'forum' => $forum,
            'previews' => $previews->build($topics->findPageInForum($forum, $pager->offset(), $pager->perPage), $user),
            'pager' => $pager,
        ]);
    }

    #[Route('/forums/{forumId}/nouveau', name: 'forum_new_topic', requirements: ['forumId' => '[a-z0-9-]+'])]
    public function newTopic(
        Request $request,
        #[MapEntity(id: 'forumId')] Forum $forum,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        PostPhotos $postPhotos,
    ): Response {
        $topic = (new Topic())->setForum($forum)->setAuthor($user);
        $form = $this->createForm(TopicType::class, $topic, ['with_photos' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $photos = $postPhotos->store($form->get('photos')->getData() ?? [], $user);
            } catch (\InvalidArgumentException $e) {
                $form->get('photos')->addError(new FormError($e->getMessage()));

                return $this->render('forum/new_topic.html.twig', ['forum' => $forum, 'form' => $form]);
            }

            $em->persist($topic);
            $postPhotos->attachToTopic($topic, $photos, $user);
            $em->flush();
            $this->addFlash('success', 'Le sujet a été créé.');

            return $this->redirectToRoute('topic_show', ['forumId' => $forum->getId(), 'id' => $topic->getId()]);
        }

        return $this->render('forum/new_topic.html.twig', ['forum' => $forum, 'form' => $form]);
    }
}
