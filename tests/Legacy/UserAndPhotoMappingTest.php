<?php

namespace App\Tests\Legacy;

use App\Legacy\Import\PhotoLinks;
use App\Legacy\Import\UserMapper;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class UserAndPhotoMappingTest extends TestCase
{
    public function testUsernamesAreComparedWithoutCaseAccentsOrPunctuation(): void
    {
        self::assertSame('shogun', UserMapper::normalize('Shogûn'));
        self::assertSame('nounou', UserMapper::normalize('nounou !'));
        self::assertSame('ledjo', UserMapper::normalize('Le Djo'));
        self::assertSame('mimipaslogee', UserMapper::normalize('Mimi (pas logée)'));
    }

    public function testOldViewerLinksAreRewrittenToThePhotoRoutes(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([
            ['id' => '41', 'original_filename' => '610.jpg'],
        ]);
        $links = new PhotoLinks($connection);

        $html = '<a title="x" href="image.php?photo=610.jpg"><img src="image.php?thumb=610.jpg" alt="x"></a>';
        self::assertSame('<a title="x" href="/photos/41"><img src="/photos/41/thumb" alt="x"></a>', $links->rewrite($html));

        $links->register(611, 99);
        self::assertSame('<a href="/photos/99">', $links->rewrite('<a href="image.php?photo=611.jpg">'));
        self::assertSame('<a >', $links->rewrite('<a href="image.php?photo=999.jpg">'), 'a photo that no longer exists loses its link');
    }
}
