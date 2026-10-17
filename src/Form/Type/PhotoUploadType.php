<?php

namespace App\Form\Type;

use App\Photo\PostPhotos;
use App\Service\PhotoUploader;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Image;

/**
 * "Add photos" field of the message and topic forms: a real multiple file input (the form works without JavaScript),
 * progressively enhanced by the Vue widget `AttachPhotos` (theme block in `templates/form/photo_upload.html.twig`).
 * The unmapped value is a list of UploadedFile that the controller hands to `App\Photo\PostPhotos`.
 *
 * Options: `max_files` (count limit, default PostPhotos::MAX_PER_POST), `max_size` (bytes per photo, default PhotoUploader::MAX_SIZE),
 * `existing_photos` (photos already attached, only used by the widget to show the remaining room).
 */
class PhotoUploadType extends FileType
{
    /** Browsers only offer these types in the picker; the server checks the real content type anyway. */
    public const MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        parent::buildView($view, $form, $options);
        $view->vars['max_files'] = $options['max_files'];
        $view->vars['max_size'] = $options['max_size'];
        $view->vars['existing_photos'] = $options['existing_photos'];
        $view->vars['accept'] = implode(',', self::MIME_TYPES);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);
        $resolver->setDefaults([
            'multiple' => true,
            'mapped' => false,
            'required' => false,
            'label' => 'Ajouter des photos',
            'max_files' => PostPhotos::MAX_PER_POST,
            'max_size' => PhotoUploader::MAX_SIZE,
            'existing_photos' => 0,
            'constraints' => static fn (Options $options): array => [
                new Count(max: $options['max_files'], maxMessage: 'Vous pouvez joindre {{ limit }} photos au maximum.'),
                new All([new Image(
                    maxSize: $options['max_size'],
                    mimeTypes: self::MIME_TYPES,
                    maxSizeMessage: 'La photo {{ name }} est trop lourde ({{ size }} {{ suffix }}) : {{ limit }} {{ suffix }} au maximum.',
                    mimeTypesMessage: '{{ name }} n\'est pas une image acceptée : seules les images JPEG, PNG, GIF et WebP sont acceptées.',
                    notFoundMessage: 'Le fichier n\'a pas pu être envoyé.',
                    notReadableMessage: 'Le fichier n\'a pas pu être lu.',
                    uploadIniSizeErrorMessage: 'Une photo dépasse la taille maximale autorisée ({{ limit }} {{ suffix }}).',
                    uploadFormSizeErrorMessage: 'Une photo dépasse la taille maximale autorisée.',
                    uploadErrorMessage: 'Le fichier n\'a pas pu être envoyé.',
                    sizeNotDetectedMessage: '{{ name }} n\'est pas une image valide.',
                    corruptedMessage: '{{ name }} n\'est pas une image valide.',
                )]),
            ],
        ]);
        $resolver->setAllowedTypes('max_files', 'int');
        $resolver->setAllowedTypes('max_size', 'int');
        $resolver->setAllowedTypes('existing_photos', 'int');
    }

    public function getBlockPrefix(): string
    {
        return 'photo_upload';
    }
}
