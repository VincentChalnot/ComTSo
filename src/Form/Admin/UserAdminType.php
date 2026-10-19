<?php

namespace App\Form\Admin;

use App\Admin\AdminAccountGuard;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Admin form of a member: whether the account can log in and whether it administers the forum. Nothing else is editable
 * here (members keep their profile, e-mail address and password to themselves; there is no e-mail field on purpose).
 * A change that would disable or demote the current administrator, or the last active one, is refused (AdminAccountGuard).
 */
class UserAdminType extends AbstractType
{
    private const MEMBER = 'member';
    private const ADMIN = 'admin';

    public function __construct(private readonly AdminAccountGuard $guard)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'admin.user.field.enabled',
                'help' => 'admin.user.field.enabled_help',
                'required' => false,
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'admin.user.field.role',
                'mapped' => false,
                'expanded' => true,
                'choices' => ['admin.user.role.member' => self::MEMBER, 'admin.user.role.admin' => self::ADMIN],
                // A submission without a role must not silently mean "member".
                'constraints' => [new NotBlank(message: 'Choisissez un rôle.')],
            ]);

        // State of the account when the form was built: what the guard compares the submission with.
        $initial = ['enabled' => true, 'roles' => []];

        // POST_SET_DATA: the data mapper resets unmapped children while it maps the entity, so the role is set once it is done.
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use (&$initial): void {
            $user = $event->getData();
            if (!$user instanceof User) {
                return;
            }
            $initial = ['enabled' => $user->isEnabled(), 'roles' => $user->getRoles()];
            $event->getForm()->get('role')->setData(\in_array('ROLE_ADMIN', $user->getRoles(), true) ? self::ADMIN : self::MEMBER);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) use (&$initial): void {
            $user = $event->getData();
            $form = $event->getForm();
            if (!$user instanceof User) {
                return;
            }

            // Other roles, if any ever exist, are kept: only ROLE_ADMIN is decided here.
            $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', 'ROLE_ADMIN']));
            if (self::ADMIN === $form->get('role')->getData()) {
                $roles[] = 'ROLE_ADMIN';
            }
            $user->setRoles($roles);

            $wasActiveAdmin = $initial['enabled'] && \in_array('ROLE_ADMIN', $initial['roles'], true);
            $staysActiveAdmin = $user->isEnabled() && \in_array('ROLE_ADMIN', $user->getRoles(), true);
            if ($wasActiveAdmin && !$staysActiveAdmin && null !== $reason = $this->guard->protection($user, true)) {
                $form->addError(new FormError($reason));
                // The managed entity must not keep the refused change in memory.
                $user->setEnabled($initial['enabled']);
                $user->setRoles(array_values(array_diff($initial['roles'], ['ROLE_USER'])));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
