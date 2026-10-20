<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Form\MessageType;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class PeopleController extends AbstractController
{
    #[Route('/gens', name: 'people_index')]
    public function index(UserRepository $users): Response
    {
        return $this->render('people/index.html.twig', ['people' => $users->findBy(['enabled' => true], ['username' => 'ASC'])]);
    }

    /**
     * A member's page. On your own page the conversation is your private memos (messages to yourself),
     * on somebody else's page it is the private conversation between the two of you.
     */
    #[Route('/gens/{username}', name: 'people_show', requirements: ['username' => '[^/]+'])]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['username' => 'username'])] User $person,
        #[CurrentUser] User $me,
        MessageRepository $messages,
        EntityManagerInterface $em,
    ): Response {
        $message = (new Message())->setAuthor($me)->setRecipient($person);
        $form = $this->createForm(MessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($message);
            $em->flush();

            return $this->redirectToRoute('people_show', ['username' => $person->getUsername()]);
        }

        $conversation = $messages->findConversation($me, $person, $me->getMessageOrder());
        if ($person !== $me) {
            $messages->markConversationRead($me, $person);
        }

        return $this->render('people/show.html.twig', [
            'person' => $person,
            'isMe' => $person === $me,
            'conversation' => $conversation,
            'form' => $form,
        ]);
    }

    /** Called by the theme toggle of the navigation bar. */
    #[Route('/preferences/theme', name: 'user_theme', methods: ['POST'])]
    public function theme(Request $request, #[CurrentUser] User $me, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isCsrfTokenValid('theme', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $theme = $request->getPayload()->getString('theme');
        if (!\in_array($theme, ['light', 'dark'], true)) {
            return new JsonResponse(['error' => 'Invalid theme'], Response::HTTP_BAD_REQUEST);
        }

        $me->setTheme($theme);
        $em->flush();

        return new JsonResponse(['theme' => $theme]);
    }
}
