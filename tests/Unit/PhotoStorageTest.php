<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PhotoStorage;
use PHPUnit\Framework\TestCase;

final class PhotoStorageTest extends TestCase
{
    private string $dir;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'photo_storage_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function storage(): PhotoStorage
    {
        return new PhotoStorage($this->dir, 5 * 1048576);
    }

    private function touch(string $name): string
    {
        $path = $this->dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, 'stub');
        $this->files[] = $path;
        return $path;
    }

    public function testThumbNameAppendsThumbSuffixBeforeExtension(): void
    {
        self::assertSame('abc123.thumb.jpg', PhotoStorage::thumbName('abc123.jpg'));
        self::assertSame('x.thumb.jpg', PhotoStorage::thumbName('x.webp'));
        self::assertSame('x.thumb.jpg', PhotoStorage::thumbName('x.png'));
    }

    public function testDeleteRemovesOriginalAndDerivative(): void
    {
        $original = $this->touch('photo.jpg');
        $thumb = $this->touch(PhotoStorage::thumbName('photo.jpg'));

        $this->storage()->delete('photo.jpg');

        self::assertFileDoesNotExist($original);
        self::assertFileDoesNotExist($thumb);
    }

    public function testDeleteIgnoresMissingFiles(): void
    {
        $this->storage()->delete('never-existed.jpg');
        $this->storage()->delete(null);
        $this->storage()->delete('');
        self::assertTrue(true);
    }

    public function testCreateThumbnailSkipsGracefullyWithoutGd(): void
    {
        if (function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD is available in this runtime.');
        }
        $source = $this->touch('photo.jpg');

        self::assertFalse($this->storage()->createThumbnail($source));
        self::assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . PhotoStorage::thumbName('photo.jpg'));
    }

    public function testCreateThumbnailGenerates800pxDerivativeWhenGdIsAvailable(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD is not available in this runtime.');
        }
        $source = $this->dir . DIRECTORY_SEPARATOR . 'wide.jpg';
        $img = imagecreatetruecolor(1600, 900);
        imagejpeg($img, $source, 90);
        imagedestroy($img);

        self::assertTrue($this->storage()->createThumbnail($source));

        $thumb = $this->dir . DIRECTORY_SEPARATOR . PhotoStorage::thumbName('wide.jpg');
        self::assertFileExists($thumb);
        $info = getimagesize($thumb);
        self::assertIsArray($info);
        self::assertSame(800, $info[0]);
        self::assertSame(450, $info[1]);
    }

    public function testCreateThumbnailLeavesSmallImagesAlone(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('GD is not available in this runtime.');
        }
        $source = $this->dir . DIRECTORY_SEPARATOR . 'small.jpg';
        $img = imagecreatetruecolor(640, 480);
        imagejpeg($img, $source, 90);
        imagedestroy($img);

        self::assertFalse($this->storage()->createThumbnail($source));
        self::assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . PhotoStorage::thumbName('small.jpg'));
    }
}
