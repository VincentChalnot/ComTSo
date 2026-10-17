<?php

namespace App\Form\Type;

use App\Content\ContentSanitizer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * HTML body editor field. Whatever the browser submits is sanitized before the form even maps it (so the entity
 * and a re-rendered invalid form only ever see safe HTML, see ContentSanitizer). The widget is progressively
 * enhanced into the Vue/Tiptap editor by the `rich_text_widget` form theme block; without JavaScript it stays a
 * plain textarea whose text is turned into paragraphs.
 *
 * Options: `compact` (small editor for signatures and short messages) and `min_height` (CSS length or pixels).
 * A placeholder is given the usual way: `attr: ['placeholder' => '…']`.
 */
class RichTextType extends AbstractType
{
    /** Content that only holds these tags is not empty although it has no text. */
    private const MEDIA_TAGS = ['img', 'iframe', 'hr'];

    public function __construct(private readonly ContentSanitizer $sanitizer)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $submitted = $event->getData();
            if (\is_string($submitted)) {
                $event->setData($this->normalize($submitted));
            }
        });
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['compact'] = $options['compact'];
        $view->vars['min_height'] = $options['min_height'];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['compact' => false, 'min_height' => null]);
        $resolver->setAllowedTypes('compact', 'bool');
        $resolver->setAllowedTypes('min_height', ['null', 'int', 'string']);
        $resolver->setNormalizer('min_height', static fn (Options $options, int|string|null $value): int|string|null => '' === $value ? null : $value);
    }

    public function getParent(): string
    {
        return TextareaType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'rich_text';
    }

    private function normalize(string $submitted): string
    {
        if ('' === trim($submitted)) {
            return '';
        }

        // The no-JavaScript fallback is a plain textarea: text without any tag becomes paragraphs.
        if (!preg_match('~</?[a-z][^>]*>~i', $submitted)) {
            $paragraphs = preg_split('~(?:\R[ \t]*){2,}~', trim($submitted)) ?: [];
            $submitted = implode('', array_map(
                static fn (string $text): string => '<p>'.preg_replace('~\R~', '<br>', htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE)).'</p>',
                $paragraphs,
            ));
        }

        // The editor keeps an empty paragraph after a video player to let the author continue below it.
        $html = preg_replace('~(?:<p>(?:<br />)*</p>\s*)+$~D', '', $this->sanitizer->sanitize($submitted)) ?? '';

        return $this->hasContent($html) ? $html : '';
    }

    /** A body made only of empty paragraphs/line breaks is empty; images, videos and rules count as content. */
    private function hasContent(string $html): bool
    {
        $text = strip_tags($html, self::MEDIA_TAGS);
        $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5);

        return '' !== trim($text, " \t\n\r\0\x0B\u{A0}");
    }
}
