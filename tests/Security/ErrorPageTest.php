<?php

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

/**
 * With `kernel.debug` off (production, see ProductionConfigTest) Symfony renders templates/bundles/TwigBundle/Exception/error.html.twig
 * for every error: it may tell the status, nothing about the exception behind it.
 */
class ErrorPageTest extends KernelTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
    public function testTheErrorPageSaysNothingAboutTheException(int $status): void
    {
        self::bootKernel();
        $exception = FlattenException::createFromThrowable(
            new \RuntimeException('SQLSTATE[HY000] Access denied for user comtso@10.9.8.7 in /app/src/Secret.php'),
            $status,
        );

        $page = self::getContainer()->get('twig')->render('bundles/TwigBundle/Exception/error.html.twig', [
            'status_code' => $status,
            'status_text' => 'Error',
            'exception' => $exception,
        ]);

        self::assertStringContainsString((string) $status, $page);
        foreach (['SQLSTATE', '10.9.8.7', 'Secret.php', 'RuntimeException', 'Stack trace'] as $leak) {
            self::assertStringNotContainsString($leak, $page);
        }
    }

    public static function statuses(): iterable
    {
        yield 'not found' => [404];
        yield 'forbidden' => [403];
        yield 'server error' => [500];
    }
}
