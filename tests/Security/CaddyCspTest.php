<?php

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/** The Caddyfile CSP whitelists the inline theme script of base.html.twig by hash: keep both in sync. */
final class CaddyCspTest extends TestCase
{
    public function testInlineScriptHashMatchesCaddyfile(): void
    {
        $root = dirname(__DIR__, 2);
        $twig = (string) file_get_contents($root.'/templates/base.html.twig');
        self::assertSame(1, preg_match_all('#<script>(.*?)</script>#s', $twig, $m), 'base.html.twig must hold exactly one inline script');
        $hash = base64_encode(hash('sha256', $m[1][0], true));

        $caddyfile = (string) file_get_contents($root.'/frankenphp/Caddyfile');
        self::assertStringContainsString(
            "'sha256-$hash'",
            $caddyfile,
            "The inline script changed: replace the sha256 in the Content-Security-Policy of frankenphp/Caddyfile with 'sha256-$hash'."
        );
    }
}
