<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/user')]
class UserController extends AbstractController
{
    #[Route('/{username}', name: 'user_profile')]
    public function profile(User $user): Response
    {
        return $this->render('user/profile.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/{username}/edit', name: 'user_profile_edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($user !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(UserProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Profile updated successfully!');

            return $this->redirectToRoute('user_profile', ['username' => $user->getUsername()]);
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{username}/theme', name: 'user_theme_toggle', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function toggleTheme(User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($user !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $currentTheme = $user->getTheme();
        $newTheme = $currentTheme === 'dark' ? 'light' : 'dark';
        $user->setTheme($newTheme);

        $entityManager->flush();

        return $this->redirectToRoute($request->headers->get('referer') ?? 'app_home');
    }
}
