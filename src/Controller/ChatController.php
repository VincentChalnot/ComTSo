<?php

namespace App\Controller;

use App\Entity\ChatMessage;
use App\Repository\ChatMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/chat')]
#[IsGranted('ROLE_USER')]
class ChatController extends AbstractController
{
    #[Route('/', name: 'chat_index')]
    public function index(ChatMessageRepository $chatMessageRepository): Response
    {
        $messages = $chatMessageRepository->findBy([], ['createdAt' => 'DESC'], 50);

        return $this->render('chat/index.html.twig', [
            'messages' => array_reverse($messages),
        ]);
    }

    #[Route('/api/messages', name: 'chat_api_messages', methods: ['GET'])]
    public function messages(ChatMessageRepository $chatMessageRepository, Request $request): JsonResponse
    {
        $since = $request->query->get('since');
        $qb = $chatMessageRepository->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(50);

        if ($since) {
            $qb->where('m.id > :since')
                ->setParameter('since', $since);
        }

        $messages = $qb->getQuery()->getResult();

        return new JsonResponse(array_reverse($messages));
    }

    #[Route('/api/send', name: 'chat_api_send', methods: ['POST'])]
    public function send(Request $request, EntityManagerInterface $entityManager, HubInterface $hub): JsonResponse
    {
        $content = $request->request->get('content');

        if (empty($content)) {
            return new JsonResponse(['error' => 'Message content cannot be empty'], 400);
        }

        $message = new ChatMessage();
        $message->setContent($content);
        $message->setAuthor($this->getUser());

        $entityManager->persist($message);
        $entityManager->flush();

        // Publish to Mercure hub
        $update = new Update(
            'chat',
            json_encode($message)
        );

        $hub->publish($update);

        return new JsonResponse($message);
    }
}
