<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Topic;
use App\Entity\User;
use App\Form\CommentType;
use App\Pagination\Pager;
use App\Photo\PostPhotos;
use App\Repository\CommentPhotoRepository;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class CommentController extends AbstractController
{
    /** A message on its own page: the stable address to link to one precise message. */
    #[Route('/comment/{id}', name: 'comment_show', requirements: ['id' => '\d+'])]
    public function show(Comment $comment, #[CurrentUser] User $user, CommentRepository $comments, CommentPhotoRepository $commentPhotos): Response
    {
        $commentPhotos->preload([$comment]);

        return $this->render('comment/show.html.twig', [
            'comment' => $comment,
            'topicUrl' => $this->topicUrlOf($comment, $user, $comments),
        ]);
    }

    #[Route('/forum/{forumId}/{id}/comment', name: 'comment_create', requirements: ['forumId' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['POST'])]
    public function create(
        Request $request,
        #[MapEntity(id: 'id')] Topic $topic,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        CommentRepository $comments,
        PostPhotos $postPhotos,
    ): Response {
        $comment = (new Comment())->setTopic($topic)->setAuthor($user);
        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $form->getErrors(true)->count() ? $form->getErrors(true)->current()->getMessage() : 'Le message n\'a pas pu être envoyé.');

            return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
        }

        try {
            $photos = $postPhotos->store($form->get('photos')->getData() ?? [], $user);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
        }

        $comment->setCreatedAt($now = new \DateTime())->setUpdatedAt($now);
        $topic->registerNewComment($now);
        $em->persist($comment);
        $postPhotos->attachToComment($comment, $photos, $user);
        $em->flush();

        return $this->redirect($this->topicUrlOf($comment, $user, $comments));
    }

    #[Route('/comment/{id}/edit', name: 'comment_edit', requirements: ['id' => '\d+'])]
    public function edit(
        Request $request,
        Comment $comment,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        CommentRepository $comments,
        PostPhotos $postPhotos,
    ): Response {
        $this->denyUnlessCanManage($comment);

        $form = $this->createForm(CommentType::class, $comment);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $added = $postPhotos->store($form->get('photos')->getData() ?? [], $user);
            } catch (\InvalidArgumentException $e) {
                $form->get('photos')->addError(new FormError($e->getMessage()));

                return $this->render('comment/edit.html.twig', ['comment' => $comment, 'form' => $form]);
            }

            $detached = $postPhotos->detach($comment, $form->has('remove_photos') ? $form->get('remove_photos')->getData() : []);
            $postPhotos->attachToComment($comment, $added, $user);
            if ($added || $detached) {
                $comment->setUpdatedAt(new \DateTime());
            }
            $em->flush();
            $postPhotos->releaseOrphans($detached);
            $this->addFlash('success', 'Le message a été modifié.');

            return $this->redirect($this->topicUrlOf($comment, $user, $comments));
        }

        return $this->render('comment/edit.html.twig', ['comment' => $comment, 'form' => $form]);
    }

    #[Route('/comment/{id}/delete', name: 'comment_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Comment $comment, EntityManagerInterface $em, PostPhotos $postPhotos): Response
    {
        $this->denyUnlessCanManage($comment);
        if (!$this->isCsrfTokenValid('delete-comment-'.$comment->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $topic = $comment->getTopic();
        $topic->setCommentCount(max(0, $topic->getCommentCount() - 1));
        // The links vanish with the message (ON DELETE CASCADE); photos used nowhere else must not stay behind as files.
        $photos = $postPhotos->photosOf($comment);
        $em->remove($comment);
        $em->flush();
        $postPhotos->releaseOrphans($photos);
        $this->addFlash('success', 'Le message a été supprimé.');

        return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
    }

    private function denyUnlessCanManage(Comment $comment): void
    {
        if ($comment->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
    }

    /** URL of the topic page that contains the message (according to the member's order), anchored on it. */
    private function topicUrlOf(Comment $comment, User $user, CommentRepository $comments): string
    {
        $topic = $comment->getTopic();
        $page = Pager::pageOfPosition($comments->positionOf($comment, $user->getMessageOrder()), TopicController::MESSAGES_PER_PAGE);

        return $this->generateUrl('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId(), 'page' => $page]).'#post-'.$comment->getId();
    }
}
