<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Topic;
use App\Form\CommentType;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/topic/{forumId}/{id}')]
class TopicController extends AbstractController
{
    #[Route('', name: 'topic_show')]
    public function show(Topic $topic, CommentRepository $commentRepository): Response
    {
        // Increment view count
        $topic->incrementViews();
        $commentRepository->getEntityManager()->flush();

        $comments = $commentRepository->findBy(['topic' => $topic], ['createdAt' => 'ASC']);

        return $this->render('topic/show.html.twig', [
            'topic' => $topic,
            'comments' => $comments,
        ]);
    }

    #[Route('/edit', name: 'topic_edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Topic $topic, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($topic->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(\App\Form\TopicType::class, $topic);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Topic updated successfully!');

            return $this->redirectToRoute('topic_show', [
                'forumId' => $topic->getForum()->getId(),
                'id' => $topic->getId(),
            ]);
        }

        return $this->render('topic/edit.html.twig', [
            'topic' => $topic,
            'form' => $form,
        ]);
    }
}
