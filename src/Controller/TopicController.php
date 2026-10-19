<?php

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\Photo;
use App\Entity\PhotoTopic;
use App\Entity\Topic;
use App\Entity\User;
use App\Form\CommentType;
use App\Form\TopicType;
use App\Forum\TopicPageBuilder;
use App\Forum\TopicRemover;
use App\Photo\PhotoUrls;
use App\Repository\PhotoTopicRepository;
use App\Service\PhotoUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/forum/{forumId}/{id}', requirements: ['forumId' => '[a-z0-9-]+', 'id' => '\d+'])]
class TopicController extends AbstractController
{
    public const MESSAGES_PER_PAGE = 20;

    /**
     * A topic: description, photos and a page of messages. Messages are listed in the member's own order
     * (config: oldest first or newest first) and, unless a page is requested, the landing page is the one with the latest messages.
     */
    #[Route('', name: 'topic_show')]
    public function show(
        Request $request,
        string $forumId,
        #[MapEntity(id: 'id')] Topic $topic,
        #[CurrentUser] User $user,
        TopicPageBuilder $page,
        EntityManagerInterface $em,
    ): Response {
        if ($redirect = $this->canonicalRedirect($topic, $forumId, $request)) {
            return $redirect;
        }

        // Plain UPDATE: counting a view must neither touch "updated at" nor load anything.
        $em->createQuery('UPDATE '.Topic::class.' t SET t.views = t.views + 1 WHERE t.id = :id')->setParameter('id', $topic->getId())->execute();

        $commentForm = $this->createForm(CommentType::class, new Comment(), [
            'action' => $this->generateUrl('comment_create', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]),
        ]);

        return $this->render('topic/show.html.twig', $page->build($request, $topic, $user) + ['commentForm' => $commentForm]);
    }

    #[Route('/edit', name: 'topic_edit')]
    public function edit(Request $request, string $forumId, #[MapEntity(id: 'id')] Topic $topic, EntityManagerInterface $em): Response
    {
        $this->denyUnlessCanManage($topic);

        $form = $this->createForm(TopicType::class, $topic, ['allow_move' => $this->isGranted('ROLE_ADMIN')]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Le sujet a été modifié.');

            return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
        }

        return $this->render('topic/edit.html.twig', ['topic' => $topic, 'form' => $form]);
    }

    /** Moderation: administrators only. The author of a topic may edit it, but not erase the messages of the others. */
    #[Route('/delete', name: 'topic_delete', methods: ['POST'])]
    public function delete(Request $request, string $forumId, #[MapEntity(id: 'id')] Topic $topic, TopicRemover $remover): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        if (!$this->isCsrfTokenValid('delete-topic-'.$topic->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $forum = $topic->getForum();
        $title = $topic->getTitle();
        $remover->remove($topic);
        $this->addFlash('success', sprintf('Le sujet « %s » a été supprimé.', $title));

        return $this->redirectToRoute('forum_show', ['forumId' => $forum->getId()]);
    }

    #[Route('/star', name: 'topic_star', methods: ['POST'])]
    public function star(Request $request, #[MapEntity(id: 'id')] Topic $topic, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        return $this->toggleStar($request, $topic, $user, $em, true);
    }

    #[Route('/unstar', name: 'topic_unstar', methods: ['POST'])]
    public function unstar(Request $request, #[MapEntity(id: 'id')] Topic $topic, #[CurrentUser] User $user, EntityManagerInterface $em): Response
    {
        return $this->toggleStar($request, $topic, $user, $em, false);
    }

    private function toggleStar(Request $request, Topic $topic, User $user, EntityManagerInterface $em, bool $star): Response
    {
        if (!$this->isCsrfTokenValid('star-'.$topic->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $star ? $user->addStarredTopic($topic) : $user->removeStarredTopic($topic);
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['starred' => $star]);
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]));
    }

    /** Photo management: add, reorder, remove. */
    #[Route('/photos', name: 'topic_photos')]
    public function photos(string $forumId, #[MapEntity(id: 'id')] Topic $topic, PhotoTopicRepository $photoTopics): Response
    {
        $this->denyUnlessCanManage($topic);

        return $this->render('topic/photos.html.twig', ['topic' => $topic, 'photos' => $photoTopics->findForTopic($topic)]);
    }

    #[Route('/photos/upload', name: 'topic_photos_upload', methods: ['POST'])]
    public function uploadPhoto(
        Request $request,
        #[MapEntity(id: 'id')] Topic $topic,
        #[CurrentUser] User $user,
        PhotoUploader $uploader,
        PhotoUrls $photoUrls,
        PhotoTopicRepository $photoTopics,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyUnlessCanManage($topic);
        if (!$this->isCsrfTokenValid('photos-'.$topic->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return new JsonResponse(['error' => 'Aucun fichier reçu.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $photo = $uploader->store($file, $user);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $link = (new PhotoTopic())->setTopic($topic)->setPhoto($photo)->setAuthor($user)->setOrder($photoTopics->nextOrder($topic));
        $topic->setLastActivityAt(new \DateTime());
        $em->persist($photo);
        $em->persist($link);
        $em->flush();

        return new JsonResponse(['id' => $photo->getId(), 'link' => $link->getId(), 'thumb' => $photoUrls->url($photo, 'thumb')], Response::HTTP_CREATED);
    }

    #[Route('/photos/order', name: 'topic_photos_order', methods: ['POST'])]
    public function orderPhotos(Request $request, #[MapEntity(id: 'id')] Topic $topic, PhotoTopicRepository $photoTopics, EntityManagerInterface $em): JsonResponse
    {
        $this->denyUnlessCanManage($topic);
        if (!$this->isCsrfTokenValid('photos-'.$topic->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $position = array_flip(array_map('intval', $request->getPayload()->all('links')));
        foreach ($photoTopics->findForTopic($topic) as $link) {
            if (isset($position[$link->getId()])) {
                $link->setOrder($position[$link->getId()]);
            }
        }
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/photos/{linkId}/remove', name: 'topic_photo_remove', requirements: ['linkId' => '\d+'], methods: ['POST'])]
    public function removePhoto(
        Request $request,
        #[MapEntity(id: 'id')] Topic $topic,
        int $linkId,
        PhotoTopicRepository $photoTopics,
        PhotoUploader $uploader,
        EntityManagerInterface $em,
    ): Response {
        $this->denyUnlessCanManage($topic);
        if (!$this->isCsrfTokenValid('photos-'.$topic->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $link = $photoTopics->find($linkId);
        if (!$link || $link->getTopic() !== $topic) {
            throw $this->createNotFoundException();
        }

        $photo = $link->getPhoto();
        $em->remove($link);
        // "Delete the photo" also removes the file; otherwise the photo just leaves this topic.
        if ($request->getPayload()->getBoolean('delete_file') && $this->canManagePhoto($photo)) {
            $em->remove($photo);
            $uploader->delete($photo);
        }
        $em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true]);
        }
        $this->addFlash('success', 'La photo a été retirée.');

        return $this->redirectToRoute('topic_photos', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()]);
    }

    private function canManagePhoto(Photo $photo): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $photo->getAuthor() === $this->getUser();
    }

    /** Topics, like messages, are editable by their author and by administrators. */
    private function denyUnlessCanManage(Topic $topic): void
    {
        if ($topic->getAuthor() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
    }

    /** Old links may carry the wrong forum slug (topics moved between forums): send them to the canonical URL. */
    private function canonicalRedirect(Topic $topic, string $forumId, Request $request): ?RedirectResponse
    {
        if ($topic->getForum()->getId() === $forumId) {
            return null;
        }

        return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()] + $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }
}
