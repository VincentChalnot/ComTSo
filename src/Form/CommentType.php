<?php

namespace App\Form;

use App\Entity\Comment;
use App\Form\Type\RichTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class CommentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', RichTextType::class, [
            'label' => false,
            'constraints' => [new Callback(static function (?string $html, ExecutionContextInterface $context): void {
                // A message made only of empty paragraphs is empty (images/videos/smilies count as content).
                if ('' === trim(strip_tags((string) $html, '<img><iframe><video>'))) {
                    $context->buildViolation('Le message ne peut pas être vide.')->addViolation();
                }
            })],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Comment::class]);
    }
}
