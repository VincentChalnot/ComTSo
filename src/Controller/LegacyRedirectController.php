<?php

namespace App\Controller;

use App\Photo\PhotoUrls;
use App\Repository\PhotoRepository;
use App\Repository\TopicRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Links to older generations of the forum were pasted in messages: keep them alive (members only, like everything else:
 * the firewall sends anonymous visitors to the login before these routes run).
 *
 * - the very first PHP version: `topic.php?id=153`;
 * - the old settings page `/gens/{username}/config` (now the settings centre, profile section);
 * - the old forum: `/photos/preview/73.jpg` (a picture opened in a new tab) and `/photos/thumbnail/73.jpg` (its thumbnail),
 *   where 73 is the old forum's photo number, i.e. the `73.jpg` stored in `ctso_photo.original_filename` by the one-off data load. The same name can come back later when somebody uploads a camera file called `73.jpg`:
 *   the oldest photo wins, which is the imported one.
 */
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

    #[Route('/gens/{username}/config', name: 'legacy_people_config', requirements: ['username' => '[^/]+'])]
    public function config(string $username): RedirectResponse
    {
        return $this->redirectToRoute('settings_profile', ['username' => $username], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/photos/{kind}/{number}.{extension}', name: 'legacy_photo', requirements: ['kind' => 'preview|thumbnail', 'number' => '\d+', 'extension' => 'jpe?g|png|gif'])]
    public function photo(string $kind, string $number, string $extension, PhotoRepository $photos, PhotoUrls $photoUrls): RedirectResponse
    {
        $photo = $photos->findOneBy(['originalFilename' => $number.'.'.$extension], ['id' => 'ASC']);
        if (!$photo) {
            throw $this->createNotFoundException();
        }

        $response = new RedirectResponse($photoUrls->url($photo, 'preview' === $kind ? 'medium' : 'thumb'), Response::HTTP_MOVED_PERMANENTLY);
        // Browsers keep a 301 forever by default: the target carries the file version, which changes when the photo is rotated.
        $response->setPrivate()->setMaxAge(3600);

        return $response;
    }
}
