<?php

namespace App\Controller;

use App\Entity\Quote;
use App\Entity\User;
use App\Form\QuoteType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Any member may add a quote (the button under the quote of the aside). Editing and removing stay with the administrators (`/admin/quotes`). */
class QuoteController extends AbstractController
{
    #[Route('/citations/nouvelle', name: 'quote_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $me, EntityManagerInterface $em): Response
    {
        $quote = (new Quote())->setAuthor($me);
        $form = $this->createForm(QuoteType::class, $quote);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($quote);
            $em->flush();
            $this->addFlash('success', 'Merci, la citation a été ajoutée.');

            return $this->redirectToRoute('app_home');
        }

        return $this->render('quote/new.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
