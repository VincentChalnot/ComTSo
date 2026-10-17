<?php

namespace App\Form;

use App\Entity\Comment;
use App\Entity\CommentPhoto;
use App\Form\Type\PhotoUploadType;
use App\Form\Type\RichTextType;
use App\Photo\PostPhotos;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A message: rich text plus photos. `photos` (unmapped, list of UploadedFile) adds photos; on an existing message
 * `remove_photos` (unmapped, list of CommentPhoto) offers to detach the attached ones. A message needs text or at least one photo.
 */
class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $comment = $options['data'] ?? null;
        $attached = $comment instanceof Comment && $comment->getId() ? $comment->getPhotos()->toArray() : [];

        $builder
            ->add('content', RichTextType::class, ['label' => false])
            ->add('photos', PhotoUploadType::class, ['existing_photos' => \count($attached)]);

        if ($attached) {
            $builder->add('remove_photos', ChoiceType::class, [
                'label' => 'Retirer des photos',
                'mapped' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'choices' => $attached,
                'choice_value' => static fn (?CommentPhoto $link): string => (string) $link?->getId(),
                'choice_label' => static fn (CommentPhoto $link): string => $link->getPhoto()->getTitle() ?: (string) $link->getPhoto()->getOriginalFilename(),
            ]);
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($attached): void {
            $form = $event->getForm();
            $new = \count($form->get('photos')->getData() ?? []);
            $removed = $form->has('remove_photos') ? \count($form->get('remove_photos')->getData() ?? []) : 0;
            $total = $new + \count($attached) - $removed;

            // A message made only of empty paragraphs is empty (images/videos/smilies count as content), unless it carries photos.
            if (0 === $total && '' === trim(strip_tags((string) $form->get('content')->getData(), '<img><iframe><video>'))) {
                $form->get('content')->addError(new FormError('Le message ne peut pas être vide.'));
            }
            if ($total > PostPhotos::MAX_PER_POST && $form->get('photos')->isValid()) {
                $form->get('photos')->addError(new FormError(sprintf('Un message ne peut pas contenir plus de %d photos.', PostPhotos::MAX_PER_POST)));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Comment::class,
            'post_max_size_message' => 'L\'envoi est trop volumineux : joignez moins de photos ou des photos plus légères.',
        ]);
    }
}
