<?php

namespace ComTSo\ForumBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PhotoTopicType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
                ->add('photo', 'photo', [
                    'label' => false,
                    'horizontal_input_wrapper_class' => 'col-lg-10 col-sm-offset-1',
                    'browsable' => false,
                ])
                ->add('order', 'hidden');
    }

    /**
     * @inheritdoc
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => 'ComTSo\ForumBundle\Entity\PhotoTopic',
        ]);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'comtso_photo_topic';
    }

}
