<?php

namespace App\Form;

use App\Entity\Forum;
use App\Entity\Topic;
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
        $resolver->setDefaults(['data_class' => Topic::class, 'allow_move' => false]);
        $resolver->setAllowedTypes('allow_move', 'bool');
    }
}
