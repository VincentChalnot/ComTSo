<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Not mapped to an entity: new password twice, and (`current_password` option, for a logged-in member changing
 * their own password) the current one. The reset-by-e-mail form has no current password: the signed link is the proof.
 */
class ChangePasswordType extends AbstractType
{
    public const MIN_LENGTH = 10;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['current_password']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Mot de passe actuel',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new UserPassword(message: 'Le mot de passe actuel est incorrect.')],
            ]);
        }

        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'first_options' => ['label' => 'Nouveau mot de passe', 'help' => sprintf('Au moins %d caractères.', self::MIN_LENGTH), 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Confirmer le nouveau mot de passe', 'attr' => ['autocomplete' => 'new-password']],
            'invalid_message' => 'Les deux mots de passe ne sont pas identiques.',
            'constraints' => [
                new NotBlank(message: 'Choisissez un mot de passe.'),
                new Length(min: self::MIN_LENGTH, max: 4096, minMessage: 'Le mot de passe doit comporter au moins {{ limit }} caractères.', maxMessage: 'Ce mot de passe est trop long.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['current_password' => false]);
        $resolver->setAllowedTypes('current_password', 'bool');
    }
}
