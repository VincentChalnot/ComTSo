<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Mot de passe oublié ?": signed, expiring, single-use links sent by e-mail.
 *
 * Stateless on purpose (no table): the link is `/mot-de-passe/reinitialiser?u=<id>&k=<key>` signed with {@see UriSigner}
 * (HMAC of the path and query with the application secret) and valid for {@see LIFETIME}. `k` is an HMAC of the member's
 * current password hash and e-mail, so the link dies as soon as the password changes (the reset itself, a password
 * change, even the transparent upgrade of a legacy hash at login) or the address is edited: single use without storage.
 */
#[WithMonologChannel('audit')]
final class PasswordReset
{
    public const LIFETIME = 'PT1H';
    public const ROUTE = 'app_reset_password';

    public function __construct(
        private readonly UriSigner $signer,
        private readonly UrlGeneratorInterface $urls,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%app.mailer_from%')]
        private readonly string $from,
        #[Autowire('%app.public_url%')]
        private readonly ?string $publicUrl,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Only enabled members with a real address get mail: imported placeholders (`@legacy.invalid`, `.invalid` is reserved by RFC 2606) never do. */
    public function canReceiveMail(User $user): bool
    {
        $email = trim((string) $user->getEmail());
        $at = strrpos($email, '@');
        if (!$user->isEnabled() || false === $at || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return !str_ends_with(strtolower(substr($email, $at + 1)), '.invalid');
    }

    /**
     * Absolute link to the reset form. The host comes from the configured public URL (DEFAULT_URI) rather than from the
     * request, so a forged Host header cannot point a victim's link to another site; the signature covers path and query only.
     */
    public function link(User $user, Request $request): string
    {
        $path = $this->urls->generate(self::ROUTE, ['u' => $user->getId(), 'k' => $this->key($user)], UrlGeneratorInterface::ABSOLUTE_PATH);

        return rtrim($this->publicUrl ?: $request->getSchemeAndHttpHost(), '/').$this->signer->sign($path, new \DateInterval(self::LIFETIME));
    }

    /** The member a reset request is for, or null when the link is unsigned, tampered, expired or already used. */
    public function userFor(Request $request): ?User
    {
        $id = $request->query->get('u');
        $key = $request->query->get('k');
        if (!\is_string($id) || !ctype_digit($id) || !\is_string($key) || !$this->signer->check($request->getRequestUri())) {
            return null;
        }

        $user = $this->users->find((int) $id);
        if (!$user || !$this->canReceiveMail($user) || !hash_equals($this->key($user), $key)) {
            return null;
        }

        return $user;
    }

    /**
     * Hands the mail to the transport. Runs after the response (see AfterResponseSubscriber), so a failure can only be reported
     * in the logs: member id and recipient DOMAIN only, never the address, and the SMTP user name is masked.
     */
    public function send(User $user, string $link): void
    {
        $email = (string) $user->getEmail();
        $context = ['user_id' => $user->getId(), 'recipient_domain' => strtolower(substr($email, (int) strrpos($email, '@') + 1))];

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(Address::create($this->from))
                ->to(new Address($email, $user->getUsername()))
                ->subject('ComTSo : réinitialisation de votre mot de passe')
                ->textTemplate('email/password_reset.txt.twig')
                ->htmlTemplate('email/password_reset.html.twig')
                ->context(['username' => $user->getUsername(), 'link' => $link, 'lifetime_minutes' => 60]));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Password reset e-mail NOT sent', $context + [
                'exception' => $e::class,
                'reason' => preg_replace('/username "[^"]*"/', 'username "***"', substr($e->getMessage(), 0, 300)),
            ]);

            return;
        }

        $this->logger->notice('Password reset e-mail handed to the mail transport', $context);
    }

    /** Server-side audit line explaining why a request produced no mail (the visitor always gets the same neutral answer). */
    public function skipped(string $reason, ?User $user = null): void
    {
        $this->logger->notice('Password reset requested, no e-mail sent: '.$reason, null === $user ? [] : ['user_id' => $user->getId()]);
    }

    private function key(User $user): string
    {
        return substr(hash_hmac('sha256', $user->getId().'|'.$user->getEmail().'|'.$user->getPassword(), $this->secret), 0, 32);
    }
}
