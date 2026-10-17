<?php

namespace App\Controller;

use App\Repository\ChatMessageRepository;
use App\Repository\CommentRepository;
use App\Repository\PhotoRepository;
use App\Repository\QuoteRepository;
use App\Repository\TopicRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SearchController extends AbstractController
{
    public const LIMIT = 30;
    public const MIN_LENGTH = 2;

    #[Route('/recherche', name: 'search')]
    public function __invoke(
        Request $request,
        CommentRepository $comments,
        TopicRepository $topics,
        PhotoRepository $photos,
        ChatMessageRepository $chat,
        UserRepository $users,
        QuoteRepository $quotes,
    ): Response {
        $term = trim($request->query->getString('q'));
        $results = null;

        if (mb_strlen($term) >= self::MIN_LENGTH) {
            $results = [
                'comments' => $comments->search($term, self::LIMIT),
                'topics' => $topics->search($term, self::LIMIT),
                'photos' => $photos->search($term, self::LIMIT),
                'chat' => $chat->search($term, self::LIMIT),
                'people' => $users->search($term, self::LIMIT),
                'quotes' => $quotes->search($term, self::LIMIT),
            ];
        }

        return $this->render('search/index.html.twig', ['term' => $term, 'results' => $results, 'limit' => self::LIMIT]);
    }
}
