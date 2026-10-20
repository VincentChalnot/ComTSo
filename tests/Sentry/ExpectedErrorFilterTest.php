<?php

namespace App\Tests\Sentry;

use App\Sentry\ExpectedErrorFilter;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class ExpectedErrorFilterTest extends TestCase
{
    private function filter(?\Throwable $exception): ?Event
    {
        return (new ExpectedErrorFilter())(Event::createEvent(), EventHint::fromArray(['exception' => $exception]));
    }

    public function testClientErrorsAreDropped(): void
    {
        self::assertNull($this->filter(new HttpException(404)));
        self::assertNull($this->filter(new HttpException(499)));
        self::assertNull($this->filter(new AuthenticationException()));
    }

    public function testServerErrorsAndUnknownExceptionsAreKept(): void
    {
        self::assertNotNull($this->filter(new HttpException(500)));
        self::assertNotNull($this->filter(new HttpException(503)));
        self::assertNotNull($this->filter(new \LogicException()));
    }

    public function testAnEventWithoutExceptionIsKept(): void
    {
        self::assertNotNull((new ExpectedErrorFilter())(Event::createEvent(), null));
        self::assertNotNull($this->filter(null));
    }
}
