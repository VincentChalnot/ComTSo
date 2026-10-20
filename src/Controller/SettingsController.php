<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\AvatarType;
use App\Form\ChangePasswordType;
use App\Form\UserProfileType;
use App\Photo\AvatarManager;
use App\Stats\MessageStatistics;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

/**
 * The settings centre: `/gens/{username}/parametres/<section>`, a menu on the left and ONE form per section, each saved on its own
 * (Symfony forms, so each carries its CSRF token; a refused form is shown again with HTTP 422 and the typed text).
 *
 * Who may open what: the profile and the display preferences belong to their member and, as always, to administrators;
 * the avatar, the password and the statistics page are the member's own business (nobody else, administrators included: `app:user:reset-password` is the way
 * for a lost password).
 */
#[Route('/gens/{username}/parametres', requirements: ['username' => '[^/]+'])]
class SettingsController extends AbstractController
{
    #[Route('/profil', name: 'settings_profile', methods: ['GET', 'POST'])]
    public function profile(Request $request, #[MapEntity(mapping: ['username' => 'username'])] User $person, EntityManagerInterface $em): Response
    {
        $this->denyUnlessSelfOrAdmin($person);

        $form = $this->createForm(UserProfileType::class, $person);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Le profil a été enregistré.');

            return $this->redirectToRoute('settings_profile', ['username' => $person->getUsername()]);
        }

        return $this->renderSection('profile', $person, $form);
    }

    #[Route('/affichage', name: 'settings_display', methods: ['GET', 'POST'])]
    public function display(Request $request, #[MapEntity(mapping: ['username' => 'username'])] User $person, EntityManagerInterface $em): Response
    {
        $this->denyUnlessSelfOrAdmin($person);

        $form = $this->createFormBuilder(['message_order' => $person->getMessageOrder()])
            ->add('message_order', ChoiceType::class, [
                'label' => 'Ordre des messages',
                'choices' => ['Plus récent en dernier' => 'asc', 'Plus récent en premier' => 'desc'],
                'expanded' => true,
            ])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $person->setMessageOrder($form->get('message_order')->getData());
            $em->flush();
            $this->addFlash('success', 'Vos préférences d’affichage ont été enregistrées.');

            return $this->redirectToRoute('settings_display', ['username' => $person->getUsername()]);
        }

        return $this->renderSection('display', $person, $form);
    }

    /**
     * Change or remove your own avatar. The image goes through the photo pipeline; the previous avatar is deleted unless
     * something else still uses it.
     */
    #[Route('/avatar', name: 'settings_avatar', methods: ['GET', 'POST'])]
    public function avatar(
        Request $request,
        #[MapEntity(mapping: ['username' => 'username'])] User $person,
        #[CurrentUser] User $me,
        AvatarManager $avatars,
    ): Response {
        $this->denyUnlessSelf($person, $me);

        $form = $this->createForm(AvatarType::class, null, ['has_avatar' => null !== $me->getAvatar()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->has('remove') && $form->get('remove')->isClicked()) {
                $avatars->remove($me);
                $this->addFlash('success', 'Votre avatar a été retiré.');

                return $this->redirectToRoute('settings_avatar', ['username' => $me->getUsername()]);
            }

            $file = $form->get('file')->getData();
            if (null === $file) {
                $form->get('file')->addError(new FormError('Choisissez une image.'));
            } else {
                try {
                    $avatars->change($me, $file);
                    $this->addFlash('success', 'Votre avatar a été modifié.');

                    return $this->redirectToRoute('settings_avatar', ['username' => $me->getUsername()]);
                } catch (\InvalidArgumentException $e) {
                    $form->get('file')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->renderSection('avatar', $person, $form);
    }

    /**
     * Change your own password. The current password is required, the new one is hashed with the native hasher (which also
     * retires a legacy SHA-512 hash). Every other session and remember-me cookie dies with the old hash, so this device is
     * logged in again on a fresh session.
     */
    #[Route('/securite', name: 'settings_security', methods: ['GET', 'POST'])]
    public function security(
        Request $request,
        #[MapEntity(mapping: ['username' => 'username'])] User $person,
        #[CurrentUser] User $me,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
    ): Response {
        $this->denyUnlessSelf($person, $me);

        $form = $this->createForm(ChangePasswordType::class, null, ['current_password' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $me->setPassword($hasher->hashPassword($me, $form->get('plainPassword')->getData()))->setSalt(null);
            $em->flush();
            $security->login($me, 'form_login', 'main', [new RememberMeBadge()]);
            $this->addFlash('success', 'Votre mot de passe a été modifié.');

            return $this->redirectToRoute('settings_security', ['username' => $me->getUsername()]);
        }

        return $this->renderSection('security', $person, $form);
    }

    /** Read-only for every member, but inside the centre of the member who opens it. */
    #[Route('/statistiques', name: 'settings_stats', methods: ['GET'])]
    public function statistics(#[MapEntity(mapping: ['username' => 'username'])] User $person, #[CurrentUser] User $me, MessageStatistics $statistics): Response
    {
        $this->denyUnlessSelf($person, $me);

        return $this->render('settings/stats.html.twig', ['person' => $person, 'section' => 'stats', 'report' => $statistics->report()]);
    }

    private function renderSection(string $section, User $person, FormInterface $form): Response
    {
        $status = $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('settings/'.$section.'.html.twig', ['person' => $person, 'section' => $section, 'form' => $form], new Response(status: $status));
    }

    private function denyUnlessSelf(User $person, User $me): void
    {
        if ($person !== $me) {
            throw $this->createAccessDeniedException();
        }
    }

    private function denyUnlessSelfOrAdmin(User $person): void
    {
        if ($person !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
    }
}
