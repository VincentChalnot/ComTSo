<?php

namespace App\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Isolated photo directory (`var/test-photos`, see config/services.yaml) and upload builders for tests that store photos.
 * Call `preparePhotoDirectory()` from setUp() and `cleanPhotoDirectory()` from tearDown().
 */
trait PhotoFilesTrait
{
    protected string $photoDir;

    protected function preparePhotoDirectory(): void
    {
        $root = static::getContainer()->getParameter('kernel.project_dir').'/var/test-photos';
        (new Filesystem())->remove($root);
        $this->photoDir = $root.'/originals';
        mkdir($this->photoDir, 0775, true);
    }

    protected function cleanPhotoDirectory(): void
    {
        (new Filesystem())->remove(static::getContainer()->getParameter('kernel.project_dir').'/var/test-photos');
    }

    /** @return list<string> file names currently stored in the private photo directory */
    protected function storedFiles(): array
    {
        return array_map('basename', glob($this->photoDir.'/*') ?: []);
    }

    protected function jpegUpload(string $name = 'beach.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('upload-', true).'.jpg';
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagejpeg($image, $path);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    /** A file that claims to be a JPEG but is a script. */
    protected function fakeImageUpload(string $name = 'evil.jpg'): UploadedFile
    {
        file_put_contents($path = sys_get_temp_dir().'/'.uniqid('upload-', true).'.jpg', '<?php echo "pwned";');

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }
}
