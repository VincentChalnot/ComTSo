<?php

namespace App\Form;

use App\Entity\User;
use App\Form\Type\RichTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Prénom', 'required' => false])
            ->add('surname', TextType::class, ['label' => 'Nom', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->add('birthday', BirthdayType::class, ['label' => 'Date de naissance', 'required' => false, 'widget' => 'single_text'])
            ->add('phone', TextType::class, ['label' => 'Téléphone', 'required' => false])
            ->add('address', TextareaType::class, ['label' => 'Adresse', 'required' => false, 'attr' => ['rows' => 2]])
            ->add('website', UrlType::class, ['label' => 'Site web', 'required' => false, 'default_protocol' => 'https'])
            ->add('activities', TextareaType::class, ['label' => 'Activités', 'required' => false, 'attr' => ['rows' => 3]])
            ->add('signature', RichTextType::class, ['label' => 'Citation / signature', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
