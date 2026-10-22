<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Topic;
use App\Entity\User;
use App\Form\CommentType;
use App\Forum\CommentRemover;
use App\Forum\TopicPageBuilder;
use App\Pagination\Pager;
use App\Photo\PostPhotos;
use App\Repository\CommentPhotoRepository;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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

    /**
     * Posts a reply. A refused reply (empty, too many photos, a file that cannot be stored…) shows the topic page again with
     * the bound form (HTTP 422): what the member typed is still in the editor, with the reason above it.
     * A GET (refresh of that page) goes back to the topic.
     */
    #[Route('/forum/{forumId}/{id}/comment', name: 'comment_create', requirements: ['forumId' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function create(
        Request $request,
        #[MapEntity(id: 'id')] Topic $topic,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        CommentRepository $comments,
        PostPhotos $postPhotos,
        TopicPageBuilder $page,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
        }

        $comment = (new Comment())->setTopic($topic)->setAuthor($user);
        $form = $this->createForm(CommentType::class, $comment);

        if (!$this->storeReply($request, $form, $comment, $user, $em, $postPhotos)) {
            return $this->render('topic/show.html.twig', $page->build($request, $topic, $user) + ['commentForm' => $form], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->redirect($this->topicUrlOf($comment, $user, $comments));
    }

    /**
     * The reply form of a home page card: the SAME form as the topic page (`CommentType`: rich text and photos, one instance
     * per card named `reply_<topicId>`) and the same write path ({@see storeReply()}), but the member goes back to the home
     * page, on the card of the topic, with the window (`since`, `page`) they were browsing. A refused reply (empty text,
     * photos refused, expired token) is a flash message and the same redirect: the home page is a list of many forms, there
     * is no single bound form to show again.
     */
    #[Route('/forum/{forumId}/{id}/reply', name: 'topic_reply', requirements: ['forumId' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['POST'])]
    public function reply(
        Request $request,
        #[MapEntity(id: 'id')] Topic $topic,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        PostPhotos $postPhotos,
        FormFactoryInterface $forms,
    ): Response {
        $comment = (new Comment())->setTopic($topic)->setAuthor($user);
        $form = $forms->createNamed(self::replyFormName($topic), CommentType::class, $comment);

        if ($this->storeReply($request, $form, $comment, $user, $em, $postPhotos)) {
            $this->addFlash('success', 'Votre réponse a été publiée.');
        } else {
            $reasons = [];
            foreach (['content', 'photos'] as $field) {
                foreach ($form->get($field)->getErrors() as $error) {
                    $reasons[] = $error->getMessage();
                }
            }
            $this->addFlash('error', 'Votre réponse n\'a pas été envoyée : '.($reasons ? implode(' ', $reasons) : 'la page a expiré, merci de réessayer.'));
        }

        $query = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->query->getString('since'))) {
            $query['since'] = $request->query->getString('since');
        }
        if (($page = $request->query->getInt('page')) > 1) {
            $query['page'] = $page;
        }

        return $this->redirect($this->generateUrl('app_home', $query).'#topic-'.$topic->getId());
    }

    /** Name of the reply form of a topic on the home page (unique per card: field ids and CSRF token). */
    public static function replyFormName(Topic $topic): string
    {
        return 'reply_'.$topic->getId();
    }

    /**
     * Binds the request to a reply form and, when it is valid, stores the message and its photos (all or nothing: a refused
     * file refuses the whole reply). Shared by the topic page and the home page.
     *
     * @return bool false when the reply was refused (the errors are on the form)
     */
    private function storeReply(Request $request, FormInterface $form, Comment $comment, User $user, EntityManagerInterface $em, PostPhotos $postPhotos): bool
    {
        $form->handleRequest($request);

        $photos = [];
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $photos = $postPhotos->store($form->get('photos')->getData() ?? [], $user);
            } catch (\InvalidArgumentException $e) {
                $form->get('photos')->addError(new FormError($e->getMessage()));
            }
        }

        if (!$form->isSubmitted() || !$form->isValid()) {
            return false;
        }

        $this->publish($comment, $em);
        $postPhotos->attachToComment($comment, $photos, $user);
        $em->flush();

        return true;
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
    public function delete(Request $request, Comment $comment, CommentRemover $remover): Response
    {
        $this->denyUnlessCanManage($comment);
        if (!$this->isCsrfTokenValid('delete-comment-'.$comment->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $topic = $comment->getTopic();
        $remover->remove($comment);
        $this->addFlash('success', 'Le message a été supprimé.');

        return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
    }

    /** Stamps the new message and the topic's counters, and queues the message (the caller flushes). */
    private function publish(Comment $comment, EntityManagerInterface $em): void
    {
        $comment->setCreatedAt($now = new \DateTime())->setUpdatedAt($now);
        $comment->getTopic()->registerNewComment($now);
        $em->persist($comment);
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
