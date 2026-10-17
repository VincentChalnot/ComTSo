<?php

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Topic;
use App\Entity\User;
use App\Form\TopicType;
use App\Pagination\Pager;
use App\Repository\CommentRepository;
use App\Repository\ForumRepository;
use App\Repository\PhotoTopicRepository;
use App\Repository\TopicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        TopicRepository $topics,
        CommentRepository $comments,
        PhotoTopicRepository $photoTopics,
    ): Response {
        $pager = new Pager($topics->countInForum($forum), self::TOPICS_PER_PAGE, $request->query->getInt('page', 1));
        $cards = [];
        foreach ($topics->findPageInForum($forum, $pager->offset(), $pager->perPage) as $topic) {
            $cards[] = [
                'topic' => $topic,
                'latest' => $comments->findLatest($topic, 2),
                'photos' => $photoTopics->findForTopic($topic),
            ];
        }

        return $this->render('forum/show.html.twig', ['forum' => $forum, 'cards' => $cards, 'pager' => $pager]);
    }

    #[Route('/forums/{forumId}/nouveau', name: 'forum_new_topic', requirements: ['forumId' => '[a-z0-9-]+'])]
    public function newTopic(
        Request $request,
        #[MapEntity(id: 'forumId')] Forum $forum,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
    ): Response {
        $topic = (new Topic())->setForum($forum)->setAuthor($user);
        $form = $this->createForm(TopicType::class, $topic);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($topic);
            $em->flush();
            $this->addFlash('success', 'Le sujet a été créé.');

            return $this->redirectToRoute('topic_show', ['forumId' => $forum->getId(), 'id' => $topic->getId()]);
        }

        return $this->render('forum/new_topic.html.twig', ['forum' => $forum, 'form' => $form]);
    }
}
