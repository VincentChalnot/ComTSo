<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:promote', description: 'Grants (or with --revoke, removes) ROLE_ADMIN: administrators can moderate every message, topic and photo')]
class UserPromoteCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Pseudo (login)')] string $username,
        #[Option(description: 'Remove the administrator role instead')] bool $revoke = false,
    ): int {
        $user = $this->users->findOneBy(['username' => $username]);
        if (!$user) {
            $io->error(sprintf('No member named "%s".', $username));

            return Command::FAILURE;
        }

        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', 'ROLE_ADMIN']));
        $user->setRoles($revoke ? $roles : [...$roles, 'ROLE_ADMIN']);
        $this->em->flush();

        $io->success(sprintf('%s is %s an administrator.', $user->getUsername(), $revoke ? 'no longer' : 'now'));

        return Command::SUCCESS;
    }
}
