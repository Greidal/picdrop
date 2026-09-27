<?php

use PHPUnit\Framework\TestCase;

final class ImagesTest extends TestCase
{
    private string $event;
    private string $eventDir;

    protected function setUp(): void
    {
        $this->event = generateUuidV4();
        $this->eventDir = dirname(__DIR__) . '/src/uploads/' . $this->event;
        mkdir($this->eventDir, 0777, true);
    }

    protected function tearDown(): void
    {
        removeDirectory($this->eventDir);
        @rmdir(dirname($this->eventDir)); // only removed if empty
    }

    public function testCreatesDownscaledWebpVariants(): void
    {
        $this->writeJpeg('photo.jpg', 3000, 2000);
        createAllImageVariants($this->event, 'photo.jpg');

        $thumb = $this->eventDir . '/.variants/thumb/photo.webp';
        $display = $this->eventDir . '/.variants/display/photo.webp';
        $this->assertSame([480, 320, IMAGETYPE_WEBP], array_slice(getimagesize($thumb), 0, 3));
        $this->assertSame([1920, 1280, IMAGETYPE_WEBP], array_slice(getimagesize($display), 0, 3));
    }

    public function testSmallImagesAreNotUpscaled(): void
    {
        $this->writeJpeg('small.jpg', 300, 200);
        $target = $this->eventDir . '/.variants/display/small.webp';

        $this->assertTrue(createImageVariant($this->eventDir . '/small.jpg', $target, 1920));
        $this->assertSame([300, 200], array_slice(getimagesize($target), 0, 2));
    }

    public function testExifOrientationIsApplied(): void
    {
        // Landscape pixels, EXIF says "rotate 90° CW" (6) -> variant must be portrait.
        $im = imagecreatetruecolor(800, 400);
        ob_start();
        imagejpeg($im);
        $jpeg = ob_get_clean();
        $tiff = "II*\0" . pack('V', 8) . pack('v', 1) . pack('vvVV', 0x0112, 3, 1, 6) . pack('V', 0);
        $app1 = "Exif\0\0" . $tiff;
        file_put_contents($this->eventDir . '/rot.jpg', "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));

        $target = $this->eventDir . '/.variants/thumb/rot.webp';
        $this->assertTrue(createImageVariant($this->eventDir . '/rot.jpg', $target, 480));
        $this->assertSame([240, 480], array_slice(getimagesize($target), 0, 2));
    }

    public function testUnsupportedFormatsReturnFalse(): void
    {
        file_put_contents($this->eventDir . '/x.heic', "\0\0\0\x18ftypheic");
        $this->assertFalse(createImageVariant($this->eventDir . '/x.heic', $this->eventDir . '/.variants/thumb/x.webp', 480));
        $this->assertFileDoesNotExist($this->eventDir . '/.variants/thumb/x.webp');
    }

    public function testVariantUrlPointsToGeneratorUntilVariantExists(): void
    {
        $this->writeJpeg('photo.jpg', 100, 100);

        $url = imageVariantUrl($this->event, 'photo.jpg', 'thumb');
        $this->assertStringStartsWith('image.php?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['event' => $this->event, 'file' => 'photo.jpg', 'size' => 'thumb'], $query);

        createAllImageVariants($this->event, 'photo.jpg');
        $this->assertSame("uploads/{$this->event}/.variants/thumb/photo.webp", imageVariantUrl($this->event, 'photo.jpg', 'thumb'));
    }

    public function testDeleteRemovesOriginalAndVariants(): void
    {
        $this->writeJpeg('photo.jpg', 600, 400);
        createAllImageVariants($this->event, 'photo.jpg');

        deleteUploadedImage($this->event, '../' . $this->event . '/photo.jpg'); // path parts are ignored

        $this->assertFileDoesNotExist($this->eventDir . '/photo.jpg');
        $this->assertFileDoesNotExist($this->eventDir . '/.variants/thumb/photo.webp');
        $this->assertFileDoesNotExist($this->eventDir . '/.variants/display/photo.webp');
    }

    private function writeJpeg(string $name, int $width, int $height): void
    {
        imagejpeg(imagecreatetruecolor($width, $height), $this->eventDir . '/' . $name);
    }
}
