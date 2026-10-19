<?php

namespace App\Controller;

use App\Entity\Message;
use App\Entity\User;
use App\Form\MessageType;
use App\Form\AvatarType;
use App\Form\ChangePasswordType;
use App\Form\UserProfileType;
use App\Photo\AvatarManager;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;

class PeopleController extends AbstractController
{
    private const USER = ['mapping' => ['username' => 'username']];

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

    #[Route('/gens/{username}/edit', name: 'people_edit', requirements: ['username' => '[^/]+'])]
    public function edit(Request $request, #[MapEntity(mapping: ['username' => 'username'])] User $person, EntityManagerInterface $em): Response
    {
        $this->denyUnlessSelfOrAdmin($person);

        $form = $this->createForm(UserProfileType::class, $person);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Le profil a été enregistré.');

            return $this->redirectToRoute('people_show', ['username' => $person->getUsername()]);
        }

        return $this->render('people/edit.html.twig', ['person' => $person, 'form' => $form]);
    }

    /**
     * Personal settings: the order of the messages (the light/dark theme has its own toggle in the navigation bar) and,
     * on your own page, the password form (it posts to {@see self::password()}).
     */
    #[Route('/gens/{username}/config', name: 'people_config', requirements: ['username' => '[^/]+'])]
    public function config(Request $request, #[MapEntity(mapping: ['username' => 'username'])] User $person, EntityManagerInterface $em): Response
    {
        $this->denyUnlessSelfOrAdmin($person);

        $form = $this->messageOrderForm($person);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $person->setMessageOrder($form->get('message_order')->getData());
            $em->flush();
            $this->addFlash('success', 'Votre configuration a été enregistrée.');

            return $this->redirectToRoute('people_config', ['username' => $person->getUsername()]);
        }

        return $this->renderConfig($person, $form, $this->passwordForm($person), $this->avatarForm($person));
    }

    /**
     * Change your own password (administrators have no say over somebody else's: `app:user:reset-password`). The current
     * password is required, the new one is hashed with the native hasher (which also retires a legacy SHA-512 hash).
     * Every other session and remember-me cookie dies with the old hash, so this device is logged in again on a fresh session.
     */
    #[Route('/gens/{username}/mot-de-passe', name: 'people_password', requirements: ['username' => '[^/]+'], methods: ['POST'])]
    public function password(
        Request $request,
        #[MapEntity(mapping: ['username' => 'username'])] User $person,
        #[CurrentUser] User $me,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface $em,
        Security $security,
    ): Response {
        if ($person !== $me) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->passwordForm($person);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $me->setPassword($hasher->hashPassword($me, $form->get('plainPassword')->getData()))->setSalt(null);
            $em->flush();
            $security->login($me, 'form_login', 'main', [new RememberMeBadge()]);
            $this->addFlash('success', 'Votre mot de passe a été modifié.');

            return $this->redirectToRoute('people_config', ['username' => $person->getUsername()]);
        }

        return $this->renderConfig($person, $this->messageOrderForm($person), $form, $this->avatarForm($person), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Change or remove your own avatar (nobody else's, administrators included). The image goes through the photo
     * pipeline; the previous avatar is deleted unless something else still uses it. A refused file shows the config page again (422).
     */
    #[Route('/gens/{username}/avatar', name: 'people_avatar', requirements: ['username' => '[^/]+'], methods: ['POST'])]
    public function avatar(
        Request $request,
        #[MapEntity(mapping: ['username' => 'username'])] User $person,
        #[CurrentUser] User $me,
        AvatarManager $avatars,
    ): Response {
        if ($person !== $me) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->avatarForm($me);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->has('remove') && $form->get('remove')->isClicked()) {
                $avatars->remove($me);
                $this->addFlash('success', 'Votre avatar a été retiré.');

                return $this->redirectToRoute('people_config', ['username' => $me->getUsername()]);
            }

            $file = $form->get('file')->getData();
            if (null === $file) {
                $form->get('file')->addError(new FormError('Choisissez une image.'));
            } else {
                try {
                    $avatars->change($me, $file);
                    $this->addFlash('success', 'Votre avatar a été modifié.');

                    return $this->redirectToRoute('people_config', ['username' => $me->getUsername()]);
                } catch (\InvalidArgumentException $e) {
                    $form->get('file')->addError(new FormError($e->getMessage()));
                }
            }
        }

        return $this->renderConfig($person, $this->messageOrderForm($person), $this->passwordForm($person), $form, Response::HTTP_UNPROCESSABLE_ENTITY);
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

    private function messageOrderForm(User $person): FormInterface
    {
        return $this->createFormBuilder(['message_order' => $person->getMessageOrder()])
            ->add('message_order', ChoiceType::class, [
                'label' => 'Ordre des messages',
                'choices' => ['Plus récent en dernier' => 'asc', 'Plus récent en premier' => 'desc'],
                'expanded' => true,
            ])
            ->getForm();
    }

    private function passwordForm(User $person): ?FormInterface
    {
        if ($person !== $this->getUser()) {
            return null;
        }

        return $this->createForm(ChangePasswordType::class, null, [
            'current_password' => true,
            'action' => $this->generateUrl('people_password', ['username' => $person->getUsername()]),
        ]);
    }

    private function avatarForm(User $person): ?FormInterface
    {
        if ($person !== $this->getUser()) {
            return null;
        }

        return $this->createForm(AvatarType::class, null, [
            'has_avatar' => null !== $person->getAvatar(),
            'action' => $this->generateUrl('people_avatar', ['username' => $person->getUsername()]),
        ]);
    }

    private function renderConfig(User $person, FormInterface $form, ?FormInterface $passwordForm, ?FormInterface $avatarForm, int $status = Response::HTTP_OK): Response
    {
        return $this->render('people/config.html.twig', ['person' => $person, 'form' => $form, 'passwordForm' => $passwordForm, 'avatarForm' => $avatarForm], new Response(status: $status));
    }

    private function denyUnlessSelfOrAdmin(User $person): void
    {
        if ($person !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
    }
}
