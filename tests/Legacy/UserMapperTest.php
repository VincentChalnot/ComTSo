<?php

namespace App\Tests\Legacy;

use App\Legacy\Import\UserMapper;
use App\Legacy\Model\LegacyUser;
use App\Tests\Support\AppWebTestCase;

/** Accounts of the old platforms without a live counterpart become disabled users that keep what the old platforms knew. */
final class UserMapperTest extends AppWebTestCase
{
    private function row(string $username): array
    {
        return $this->em()->getConnection()->fetchAssociative('SELECT * FROM fos_user WHERE username = ?', [$username]);
    }

    public function testDisabledAccountKeepsItsRealAddressAndRegistrationDate(): void
    {
        $this->createUser('alice');
        $mapper = new UserMapper($this->em()->getConnection(), false);
        $mapper->register([
            new LegacyUser('phpbb3', 53, 'Dr Schtroumpf', 'schtroumpf@example.org', new \DateTimeImmutable('2007-06-24 10:00:00')),
            new LegacyUser('phpbb2', 3, 'Dr Schtroumpf', 'old@example.org', new \DateTimeImmutable('2004-05-01 10:00:00')),
        ]);

        $id = $mapper->resolve('phpbb3', 53);

        $row = $this->row('Dr Schtroumpf');
        self::assertSame($id, (int) $row['id']);
        self::assertSame('schtroumpf@example.org', $row['email'], 'The most recent platform knows the address.');
        self::assertSame('2004-05-01 10:00:00', $row['registered_at'], 'The earliest registration.');
        self::assertSame(0, (int) $row['enabled'], 'Disabled: nobody can log into it.');
        self::assertStringStartsWith('!', $row['password']);
    }

    public function testPlaceholderAddressWhenTheRealOneIsUnknownMalformedOrTaken(): void
    {
        $this->createUser('alice');
        $mapper = new UserMapper($this->em()->getConnection(), false);
        $mapper->register([
            new LegacyUser('phpbb3', 1, 'Frofro', null, null),
            new LegacyUser('phpbb3', 2, 'Janna', 'not an address', null),
            new LegacyUser('phpbb3', 4, 'Merry', 'merry@example.org', null),
        ]);

        foreach ([1, 2, 4] as $id) {
            $mapper->resolve('phpbb3', $id);
        }

        self::assertStringEndsWith('@legacy.invalid', $this->row('Frofro')['email']);
        self::assertStringEndsWith('@legacy.invalid', $this->row('Janna')['email']);
        self::assertSame('merry@example.org', $this->row('Merry')['email']);
    }
}
