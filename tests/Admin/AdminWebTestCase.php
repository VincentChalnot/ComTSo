<?php

namespace App\Tests\Admin;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

/** Helpers of the admin panel tests: every address in the tests ends with `@example.test` (see createUser()), none may ever be shown. */
abstract class AdminWebTestCase extends AppWebTestCase
{
    protected function loginAdmin(string $username = 'root'): User
    {
        $admin = $this->createUser($username, ['ROLE_ADMIN']);
        $this->loginAs($admin);

        return $admin;
    }

    /** The page just fetched must not contain any e-mail address of a member. */
    protected function assertNoEmailAddress(): void
    {
        self::assertStringNotContainsString('@example.test', (string) $this->client->getResponse()->getContent(), 'an e-mail address leaked into the admin HTML');
    }

    protected function reloadUser(int $id): ?User
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $id);
    }
}
