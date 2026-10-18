<?php

namespace App\Controller;

use App\EventSubscriber\AfterResponseSubscriber;
use App\Form\ChangePasswordType;
use App\Repository\UserRepository;
use App\Security\PasswordReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** "Mot de passe oublié ?": the only way back in for a member who lost their password, without involving the owner. */
class PasswordResetController extends AbstractController
{
    #[Route('/mot-de-passe/oublie', name: 'app_forgot_password')]
    public function request(
        Request $request,
        UserRepository $users,
        PasswordReset $reset,
        RateLimiterFactoryInterface $passwordResetIpLimiter,
        RateLimiterFactoryInterface $passwordResetAccountLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('people_config', ['username' => $this->getUser()->getUserIdentifier()]);
        }

        $form = $this->createFormBuilder()
            ->add('identifier', TextType::class, [
                'label' => 'Pseudo ou adresse e-mail',
                'constraints' => [new NotBlank(message: 'Indiquez votre pseudo ou votre adresse e-mail.'), new Length(max: 180)],
                'attr' => ['autofocus' => true, 'autocomplete' => 'username'],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Per client address: a visible error is fine, it says nothing about any account.
            if (!$passwordResetIpLimiter->create($request->getClientIp() ?? 'unknown')->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de demandes : réessayez dans un moment.'));

                return $this->render('security/forgot_password.html.twig', ['form' => $form], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $identifier = trim((string) $form->get('identifier')->getData());
            $user = $users->findOneByUsernameOrEmail($identifier);
            // Per account: silently drops the excess, so the answer never depends on whether the account exists.
            if ($user && $reset->canReceiveMail($user) && $passwordResetAccountLimiter->create((string) $user->getId())->consume()->isAccepted()) {
                $link = $reset->link($user, $request);
                AfterResponseSubscriber::defer($request, static fn () => $reset->send($user, $link));
            }

            $this->addFlash('success', 'Si un compte correspond à cette demande, un e-mail contenant un lien de réinitialisation (valable une heure) vient de lui être envoyé.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig', ['form' => $form], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/mot-de-passe/reinitialiser', name: PasswordReset::ROUTE)]
    public function reset(Request $request, PasswordReset $reset, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        $user = $reset->userFor($request);
        if (!$user) {
            return $this->noStore($this->render('security/reset_password_invalid.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST)));
        }

        $form = $this->createForm(ChangePasswordType::class, null, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Replacing the hash is what retires the link (and every remember-me cookie issued before).
            $user->setPassword($hasher->hashPassword($user, $form->get('plainPassword')->getData()))->setSalt(null);
            $em->flush();
            $this->addFlash('success', 'Votre mot de passe a été modifié : vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->noStore($this->render('security/reset_password.html.twig', ['form' => $form, 'username' => $user->getUsername()], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /** The address carries a credential: neither cached nor leaked through the Referer header. */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
