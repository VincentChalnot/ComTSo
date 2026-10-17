<?php

namespace App\Tests\Twig;

use App\Twig\TimeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TimeExtensionTest extends TestCase
{
    /** @return iterable<string, array{string, string}> offset from "now" (a past duration) and the expected label */
    public static function agoProvider(): iterable
    {
        yield 'now' => ['0 seconds', 'à l\'instant'];
        yield 'under a minute' => ['59 seconds', 'à l\'instant'];
        yield 'a minute' => ['60 seconds', 'il y a 1 min'];
        yield 'minutes' => ['59 minutes', 'il y a 59 min'];
        yield 'an hour' => ['1 hour', 'il y a 1 h'];
        yield 'hours' => ['23 hours', 'il y a 23 h'];
        yield 'a day' => ['1 day', 'il y a 1 j'];
        yield 'days' => ['6 days', 'il y a 6 j'];
        yield 'a week' => ['7 days', 'il y a 1 sem.'];
        yield 'weeks' => ['29 days', 'il y a 4 sem.'];
        yield 'a month' => ['30 days', 'il y a 1 mois'];
        yield 'months' => ['200 days', 'il y a 6 mois'];
        yield 'a year' => ['365 days', 'il y a 1 an'];
        yield 'years' => ['800 days', 'il y a 2 ans'];
    }

    #[DataProvider('agoProvider')]
    public function testAgo(string $offset, string $expected): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00');

        self::assertSame($expected, (new TimeExtension())->ago($now->modify("-$offset"), $now));
    }

    public function testFutureDatesAndNullAreHandled(): void
    {
        $now = new \DateTimeImmutable('2026-06-15 12:00:00');

        self::assertSame('à l\'instant', (new TimeExtension())->ago($now->modify('+2 hours'), $now));
        self::assertSame('', (new TimeExtension())->ago(null, $now));
    }
}
