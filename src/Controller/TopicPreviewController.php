<?php

namespace App\Controller;

use App\Entity\Topic;
use App\Entity\User;
use App\Repository\CommentPhotoRepository;
use App\Repository\CommentRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class TopicPreviewController extends AbstractController
{
    public const MESSAGES = 5;

    /**
     * HTML fragment (no layout) with the latest messages of a topic, for the inline "Aperçu" of the forum listing.
     * Messages follow the member's own order; the response is private, it must never be shared by a cache.
     */
    #[Route('/forum/{forumId}/{id}/preview', name: 'topic_preview', requirements: ['forumId' => '[a-z0-9-]+', 'id' => '\d+'], methods: ['GET'])]
    public function __invoke(
        string $forumId,
        #[MapEntity(id: 'id')] Topic $topic,
        #[CurrentUser] User $user,
        CommentRepository $comments,
        CommentPhotoRepository $commentPhotos,
    ): Response {
        // Old links may carry the wrong forum slug (topic moved): same canonical redirect as TopicController::show.
        if ($topic->getForum()->getId() !== $forumId) {
            return $this->redirectToRoute('topic_preview', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()], Response::HTTP_MOVED_PERMANENTLY);
        }

        $latest = $comments->findLatest($topic, self::MESSAGES);
        $commentPhotos->preload($latest);

        $response = $this->render('topic/_preview.html.twig', [
            'topic' => $topic,
            'comments' => 'desc' === $user->getMessageOrder() ? $latest : array_reverse($latest),
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
