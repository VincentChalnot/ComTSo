<?php

namespace ComTSo\UserBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
                ->add('username')
                ->add('email')
                ->add('avatar', 'photo')
                ->add('name')
                ->add('surname')
                ->add('birthday', 'date', [
                    'widget' => 'single_text',
                ])
                ->add('address')
                ->add('phone')
                ->add('activities')
                ->add('signature')
                ->add('website');
    }

    /**
     * @inheritdoc
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => 'ComTSo\UserBundle\Entity\User'
        ]);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'comtso_user';
    }

}
