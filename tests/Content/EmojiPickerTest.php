<?php

namespace App\Tests\Content;

use App\Content\ContentSanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The editor's emoji picker (assets/vue/editor/emojis.js): a clean list, and every entry survives the sanitizer unchanged. */
class EmojiPickerTest extends KernelTestCase
{
    /** @return list<array{id: string, label: string, emojis: list<array{char: string, label: string}>}> */
    private function categories(): array
    {
        $source = file_get_contents(\dirname(__DIR__, 2).'/assets/vue/editor/emojis.js');
        self::assertIsString($source);

        $groups = [];
        foreach (preg_split("~\n  \{\n    id: ~", $source) as $i => $chunk) {
            if (0 === $i) {
                continue; // the header and the opening of the array
            }
            preg_match("~^'([a-z]+)',\s+icon: '([^']+)',\s+label: '([^']+)'~u", $chunk, $head);
            preg_match_all("~\{ char: '([^']+)', label: '([^']+)' \}~u", $chunk, $emojis, PREG_SET_ORDER);
            $groups[] = [
                'id' => $head[1],
                'icon' => $head[2],
                'label' => $head[3],
                'emojis' => array_map(static fn (array $m): array => ['char' => $m[1], 'label' => $m[2]], $emojis),
            ];
        }

        return $groups;
    }

    public function testThePickerOffersCategoriesOfLabelledEmojis(): void
    {
        $groups = $this->categories();

        self::assertGreaterThanOrEqual(5, \count($groups));
        self::assertSame(array_values(array_unique(array_column($groups, 'id'))), array_column($groups, 'id'), 'category ids are unique');
        $all = [];
        foreach ($groups as $group) {
            self::assertNotSame('', $group['label']);
            self::assertNotEmpty($group['emojis'], $group['id']);
            self::assertContains($group['icon'], array_column($group['emojis'], 'char'), $group['id'].': the category button shows one of its emojis');
            foreach ($group['emojis'] as $emoji) {
                self::assertNotSame('', trim($emoji['label']), $emoji['char'].' needs a French accessible name');
                $all[] = $emoji['char'];
            }
        }
        self::assertSame(array_values(array_unique($all)), $all, 'no emoji is offered twice');
        self::assertGreaterThanOrEqual(80, \count($all));
    }

    public function testEveryEmojiOfThePickerSurvivesSanitizingAndReSanitizing(): void
    {
        self::bootKernel();
        $sanitizer = static::getContainer()->get(ContentSanitizer::class);

        foreach ($this->categories() as $group) {
            foreach ($group['emojis'] as $emoji) {
                $html = '<p>Bravo '.$emoji['char'].'</p>';
                self::assertSame($html, $sanitizer->sanitize($html), $emoji['label']);
            }
        }
    }
}
