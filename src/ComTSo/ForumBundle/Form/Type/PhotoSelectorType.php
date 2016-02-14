<?php

namespace ComTSo\ForumBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PhotoSelectorType extends AbstractType
{
    /**
     * @inheritdoc
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'class' => 'ComTSo\ForumBundle\Entity\Photo',
            'browsable' => true,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['browsable'] = $options['browsable'];
    }

    public function getParent()
    {
        return 'entity';
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'photo';
    }

}
