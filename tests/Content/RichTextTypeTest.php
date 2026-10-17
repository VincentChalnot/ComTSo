<?php

namespace App\Tests\Content;

use App\Form\Type\RichTextType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;

class RichTextTypeTest extends KernelTestCase
{
    private function form(array $fieldOptions = [], mixed $data = null): FormInterface
    {
        self::bootKernel();

        return static::getContainer()->get(FormFactoryInterface::class)
            ->createNamedBuilder('post', FormType::class, ['content' => $data], ['csrf_protection' => false])
            ->add('content', RichTextType::class, $fieldOptions)
            ->getForm();
    }

    public function testSubmittedHtmlIsSanitizedBeforeItReachesTheData(): void
    {
        $form = $this->form();
        $form->submit(['content' => '<p onclick="steal()">Salut <strong>toi</strong></p><script>alert(1)</script><iframe src="https://evil.example/"></iframe>']);

        self::assertTrue($form->isSynchronized());
        self::assertSame('<p>Salut <strong>toi</strong></p>', $form->getData()['content']);
        // A form re-rendered after a validation error shows the sanitized value too, never the raw submission.
        self::assertSame('<p>Salut <strong>toi</strong></p>', $form->get('content')->getViewData());
    }

    public function testEditorOutputWithAYoutubePlayerSurvives(): void
    {
        $form = $this->form();
        $form->submit(['content' => '<p>Regardez :</p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" allowfullscreen></iframe><p><img src="/smilies/icon_lol.gif" alt=":lol:"></p>']);

        $html = $form->getData()['content'];
        self::assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"', $html);
        self::assertStringContainsString('<img src="/smilies/icon_lol.gif" alt=":lol:" />', $html);
    }

    public function testTheTrailingEmptyParagraphAfterAPlayerIsNotStored(): void
    {
        $form = $this->form();
        $form->submit(['content' => '<p>Tiens :</p><iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"></iframe><p></p><p><br></p>']);

        self::assertStringEndsWith('</iframe>', $form->getData()['content']);
        self::assertStringStartsWith('<p>Tiens :</p>', $form->getData()['content']);
    }

    public function testPlainTextFromTheNoJavascriptFallbackBecomesParagraphs(): void
    {
        $form = $this->form();
        $form->submit(['content' => "Salut <3 & tout\nle monde\r\n\r\nDeuxième paragraphe"]);

        self::assertSame('<p>Salut &lt;3 &amp; tout<br />le monde</p><p>Deuxième paragraphe</p>', $form->getData()['content']);
    }

    public function testTextTypedAsMarkupInThePlainFallbackIsTreatedAsMarkup(): void
    {
        $form = $this->form();
        $form->submit(['content' => 'du <b>gras</b> <script>alert(1)</script>']);

        self::assertSame('du <b>gras</b> ', $form->getData()['content']);
    }

    public function testAnEmptyEditorSubmitsAnEmptyValue(): void
    {
        foreach (['', '   ', "<p></p>", '<p><br></p>', '<p>&nbsp;</p><p> </p>', '<script>alert(1)</script>'] as $submitted) {
            $form = $this->form();
            $form->submit(['content' => $submitted]);

            self::assertSame('', (string) $form->getData()['content'], var_export($submitted, true));
        }
    }

    public function testMediaOnlyContentIsNotEmpty(): void
    {
        $form = $this->form();
        $form->submit(['content' => '<p><img src="/smilies/icon_lol.gif" alt=":lol:"></p>']);

        self::assertNotSame('', $form->getData()['content']);
    }

    public function testASubmittedArrayDoesNotCrashAndIsRejected(): void
    {
        $form = $this->form();
        $form->submit(['content' => ['<script>alert(1)</script>']]);

        self::assertFalse($form->isValid());
    }

    public function testMinHeightMustBeAnIntegerOrAString(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->form(['min_height' => 1.5])->createView();
    }

    public function testSeveralEditorsOnOnePageEachGetTheirOwnMountPointAndFallbackTextarea(): void
    {
        self::bootKernel();
        $form = static::getContainer()->get(FormFactoryInterface::class)
            ->createNamedBuilder('post', FormType::class, ['intro' => '<p>Déjà <strong>là</strong></p>', 'signature' => '</textarea><script>alert(1)</script>'], ['csrf_protection' => false])
            ->add('intro', RichTextType::class, ['label' => false, 'attr' => ['placeholder' => 'Écrivez ici…']])
            ->add('signature', RichTextType::class, ['label' => 'Signature', 'compact' => true, 'min_height' => 80, 'required' => false])
            ->getForm();

        $html = static::getContainer()->get('twig')
            ->createTemplate('{{ form_start(form) }}{{ form_row(form.intro) }}{{ form_row(form.signature) }}{{ form_end(form, {render_rest: false}) }}')
            ->render(['form' => $form->createView()]);
        $page = new Crawler($html);

        $mounts = $page->filter('[data-vue-component="RichEditor"]');
        self::assertCount(2, $mounts);
        self::assertCount(0, $page->filter('script'), 'stored values are escaped, they cannot break out of the markup');

        $props = [];
        foreach ($mounts as $mount) {
            $props[] = json_decode($mount->getAttribute('data-props'), true, flags: \JSON_THROW_ON_ERROR);
        }
        self::assertSame(['post[intro]', 'post[signature]'], array_column($props, 'name'));
        self::assertSame(['post_intro', 'post_signature'], array_column($props, 'id'));
        self::assertSame('<p>Déjà <strong>là</strong></p>', $props[0]['value']);
        self::assertSame('</textarea><script>alert(1)</script>', $props[1]['value']);
        self::assertSame('Écrivez ici…', $props[0]['placeholder']);
        self::assertFalse($props[0]['compact']);
        self::assertTrue($props[1]['compact']);
        self::assertSame(80, $props[1]['minHeight']);
        self::assertTrue($props[0]['required']);
        self::assertFalse($props[1]['required']);

        // No-JS fallback: each mount point holds a real textarea with the same name.
        self::assertSame('post[intro]', $page->filter('#post_intro')->attr('name'));
        self::assertSame('textarea', $page->filter('#post_intro')->nodeName());
        self::assertSame('post[signature]', $page->filter('#post_signature')->attr('name'));
        self::assertSame('</textarea><script>alert(1)</script>', $page->filter('#post_signature')->text());

        // `label: false` renders no label, a normal label stays.
        self::assertCount(0, $page->filter('label[for="post_intro"]'));
        self::assertCount(1, $page->filter('label[for="post_signature"]'));
    }
}
