<?php

namespace App\Tests\Content;

use App\Content\ContentSanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The editor's smiley picker (assets/vue/editor/smilies.js) must offer every gif of public/smilies and only those. */
class SmiliesListTest extends KernelTestCase
{
    /** @return list<string> */
    private function pickerFiles(): array
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/assets/vue/editor/smilies.js');
        self::assertIsString($source);
        preg_match_all("~file: '([^']+)'~", $source, $m);

        return $m[1];
    }

    public function testPickerListsExactlyTheSmiliesOfThePublicDirectory(): void
    {
        $onDisk = array_map('basename', glob(\dirname(__DIR__, 2).'/public/smilies/*.gif') ?: []);
        $listed = $this->pickerFiles();
        sort($onDisk);
        sort($listed);

        self::assertNotEmpty($onDisk);
        self::assertSame($onDisk, $listed);
        self::assertSame($listed, array_values(array_unique($listed)));
    }

    public function testEverySmileyTheEditorInsertsSurvivesSanitizing(): void
    {
        self::bootKernel();
        $sanitizer = static::getContainer()->get(ContentSanitizer::class);

        foreach ($this->pickerFiles() as $file) {
            $html = sprintf('<p><img src="/smilies/%s" alt=":)" /></p>', $file);
            self::assertSame($html, $sanitizer->sanitize($html), $file);
        }
    }
}
