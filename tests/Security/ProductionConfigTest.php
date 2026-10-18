<?php

namespace App\Tests\Security;

use App\Kernel;
use PHPUnit\Framework\TestCase;

/**
 * What production runs (APP_ENV=prod, APP_DEBUG=0): booted for real, because a `when@prod` mistake is invisible in the test environment.
 */
class ProductionConfigTest extends TestCase
{
    private static ?Kernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        self::$kernel = new Kernel('prod', false);
        self::$kernel->boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
    }

    public function testNoDebuggingToolIsLoaded(): void
    {
        self::assertFalse(self::$kernel->isDebug());
        $bundles = array_keys(self::$kernel->getBundles());
        self::assertNotContains('WebProfilerBundle', $bundles, 'no toolbar, no profiler');
        self::assertNotContains('DebugBundle', $bundles);
        self::assertNotContains('MakerBundle', $bundles);
        self::assertFalse(self::$kernel->getContainer()->has('profiler'), 'no profiler service');
    }

    public function testCookiesAreSecureHttpOnlyAndSameSiteLax(): void
    {
        $options = self::$kernel->getContainer()->getParameter('session.storage.options');

        self::assertTrue($options['cookie_secure'], 'never sent over plain HTTP');
        self::assertTrue($options['cookie_httponly']);
        self::assertSame('lax', $options['cookie_samesite']);
    }
}
