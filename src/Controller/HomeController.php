<?php

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Pager;
use App\Photo\FeaturedPhotoProvider;
use App\Repository\CommentPhotoRepository;
use App\Repository\CommentRepository;
use App\Repository\PhotoTopicRepository;
use App\Repository\TopicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class HomeController extends AbstractController
{
    public const TOPICS_PER_PAGE = 8;
    public const MESSAGES_PER_TOPIC = 15;

    /** "Activités récentes": everything posted since a date (by default the end of the member's previous visit), grouped by topic. */
    #[Route('/', name: 'app_home')]
    public function index(
        Request $request,
        #[CurrentUser] User $user,
        TopicRepository $topics,
        CommentRepository $comments,
        PhotoTopicRepository $photoTopics,
        FeaturedPhotoProvider $featuredPhoto,
        CommentPhotoRepository $commentPhotos,
    ): Response {
        $since = $this->resolveSince($request, $user);
        $order = $user->getMessageOrder();

        $pager = new Pager($topics->countActiveSince($since), self::TOPICS_PER_PAGE, $request->query->getInt('page', 1));
        $cards = [];
        foreach ($topics->findActiveSince($since, $pager->offset(), $pager->perPage) as $topic) {
            $newMessages = $comments->countSince($topic, $since);
            $latest = $comments->findLatestSince($topic, $since, $order, self::MESSAGES_PER_TOPIC);
            $cards[] = [
                'topic' => $topic,
                'comments' => $latest,
                'hidden' => max(0, $newMessages - \count($latest)),
                'photos' => $photoTopics->findSince($topic, $since),
                'created' => $topic->getCreatedAt() >= $since,
            ];
        }
        $commentPhotos->preload(array_merge(...array_column($cards, 'comments')));

        $response = $this->render('home/index.html.twig', [
            'since' => $since,
            'cards' => $cards,
            'pager' => $pager,
            'hero' => $featuredPhoto->select(),
        ]);

        // The hero is picked at random on every visit: nothing may keep a copy of the page.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function resolveSince(Request $request, User $user): \DateTimeImmutable
    {
        // `form[since]` is the parameter name used by the legacy site: old shared links keep working.
        $raw = $request->query->get('since') ?? $request->query->all('form')['since'] ?? null;
        if (\is_string($raw) && '' !== $raw) {
            foreach (['!Y-m-d', '!d/m/Y'] as $format) {
                if (($date = \DateTimeImmutable::createFromFormat($format, $raw)) && $date <= new \DateTimeImmutable('tomorrow')) {
                    return $date;
                }
            }
        }

        $previous = $user->getPreviousLogin();

        return $previous ? \DateTimeImmutable::createFromInterface($previous) : new \DateTimeImmutable('-7 days');
    }
}
