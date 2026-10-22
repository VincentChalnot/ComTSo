<?php

namespace App\Form;

use App\Entity\Comment;
use App\Form\Type\RichTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The compact reply of the home page: rich text only, no photos (those stay on the topic page, see CommentType). One
 * form per topic card, so the caller names each instance after its topic (`reply_<topicId>`): ids and CSRF tokens never collide.
 */
class QuickReplyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', RichTextType::class, [
            'label' => false,
            'compact' => true,
            'min_height' => '4rem',
            'attr' => ['placeholder' => 'Répondre à ce sujet…', 'aria-label' => 'Votre réponse'],
            'constraints' => [new NotBlank(message: 'Le message ne peut pas être vide.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Comment::class]);
    }
}
