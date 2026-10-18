<?php

namespace App\Command;

use App\Entity\User;
use App\Forum\UserRemover;
use App\Repository\ChatMessageRepository;
use App\Repository\UserRepository;
use App\Security\PasswordReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Last step before opening the forum to its members, once the legacy data has been imported: nobody should find
 * 20 years of "new since your last visit" on the home page, nor a chat badge counting the whole history.
 * Run it once, then open the doors: it moves the pointers forward again at every run, so never on a live forum.
 * Safe to repeat (and with --dry-run to look first): pointers only ever move forward, a removed test account is just reported as gone.
 *
 * Run with XDEBUG_MODE=off and --no-debug (whole-table updates).
 */
#[AsCommand(name: 'app:cutover:prepare', description: 'Resets every member\'s "since your last visit" and chat read pointers to now; optionally deletes test accounts')]
class CutoverPrepareCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly ChatMessageRepository $chat,
        private readonly UserRemover $remover,
        private readonly PasswordReset $passwordReset,
    ) {
    }

    /** @param list<string> $deleteUser */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Report what would change, write nothing')] bool $dryRun = false,
        #[Option(description: 'Pseudo of a (test) account to delete with everything it wrote; repeatable', name: 'delete-user')] array $deleteUser = [],
    ): int {
        $io->title('Cutover preparation'.($dryRun ? ' (dry run: nothing is written)' : ''));

        foreach (array_unique($deleteUser) as $username) {
            $user = $this->users->findOneBy(['username' => $username]);
            if (!$user) {
                $io->writeln(sprintf('  <comment>%s</comment>: no such member (already deleted?)', $username));
                continue;
            }
            $impact = $dryRun ? $this->remover->impact($user) : $this->remover->remove($user);
            $io->writeln(sprintf('  <info>%s</info> %s: %d topic(s), %d message(s), %d private message(s), %d chat message(s)', $username, $dryRun ? 'would be deleted' : 'deleted', $impact['topics'], $impact['comments'], $impact['privateMessages'], $impact['chatMessages']));
        }

        $now = new \DateTime();
        $latestChatId = $this->chat->latestId();
        $needVisit = (int) $this->em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.previousLogin IS NULL OR u.previousLogin < :now')->setParameter('now', $now)->getSingleScalarResult();
        $needChat = (int) $this->em->createQuery('SELECT COUNT(u.id) FROM '.User::class.' u WHERE u.chatLastReadId < :latest')->setParameter('latest', $latestChatId)->getSingleScalarResult();
        if (!$dryRun) {
            $this->em->createQuery('UPDATE '.User::class.' u SET u.previousLogin = :now WHERE u.previousLogin IS NULL OR u.previousLogin < :now')->setParameter('now', $now)->execute();
            $this->em->createQuery('UPDATE '.User::class.' u SET u.chatLastReadId = :latest WHERE u.chatLastReadId < :latest')->setParameter('latest', $latestChatId)->execute();
        }
        $io->writeln(sprintf('  previous_login %s %d member(s) (now: %s)', $dryRun ? 'would be set for' : 'set for', $needVisit, $now->format('Y-m-d H:i:s')));
        $io->writeln(sprintf('  chat_last_read_id %s %d member(s) (latest chat message: #%d)', $dryRun ? 'would be set for' : 'set for', $needChat, $latestChatId));

        $io->section('Accounts to check');
        $all = $this->users->findBy([], ['username' => 'ASC']);
        $admins = $disabled = $noMail = [];
        foreach ($all as $user) {
            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $admins[] = $user->getUsername().($user->isEnabled() ? '' : ' (disabled!)');
            }
            if (!$user->isEnabled()) {
                $disabled[] = $user->getUsername();
            } elseif (!$this->passwordReset->canReceiveMail($user)) {
                $noMail[] = $user->getUsername();
            }
        }
        $io->writeln(sprintf('  %d account(s), %d enabled', \count($all), \count($all) - \count($disabled)));
        $io->writeln(sprintf('  ROLE_ADMIN (%d): %s', \count($admins), $admins ? implode(', ', $admins) : '-'));
        $io->writeln(sprintf('  Disabled accounts (cannot log in): %d%s', \count($disabled), $io->isVerbose() && $disabled ? ': '.implode(', ', $disabled) : ' (-v lists them)'));
        $io->writeln(sprintf('  Enabled accounts without a usable e-mail address, so no reset link by mail (%d): %s', \count($noMail), $noMail ? implode(', ', $noMail) : '-'));

        $dryRun ? $io->note('Dry run: nothing was written.') : $io->success('Cutover pointers are up to date.');

        return Command::SUCCESS;
    }
}
