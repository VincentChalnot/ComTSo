<?php

namespace App\Mail;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;

/**
 * The one way to build an e-mail of the forum: sender, subject, and BOTH parts (`templates/email/<name>.html.twig` and
 * `<name>.txt.twig`, see docs/design-system.md "Emails").
 *
 * The bodies are rendered HERE, immediately. Never leave that to the mailer: mails are usually sent from `kernel.terminate`
 * (AfterResponseSubscriber), after FrankenPHP has closed the response, and Twig can no longer open an output buffer then
 * (`ob_start(): Failed to create buffer`): the message left with EMPTY text and HTML parts. Only the delivery
 * ({@see \Symfony\Component\Mailer\MailerInterface::send()}) may be deferred.
 */
final class TemplatedMailFactory
{
    public function __construct(
        private readonly BodyRendererInterface $renderer,
        #[Autowire('%app.mailer_from%')]
        private readonly string $from,
        #[Autowire('%app.public_url%')]
        private readonly ?string $publicUrl,
    ) {
    }

    /**
     * @param string               $name    template base name: `password_reset` -> `email/password_reset.{html,txt}.twig`
     * @param array<string, mixed> $context variables of both templates; `site_url` (public address of the forum, no trailing slash,
     *                                      or null) is added, `app_name` is a Twig global
     */
    public function create(string $name, string $subject, Address $to, array $context = []): TemplatedEmail
    {
        $email = (new TemplatedEmail())
            ->from(Address::create($this->from))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate(sprintf('email/%s.html.twig', $name))
            ->textTemplate(sprintf('email/%s.txt.twig', $name))
            ->context($context + ['site_url' => $this->publicUrl ? rtrim($this->publicUrl, '/') : null]);

        $this->renderer->render($email);

        foreach (['text' => $email->getTextBody(), 'HTML' => $email->getHtmlBody()] as $part => $body) {
            if ('' === trim((string) $body)) {
                throw new \LogicException(sprintf('The "%s" e-mail has an empty %s part.', $name, $part));
            }
        }

        return $email;
    }
}
