<?php

namespace App\Form\Admin;

use App\Entity\Forum;
use App\Form\Type\RichTextType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * Admin form of a forum. `ctso_forum.id` is the SLUG used in every URL (`/forum/{id}/…`): it is derived from the title when the
 * forum is created (Forum::setTitle()) and never changes afterwards, so renaming a forum keeps its links alive.
 */
class ForumAdminType extends AbstractType
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'admin.forum.field.title',
                'constraints' => [new NotBlank(message: 'Le titre est obligatoire.'), new Length(max: 128)],
            ])
            ->add('order', IntegerType::class, [
                'label' => 'admin.forum.field.order',
                'help' => 'admin.forum.field.order_help',
                'required' => false,
                'constraints' => [new PositiveOrZero()],
            ])
            ->add('content', RichTextType::class, [
                'label' => 'admin.forum.field.content',
                'help' => 'admin.forum.field.content_help',
                'required' => false,
            ]);

        // A new forum must not collide with an existing slug (the primary key is assigned, not generated).
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $forum = $event->getData();
            $form = $event->getForm();
            if (!$forum instanceof Forum || !$form->isValid() || !$this->isNew($forum)) {
                return;
            }
            if (null !== $this->em->find(Forum::class, $forum->getId())) {
                $form->get('title')->addError(new FormError(sprintf('Une adresse « %s » existe déjà : choisissez un autre titre.', $forum->getId())));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Forum::class]);
    }

    private function isNew(Forum $forum): bool
    {
        return !$this->em->contains($forum);
    }
}
