<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Liveness probe for the container healthcheck / uptime monitor: public, never cached, says nothing about the application. */
class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET', 'HEAD'])]
    public function __invoke(Connection $connection, LoggerInterface $logger): Response
    {
        try {
            $connection->executeQuery('SELECT 1')->fetchOne();
            $response = new Response('ok');
        } catch (\Throwable $e) {
            // The reason goes to the logs, never to whoever asked.
            $logger->error('Health check failed: '.$e->getMessage(), ['exception' => $e]);
            $response = new Response('unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
