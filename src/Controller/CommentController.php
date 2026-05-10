<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Topic;
use App\Form\CommentType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/comment')]
#[IsGranted('ROLE_USER')]
class CommentController extends AbstractController
{
    #[Route('/create/{forumId}/{topicId}', name: 'comment_create')]
    public function create(Topic $topic, Request $request, EntityManagerInterface $entityManager): Response
    {
        $comment = new Comment();
        $comment->setTopic($topic);
        $comment->setAuthor($this->getUser());

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($comment);

            // Update topic comment count
            $topic->setCommentCount($topic->getCommentCount() + 1);

            $entityManager->flush();

            $this->addFlash('success', 'Comment added successfully!');

            return $this->redirectToRoute('topic_show', [
                'forumId' => $topic->getForum()->getId(),
                'id' => $topic->getId(),
            ]);
        }

        return $this->render('comment/create.html.twig', [
            'topic' => $topic,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'comment_edit')]
    public function edit(Comment $comment, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($comment->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Comment updated successfully!');

            return $this->redirectToRoute('topic_show', [
                'forumId' => $comment->getTopic()->getForum()->getId(),
                'id' => $comment->getTopic()->getId(),
            ]);
        }

        return $this->render('comment/edit.html.twig', [
            'comment' => $comment,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'comment_delete', methods: ['POST'])]
    public function delete(Comment $comment, EntityManagerInterface $entityManager): Response
    {
        if ($comment->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $topic = $comment->getTopic();
        $topic->setCommentCount(max(0, $topic->getCommentCount() - 1));

        $entityManager->remove($comment);
        $entityManager->flush();

        $this->addFlash('success', 'Comment deleted successfully!');

        return $this->redirectToRoute('topic_show', [
            'forumId' => $topic->getForum()->getId(),
            'id' => $topic->getId(),
        ]);
    }
}
