<?php

namespace App\Controller;

use App\Repository\ForumRepository;
use App\Repository\TopicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(ForumRepository $forumRepository, TopicRepository $topicRepository): Response
    {
        $forums = $forumRepository->findBy([], ['order' => 'ASC', 'title' => 'ASC']);
        $recentTopics = $topicRepository->findBy([], ['createdAt' => 'DESC'], 10);

        return $this->render('home/index.html.twig', [
            'forums' => $forums,
            'recent_topics' => $recentTopics,
        ]);
    }
}
