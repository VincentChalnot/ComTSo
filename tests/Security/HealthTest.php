<?php

namespace App\Tests\Security;

use App\Tests\Support\AppWebTestCase;
use Doctrine\DBAL\Connection;

class HealthTest extends AppWebTestCase
{
    public function testItIsPublicAndSaysNothingButOk(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertSame('ok', $this->client->getResponse()->getContent());
        self::assertStringStartsWith('text/plain', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertEmpty($this->client->getResponse()->headers->getCookies(), 'no session is started for a probe');
    }

    public function testItAnswers503WithoutLeakingTheReasonWhenTheDatabaseIsDown(): void
    {
        $broken = $this->createStub(Connection::class);
        $broken->method('executeQuery')->willThrowException(new \RuntimeException('Access denied for user comtso@10.1.2.3 using password YES'));
        static::getContainer()->set('doctrine.dbal.default_connection', $broken);
        $this->client->disableReboot();

        $this->client->request('GET', '/health');

        self::assertResponseStatusCodeSame(503);
        self::assertSame('unavailable', $this->client->getResponse()->getContent());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('10.1.2.3', (string) $this->client->getResponse()->getContent());
    }
}
