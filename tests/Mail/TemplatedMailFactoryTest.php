<?php

namespace App\Tests\Mail;

use App\Mail\TemplatedMailFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;

/**
 * Production once sent password-reset mails with EMPTY text and HTML parts: the templates were rendered by the mailer after the
 * response was closed, when Twig cannot open an output buffer. The factory must hand over a message that is already rendered.
 */
class TemplatedMailFactoryTest extends KernelTestCase
{
    public function testTheMessageIsFullyRenderedBeforeAnySending(): void
    {
        $link = 'https://forum.test/mot-de-passe/reinitialiser?u=5&k=abc&_hash=x%3D';
        $email = self::getContainer()->get(TemplatedMailFactory::class)->create('password_reset', 'Sujet', new Address('marie@example.test', 'Marie'), [
            'username' => 'Marie <b>D</b>', 'link' => $link, 'lifetime_minutes' => 60,
        ]);

        self::assertTrue($email->isRendered(), 'rendered by the factory, nothing left for the mailer (which may run after the response)');
        self::assertNull($email->getHtmlTemplate());
        self::assertNull($email->getTextTemplate());

        $text = (string) $email->getTextBody();
        $html = (string) $email->getHtmlBody();
        self::assertStringContainsString('Marie <b>D</b>', $text, 'plain text is not HTML-escaped');
        self::assertStringContainsString($link, $text);
        self::assertStringContainsString('Marie &lt;b&gt;D&lt;/b&gt;', $html, 'user-supplied text is escaped in HTML');
        self::assertStringNotContainsString('<b>D</b>', $html);
        self::assertStringContainsString(htmlspecialchars($link, \ENT_QUOTES), $html);
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertSame('Sujet', $email->getSubject());
        self::assertSame('no-reply@localhost', $email->getFrom()[0]->getAddress());
    }

    public function testAMailWithoutBothPartsIsRefused(): void
    {
        $this->expectException(\Throwable::class);

        self::getContainer()->get(TemplatedMailFactory::class)->create('does_not_exist', 'Sujet', new Address('marie@example.test'));
    }
}
