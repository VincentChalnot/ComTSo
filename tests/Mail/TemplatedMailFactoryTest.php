<?php

namespace App\Tests\Mail;

use App\Mail\TemplatedMailFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

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

    /**
     * The layout reuse contract of docs/design-system.md "Emails": a new mail (here a digest of topics) only extends the shared
     * base and calls the shared macros, in both parts. Written against a sample so that the next real digest cannot drift.
     */
    public function testADigestSampleReusesTheSharedLayoutAndMacrosInBothParts(): void
    {
        $twig = self::getContainer()->get(Environment::class);
        $twig->setLoader(new ChainLoader([new ArrayLoader([
            'email/digest_sample.html.twig' => <<<'TWIG'
                {% extends 'email/base.html.twig' %}
                {% import 'email/_macros.html.twig' as m %}
                {% block preheader %}{{ items|length }} sujets ont bougé.{% endblock %}
                {% block content %}{{ m.heading('Du nouveau') }}{{ m.items(items) }}{{ m.button('Tout voir', site_url) }}{% endblock %}
                TWIG,
            'email/digest_sample.txt.twig' => <<<'TWIG'
                {% extends 'email/base.txt.twig' %}
                {% import 'email/_macros.txt.twig' as m %}
                {% block content %}{{ m.heading('Du nouveau') }}{{ m.items(items) }}{{ m.button('Tout voir', site_url) }}{% endblock %}
                TWIG,
        ]), $twig->getLoader()]));
        $items = [
            ['title' => 'Vacances <i>d\'été</i>', 'url' => 'https://forum.test/forum/general/1', 'meta' => '3 nouveaux messages', 'excerpt' => 'On part le 12 & on revient le 30.'],
            ['title' => 'Photos de la rando', 'url' => 'https://forum.test/forum/general/2'],
        ];

        $email = self::getContainer()->get(TemplatedMailFactory::class)->create('digest_sample', 'Du nouveau', new Address('marie@example.test'), ['items' => $items]);

        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        $base = (string) self::getContainer()->get(TemplatedMailFactory::class)->create('password_reset', 'x', new Address('marie@example.test'), ['username' => 'm', 'link' => 'https://forum.test/x', 'lifetime_minutes' => 60])->getHtmlBody();

        // Both parts exist and carry the content.
        foreach ([$html, $text] as $part) {
            self::assertStringContainsString('Photos de la rando', $part);
            self::assertStringContainsString('https://forum.test/forum/general/1', $part, 'links are present as plain URLs');
        }
        // HTML: same base layout (doctype, footer sentence, site link) as every other mail; text is escaped, preheader filled.
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Vous recevez ce message parce que vous avez un compte sur', $html);
        self::assertStringContainsString('Vous recevez ce message parce que vous avez un compte sur', $base, 'the sentence comes from the shared base');
        self::assertStringContainsString('2 sujets ont bougé.', $html);
        self::assertStringContainsString('Vacances &lt;i&gt;d', $html);
        self::assertStringNotContainsString('<i>d', $html);
        self::assertStringContainsString('On part le 12 &amp; on revient', $html);
        // Text: no layout markup, no escaping.
        self::assertStringNotContainsString('<table', $text);
        self::assertStringNotContainsString('&amp;', $text);
        self::assertStringContainsString('On part le 12 & on revient le 30.', $text);
        self::assertStringContainsString('Vacances <i>d', $text, 'plain text keeps the characters as typed');
    }
}
