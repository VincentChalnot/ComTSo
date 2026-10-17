<?php

namespace App\Form;

use App\Entity\Forum;
use App\Entity\Topic;
use App\Form\Type\PhotoUploadType;
use App\Form\Type\RichTextType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class TopicType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'constraints' => [new NotBlank(message: 'Le titre est obligatoire.'), new Length(max: 128)],
            ])
            ->add('content', RichTextType::class, [
                'label' => 'Description',
                'required' => false,
                'help' => 'Optionnelle : s\'affiche sous le titre du sujet.',
            ]);

        // New topics: photos are attached to the topic itself (App\Entity\PhotoTopic), no need to open the photo manager afterwards.
        if ($options['with_photos']) {
            $builder->add('photos', PhotoUploadType::class);
        }

        if ($options['allow_move']) {
            $builder->add('forum', EntityType::class, [
                'label' => 'Forum',
                'class' => Forum::class,
                'choice_label' => 'title',
                'query_builder' => static fn ($repository) => $repository->createQueryBuilder('f')->orderBy('f.order')->addOrderBy('f.title'),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Topic::class,
            'allow_move' => false,
            'with_photos' => false,
            'post_max_size_message' => 'L\'envoi est trop volumineux : joignez moins de photos ou des photos plus légères.',
        ]);
        $resolver->setAllowedTypes('allow_move', 'bool');
        $resolver->setAllowedTypes('with_photos', 'bool');
    }
}
