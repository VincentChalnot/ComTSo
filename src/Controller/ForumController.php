<?php

namespace App\Controller;

use App\Entity\Forum;
use App\Entity\Topic;
use App\Form\TopicType;
use App\Repository\ForumRepository;
use App\Repository\TopicRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/forum')]
class ForumController extends AbstractController
{
    #[Route('/', name: 'forum_index')]
    public function index(ForumRepository $forumRepository): Response
    {
        $forums = $forumRepository->findBy([], ['order' => 'ASC', 'title' => 'ASC']);

        return $this->render('forum/index.html.twig', [
            'forums' => $forums,
        ]);
    }

    #[Route('/{id}', name: 'forum_show')]
    public function show(Forum $forum, TopicRepository $topicRepository): Response
    {
        $topics = $topicRepository->findBy(['forum' => $forum], ['createdAt' => 'DESC']);

        return $this->render('forum/show.html.twig', [
            'forum' => $forum,
            'topics' => $topics,
        ]);
    }

    #[Route('/{id}/new-topic', name: 'forum_new_topic')]
    #[IsGranted('ROLE_USER')]
    public function newTopic(Forum $forum, Request $request, EntityManagerInterface $entityManager): Response
    {
        $topic = new Topic();
        $topic->setForum($forum);
        $topic->setAuthor($this->getUser());

        $form = $this->createForm(TopicType::class, $topic);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($topic);
            $entityManager->flush();

            $this->addFlash('success', 'Topic created successfully!');

            return $this->redirectToRoute('topic_show', [
                'forumId' => $forum->getId(),
                'id' => $topic->getId(),
            ]);
        }

        return $this->render('forum/new_topic.html.twig', [
            'forum' => $forum,
            'form' => $form,
        ]);
    }
}
