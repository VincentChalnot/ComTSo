<?php

namespace App\Form;

use App\Entity\Message;
use App\Form\Type\RichTextType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class MessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('content', RichTextType::class, [
            'label' => false,
            'constraints' => [new NotBlank(message: 'Le message ne peut pas être vide.')],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Message::class]);
    }
}
