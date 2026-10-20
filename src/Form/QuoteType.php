<?php

namespace App\Form;

use App\Entity\Quote;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * A quote proposed by any member (shown at random in the aside): PLAIN TEXT only (escaped on display, never sanitized HTML),
 * trimmed by the form, same limits as the administrators' form. The submitting member is recorded by the controller.
 */
class QuoteType extends AbstractType
{
    public const MAX_CONTENT = 2000;
    public const MAX_AUTHOR = 128;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'label' => 'Citation',
                'help' => 'Texte brut, '.self::MAX_CONTENT.' caractères au maximum.',
                'attr' => ['rows' => 5, 'maxlength' => self::MAX_CONTENT],
                'constraints' => [
                    new NotBlank(message: 'Le texte de la citation est obligatoire.'),
                    new Length(max: self::MAX_CONTENT, maxMessage: 'La citation est trop longue ({{ limit }} caractères au maximum).'),
                ],
            ])
            ->add('originalAuthor', TextType::class, [
                'label' => 'Auteur',
                'help' => 'Qui l’a dite ou écrite.',
                'attr' => ['maxlength' => self::MAX_AUTHOR],
                'constraints' => [
                    new NotBlank(message: 'L’auteur de la citation est obligatoire.'),
                    new Length(max: self::MAX_AUTHOR, maxMessage: 'Le nom de l’auteur est trop long ({{ limit }} caractères au maximum).'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Quote::class]);
    }
}
