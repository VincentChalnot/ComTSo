<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** Registration is closed: accounts are created (or promoted) from the command line by the forum's owner. */
#[AsCommand(name: 'app:user:create', description: 'Creates a member, or updates the password/roles of an existing one')]
class UserCreateCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Pseudo (login)')] string $username,
        #[Argument(description: 'Password')] string $password,
        #[Option(description: 'Email address')] ?string $email = null,
        #[Option(description: 'Grant ROLE_ADMIN')] bool $admin = false,
    ): int {
        $user = $this->users->findOneBy(['username' => $username]);
        $created = null === $user;
        if ($created) {
            $user = (new User())->setUsername($username)->setEmail($email ?? mb_strtolower($username).'@example.invalid');
        } elseif (null !== $email) {
            $user->setEmail($email);
        }

        $user->setPassword($this->hasher->hashPassword($user, $password))->setSalt(null)->setEnabled(true);
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('%s %s%s.', $created ? 'Created' : 'Updated', $username, $admin ? ' (admin)' : ''));

        return Command::SUCCESS;
    }
}
