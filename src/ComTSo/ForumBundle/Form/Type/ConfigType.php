<?php

namespace ComTSo\ForumBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class ConfigType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array                $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('bootstrap_theme', 'choice', [
            'choices' => [
                'default' => 'Default',
                'cerulean' => 'Cerulean',
                'cosmo' => 'Cosmo',
                'cyborg' => 'Cyborg',
                'darkly' => 'Darkly',
                'flatly' => 'Flatly',
                'journal' => 'Journal',
                'lumen' => 'Lumen',
                'paper' => 'Paper',
                'readable' => 'Readable',
                'sandstone' => 'Sandstone',
                'simplex' => 'Simplex',
                'slate' => 'Slate',
                'spacelab' => 'Spacelab',
                'superhero' => 'Superhero',
                'united' => 'United',
                'yeti' => 'Yeti',
                'geobootstrap' => 'GeoBootstrap',
            ],
            'help_label' => '<a href="http://bootswatch.com">bootswatch.com</a>',
        ])
        ->add('message_order', 'choice', [
            'label' => 'Ordre des messages',
            'choices' => ['ASC' => 'Plus récent en dernier', 'DESC' => 'Plus récent en premier']
        ]);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'comtso_config';
    }

}
