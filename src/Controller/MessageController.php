<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Form\MessageType;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/message')]
#[IsGranted('ROLE_USER')]
class MessageController extends AbstractController
{
    #[Route('/', name: 'message_index')]
    public function index(MessageRepository $messageRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $received = $messageRepository->createQueryBuilder('m')
            ->where('m.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $sent = $messageRepository->createQueryBuilder('m')
            ->where('m.author = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return $this->render('message/index.html.twig', [
            'received_messages' => $received,
            'sent_messages' => $sent,
        ]);
    }

    #[Route('/compose', name: 'message_compose')]
    #[Route('/compose/{username}', name: 'message_compose_to')]
    public function compose(?string $username, Request $request, EntityManagerInterface $entityManager): Response
    {
        $message = new Message();
        $message->setAuthor($this->getUser());

        if ($username) {
            $recipient = $entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if ($recipient) {
                $message->setRecipient($recipient);
            }
        }

        $form = $this->createForm(MessageType::class, $message);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($message);
            $entityManager->flush();

            $this->addFlash('success', 'Message sent successfully!');

            return $this->redirectToRoute('message_index');
        }

        return $this->render('message/compose.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'message_show')]
    public function show(Message $message, EntityManagerInterface $entityManager): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($message->getAuthor() !== $user && $message->getRecipient() !== $user) {
            throw $this->createAccessDeniedException();
        }

        // Mark as read if recipient is viewing
        if ($message->getRecipient() === $user && $message->getState() === null) {
            $message->setState(1);
            $entityManager->flush();
        }

        return $this->render('message/show.html.twig', [
            'message' => $message,
        ]);
    }
}
