<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Outgoing TLS (SMTP STARTTLS to the mail provider) is verified against a CA bundle: PHP's built-in default path does not exist in the
 * FrankenPHP images, so `openssl.cafile` must point at the bundle the image ships. Production once failed with "certificate verify failed".
 */
class TlsTrustStoreTest extends TestCase
{
    public function testPhpIniDeclaresAnExistingCaBundle(): void
    {
        $ini = parse_ini_file(__DIR__.'/../../frankenphp/conf.d/10-app.ini');
        self::assertSame('/etc/ssl/certs/ca-certificates.crt', $ini['openssl.cafile'] ?? null);
    }

    public function testTheRunningPhpTrustsAnExistingCaBundle(): void
    {
        $locations = openssl_get_cert_locations();
        $file = '' !== $locations['ini_cafile'] ? $locations['ini_cafile'] : $locations['default_cert_file'];
        self::assertFileExists($file, 'PHP must have a CA bundle to verify TLS peers');
        self::assertGreaterThan(100000, filesize($file));
    }
}
