<?php

namespace App\Forum;

use App\Controller\TopicController;
use App\Entity\Topic;
use App\Entity\User;
use App\Pagination\Pager;
use App\Repository\CommentPhotoRepository;
use App\Repository\CommentRepository;
use App\Repository\PhotoTopicRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Everything `topic/show.html.twig` needs besides the reply form: the page of messages (in the member's own order; the
 * latest page unless `?page=` is given), the topic photos, the pager. Shared by the topic page and by the reply
 * endpoint, which shows the same page again, with the typed text, when the reply is refused.
 */
class TopicPageBuilder
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly PhotoTopicRepository $photoTopics,
        private readonly CommentPhotoRepository $commentPhotos,
    ) {
    }

    /** @return array{topic: Topic, comments: list<\App\Entity\Comment>, photos: array<mixed>, pager: Pager, order: string, starred: bool} */
    public function build(Request $request, Topic $topic, User $user): array
    {
        $order = $user->getMessageOrder();
        $requested = $request->query->has('page') ? $request->query->getInt('page', 1) : ('asc' === $order ? PHP_INT_MAX : 1);
        $pager = new Pager($this->comments->countInTopic($topic), TopicController::MESSAGES_PER_PAGE, $requested);

        $pageComments = $this->comments->findPage($topic, $order, $pager->offset(), $pager->perPage);
        $this->commentPhotos->preload($pageComments);

        return [
            'topic' => $topic,
            'comments' => $pageComments,
            'photos' => $this->photoTopics->findForTopic($topic),
            'pager' => $pager,
            'order' => $order,
            'starred' => $user->getStarredTopics()->contains($topic),
        ];
    }
}
