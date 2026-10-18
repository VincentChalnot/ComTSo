<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserResetPasswordCommandTest extends AppWebTestCase
{
    private function execute(array $input): CommandTester
    {
        $tester = new CommandTester((new Application(static::$kernel))->find('app:user:reset-password'));
        $tester->execute($input, ['decorated' => false]);
        $this->em()->clear();

        return $tester;
    }

    private function valid(User $user, string $password): bool
    {
        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($this->em()->find(User::class, $user->getId()), $password);
    }

    public function testItPrintsARandomOneTimePasswordThatWorks(): void
    {
        $user = $this->createUser('alice', password: 'Forgotten-1');

        $tester = $this->execute(['username' => 'alice']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(1, preg_match('~One-time password: (\S{16})~', $tester->getDisplay(), $m), 'a password is printed');
        self::assertTrue($this->valid($user, $m[1]));
        self::assertFalse($this->valid($user, 'Forgotten-1'));
    }

    public function testTwoRunsGiveDifferentPasswords(): void
    {
        $this->createUser('alice');

        preg_match('~One-time password: (\S+)~', $this->execute(['username' => 'alice'])->getDisplay(), $first);
        preg_match('~One-time password: (\S+)~', $this->execute(['username' => 'alice'])->getDisplay(), $second);

        self::assertNotSame($first[1], $second[1]);
    }

    public function testItAcceptsAChosenPasswordAndPrintsNothingSecret(): void
    {
        $user = $this->createUser('alice');

        $tester = $this->execute(['username' => 'alice', '--password' => 'Chosen-by-the-owner-1']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertTrue($this->valid($user, 'Chosen-by-the-owner-1'));
        self::assertStringNotContainsString('Chosen-by-the-owner-1', $tester->getDisplay());
    }

    public function testAMemberStillOnTheLegacyHashIsUpgraded(): void
    {
        $user = $this->createUser('marie');
        $user->setSalt('abcsalt123')->setPassword('legacy-hash-value');
        $this->em()->flush();

        $this->execute(['username' => 'marie', '--password' => 'Chosen-by-the-owner-1']);

        $reloaded = $this->em()->find(User::class, $user->getId());
        self::assertNull($reloaded->getSalt());
        self::assertTrue($this->valid($user, 'Chosen-by-the-owner-1'));
    }

    public function testATooShortPasswordIsRefused(): void
    {
        $user = $this->createUser('alice', password: 'Forgotten-1');

        $tester = $this->execute(['username' => 'alice', '--password' => 'short']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertTrue($this->valid($user, 'Forgotten-1'), 'unchanged');
    }

    public function testAnUnknownMemberIsAnError(): void
    {
        $tester = $this->execute(['username' => 'nobody']);

        self::assertNotSame(0, $tester->getStatusCode());
    }
}
