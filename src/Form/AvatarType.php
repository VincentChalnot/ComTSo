<?php

namespace App\Form;

use App\Form\Type\PhotoUploadType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Change or remove your own avatar (config page). Not mapped: the controller hands the file to `App\Photo\AvatarManager`.
 * The file goes through the same checks as every other upload ({@see PhotoUploadType::imageConstraint()}); `has_avatar`
 * adds the "remove" button.
 */
class AvatarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Nouvelle image',
                'help' => 'JPEG, PNG, GIF ou WebP, 10 Mo au maximum. Elle sera affichée en carré.',
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => implode(',', PhotoUploadType::MIME_TYPES)],
                'constraints' => [PhotoUploadType::imageConstraint()],
            ])
            ->add('upload', SubmitType::class, ['label' => 'Changer l\'avatar']);

        if ($options['has_avatar']) {
            $builder->add('remove', SubmitType::class, ['label' => 'Retirer mon avatar', 'attr' => ['class' => 'btn btn-danger']]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['has_avatar' => false]);
        $resolver->setAllowedTypes('has_avatar', 'bool');
    }
}
