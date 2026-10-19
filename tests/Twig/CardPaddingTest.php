<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\TestCase;

/**
 * The `.card` component carries its own padding (`.card-roomy` / `.card-flush` are the only variants): no template,
 * showcase section or Vue component may add a `p-*` utility or an inline padding on a card.
 */
final class CardPaddingTest extends TestCase
{
    public function testCardsHaveNoHandAddedPadding(): void
    {
        $root = \dirname(__DIR__, 2);
        foreach (['templates', 'design-system/sections', 'assets/vue'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (preg_match('/\.(twig|html|vue)$/', $file->getFilename())) {
                    $this->check($file->getPathname());
                }
            }
        }
    }

    private function check(string $path): void
    {
        $source = (string) file_get_contents($path);
        preg_match_all('/<[a-z0-9]+\b[^>]*\sclass="([^"]*)"[^>]*>/i', $source, $tags, PREG_SET_ORDER);
        foreach ($tags as [$tag, $classes]) {
            if (!\in_array('card', preg_split('/\s+/', $classes) ?: [], true)) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/(^|\s|:)p[xytblr]?-\d/', $classes, $path.': p-* utility on a card: '.$tag);
            self::assertDoesNotMatchRegularExpression('/style="[^"]*padding/', $tag, $path.': inline padding on a card: '.$tag);
        }
    }
}
