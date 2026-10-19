<?php

namespace App\Form\Admin;

use App\Entity\Quote;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Admin form of a quote (shown at random in the aside of every page, as PLAIN TEXT: no markup here). A new quote
 * remembers the administrator who added it.
 */
class QuoteAdminType extends AbstractType
{
    public function __construct(private readonly Security $security)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'label' => 'admin.quote.field.content',
                'attr' => ['rows' => 4],
                'constraints' => [new NotBlank(message: 'Le texte de la citation est obligatoire.'), new Length(max: 2000)],
            ])
            ->add('originalAuthor', TextType::class, [
                'label' => 'admin.quote.field.original_author',
                'help' => 'admin.quote.field.original_author_help',
                'constraints' => [new NotBlank(message: 'L’auteur de la citation est obligatoire.'), new Length(max: 128)],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $quote = $event->getData();
            $user = $this->security->getUser();
            if ($quote instanceof Quote && null === $quote->getId() && null === $quote->getAuthor() && $user instanceof User) {
                $quote->setAuthor($user);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Quote::class]);
    }
}
