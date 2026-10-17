<?php

namespace App\Controller;

use App\Repository\TopicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Links to the very first PHP version of the forum (`topic.php?id=153`) were pasted in messages: keep them alive. */
class LegacyRedirectController extends AbstractController
{
    #[Route('/topic.php', name: 'legacy_topic')]
    public function topic(Request $request, TopicRepository $topics): RedirectResponse
    {
        $topic = $topics->find($request->query->getInt('id'));
        if (!$topic) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('topic_show', ['forumId' => $topic->getForum()->getId(), 'id' => $topic->getId()], Response::HTTP_MOVED_PERMANENTLY);
    }
}
