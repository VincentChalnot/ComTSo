<?php

namespace ComTSo\ForumBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class TopicType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
                ->add('title')
                ->add('forum')
                ->add('author')
                ->add('content')
                ->add('photos', 'collection', [
                    'type' => new PhotoTopicType(),
                    //'allow_add' => true,
                    'allow_delete' => true,
                    //'widget_add_btn' => false,
                    'options' => [
                        'horizontal_input_wrapper_class' => 'col-lg-10',
                        'label_render' => false,
                        'widget_remove_btn' => false,
                    ],
                ]);
    }

    /**
     * @param OptionsResolverInterface $resolver
     */
    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'ComTSo\ForumBundle\Entity\Topic'
        ));
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'comtso_topic';
    }

}
