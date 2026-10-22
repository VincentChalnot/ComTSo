<?php

namespace App\Tests\Content;

use App\Content\ContentSanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The editor no longer offers the gif smilies, but ~8000 stored messages use them: every gif of public/smilies keeps rendering. */
class SmiliesListTest extends KernelTestCase
{
    public function testEveryLegacySmileySurvivesSanitizing(): void
    {
        self::bootKernel();
        $sanitizer = static::getContainer()->get(ContentSanitizer::class);
        $files = array_map('basename', glob(\dirname(__DIR__, 2).'/public/smilies/*.gif') ?: []);

        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $html = sprintf('<p><img src="/smilies/%s" alt=":)" /></p>', $file);
            self::assertSame($html, $sanitizer->sanitize($html), $file);
        }
    }
}
