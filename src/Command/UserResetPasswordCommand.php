<?php

namespace App\Command;

use App\Form\ChangePasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** The fallback of "Mot de passe oublié ?" (e-mail not delivered, member without a real address): the owner sets a password by hand. */
#[AsCommand(name: 'app:user:reset-password', description: 'Sets a new password for a member (a random one-time password is printed unless --password is given)')]
class UserResetPasswordCommand
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Pseudo (login)')] string $username,
        #[Option(description: 'The new password (visible in the shell history): omit it to get a random one')] ?string $password = null,
    ): int {
        $user = $this->users->findOneBy(['username' => $username]);
        if (!$user) {
            $io->error(sprintf('No member named "%s".', $username));

            return Command::FAILURE;
        }

        $generated = null === $password;
        if ($generated) {
            $password = '';
            for ($i = 0; $i < 16; ++$i) {
                $password .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }
        } elseif (mb_strlen($password) < ChangePasswordType::MIN_LENGTH) {
            $io->error(sprintf('The password must have at least %d characters.', ChangePasswordType::MIN_LENGTH));

            return Command::INVALID;
        }

        // Replacing the hash also signs the member out everywhere (sessions, remember-me cookies) and retires any reset link already sent.
        $user->setPassword($this->hasher->hashPassword($user, $password))->setSalt(null);
        $this->em->flush();

        $io->success(sprintf('Password of %s changed.', $user->getUsername()));
        if ($generated) {
            $io->writeln(sprintf('One-time password: <info>%s</info>', $password));
            $io->writeln('Hand it over privately and ask the member to change it in Configuration > Mot de passe.');
        }
        if (!$user->isEnabled()) {
            $io->warning('This account is disabled: the member still cannot log in.');
        }

        return Command::SUCCESS;
    }
}
