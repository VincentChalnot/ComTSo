<?php

namespace App\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Keeps the forum administrable from its own admin panel: nobody locks themselves out, and the last active administrator
 * can neither be disabled, demoted nor removed (by anybody). The CLI (`app:user:promote`, `app:user:create`) stays the way
 * back if it ever happens through SQL.
 */
class AdminAccountGuard
{
    public const OWN_ACCOUNT = 'Vous ne pouvez pas désactiver, rétrograder ni supprimer votre propre compte.';
    public const LAST_ADMIN = 'C’est le dernier compte administrateur actif : il ne peut être ni désactivé, ni rétrogradé, ni supprimé.';

    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $users,
    ) {
    }

    public function isCurrentUser(User $user): bool
    {
        $current = $this->security->getUser();

        return $current instanceof User && null !== $user->getId() && $current->getId() === $user->getId();
    }

    /**
     * Why an administrator account that is active now must keep its rights and its existence, null when nothing protects it
     * (a member, or an account that is already disabled, is free to change).
     *
     * @param bool $persistedAdminState whether the account IS an active administrator in the database (not what a form just set)
     */
    public function protection(User $user, bool $persistedAdminState): ?string
    {
        if (!$persistedAdminState) {
            return null;
        }
        if ($this->isCurrentUser($user)) {
            return self::OWN_ACCOUNT;
        }

        return $this->users->countEnabledAdmins() <= 1 ? self::LAST_ADMIN : null;
    }

    /** {@see protection()} for an account as it is stored right now (removal checks). */
    public function removalProtection(User $user): ?string
    {
        return $this->protection($user, $user->isEnabled() && \in_array('ROLE_ADMIN', $user->getRoles(), true));
    }
}
