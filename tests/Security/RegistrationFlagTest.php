<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Tests\Support\AppWebTestCase;

class RegistrationFlagTest extends AppWebTestCase
{
    private ?string $originalFlag = null;

    protected function setUp(): void
    {
        $this->originalFlag = $_SERVER['REGISTRATION_ENABLED'] ?? null;
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->setFlag($this->originalFlag);
        parent::tearDown();
    }

    private function setFlag(?string $value): void
    {
        if (null === $value) {
            unset($_SERVER['REGISTRATION_ENABLED'], $_ENV['REGISTRATION_ENABLED']);
        } else {
            $_SERVER['REGISTRATION_ENABLED'] = $_ENV['REGISTRATION_ENABLED'] = $value;
        }
    }

    public function testRegistrationIsDisabledByDefault(): void
    {
        $this->client->request('GET', '/register');

        self::assertResponseRedirects('/login');
    }

    public function testRegistrationCannotBeUsedWhenDisabled(): void
    {
        $this->client->request('POST', '/register', ['registration_form' => ['username' => 'mallory', 'email' => 'm@example.test', 'plainPassword' => 'secret123']]);

        self::assertResponseRedirects('/login');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'mallory']));
    }

    public function testFeatureFlagReenablesRegistration(): void
    {
        self::ensureKernelShutdown();
        $this->setFlag('true');
        $this->client = static::createClient();

        $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
    }
}
