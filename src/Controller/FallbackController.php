<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catches every URL that no other route matches. Without it unknown URLs would answer 404 to anonymous
 * visitors (the router runs before the firewall), whereas the private forum must always send them to the login.
 * Authenticated users still get the regular 404 page.
 */
class FallbackController extends AbstractController
{
    #[Route('/{path}', name: 'app_fallback', requirements: ['path' => '.*'], priority: -1000)]
    public function __invoke(string $path): never
    {
        throw new NotFoundHttpException(sprintf('No route found for "/%s".', $path));
    }
}
