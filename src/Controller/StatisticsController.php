<?php

namespace App\Controller;

use App\Stats\MessageStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Read-only page for every member: who wrote how many messages, year by year. */
class StatisticsController extends AbstractController
{
    #[Route('/statistiques', name: 'stats_index')]
    public function __invoke(MessageStatistics $statistics): Response
    {
        return $this->render('stats/index.html.twig', ['report' => $statistics->report()]);
    }
}
