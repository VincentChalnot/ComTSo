<?php

namespace App\Tests\Photos;

use App\Entity\Photo;
use App\Photo\FeaturedPhoto;
use App\Photo\FeaturedRule;
use PHPUnit\Framework\TestCase;

class FeaturedPhotoTest extends TestCase
{
    private function featured(?int $width, ?int $height): FeaturedPhoto
    {
        return new FeaturedPhoto((new Photo())->setWidth($width)->setHeight($height), FeaturedRule::Week);
    }

    public function testImageSizeIsScaledToTheHeroVariantAndNeverUpscaled(): void
    {
        self::assertSame(['width' => 1600, 'height' => 800], $this->featured(3200, 1600)->imageSize());
        self::assertSame(['width' => 800, 'height' => 600], $this->featured(800, 600)->imageSize());
        self::assertSame(['width' => 900, 'height' => 1600], $this->featured(1800, 3200)->imageSize());
    }

    public function testImageSizeIsUnknownWithoutDimensions(): void
    {
        self::assertNull($this->featured(null, null)->imageSize());
        self::assertNull($this->featured(1600, null)->imageSize());
    }
}
