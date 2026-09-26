<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/picdrop-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[DataProvider('byteOrders')]
    public function testJpegGpsIsRemovedLosslessly(string $byteOrder): void
    {
        $path = $this->dir . '/photo.jpg';
        file_put_contents($path, self::jpegWithExif($byteOrder));

        $before = exif_read_data($path);
        $this->assertArrayHasKey('GPSLatitude', $before);
        $imageDataBefore = self::scanData(file_get_contents($path));

        $this->assertSame(METADATA_STRIPPED, stripLocationMetadata($path));

        $after = exif_read_data($path);
        $this->assertArrayNotHasKey('GPSLatitude', $after);
        $this->assertArrayNotHasKey('GPSLongitude', $after);
        $this->assertSame(6, $after['Orientation'], 'other EXIF data must be kept');
        $this->assertSame($imageDataBefore, self::scanData(file_get_contents($path)), 'image data must not change');
        $this->assertStringNotContainsString('GPSLatitude', file_get_contents($path));
        $this->assertNotFalse(imagecreatefromjpeg($path));
    }

    public function testJpegXmpIsDropped(): void
    {
        $path = $this->dir . '/photo.jpg';
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><exif:GPSLatitude>52,31.0N</exif:GPSLatitude></x:xmpmeta>";
        file_put_contents($path, self::insertJpegSegment(self::plainJpeg(), 0xE1, $xmp));

        $this->assertSame(METADATA_STRIPPED, stripLocationMetadata($path));
        $this->assertStringNotContainsString('GPSLatitude', file_get_contents($path));
        $this->assertNotFalse(imagecreatefromjpeg($path));
    }

    public function testJpegWithoutMetadataIsUnchanged(): void
    {
        $path = $this->dir . '/photo.jpg';
        $original = self::plainJpeg();
        file_put_contents($path, $original);

        $this->assertSame(METADATA_STRIPPED, stripLocationMetadata($path));
        $this->assertSame($original, file_get_contents($path));
    }

    public function testPngExifAndXmpChunksAreDropped(): void
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($im);
        $png = ob_get_clean();

        $chunk = static fn (string $type, string $body): string =>
            pack('N', strlen($body)) . $type . $body . pack('N', crc32($type . $body));
        // Insert after IHDR (8 byte signature + 25 byte IHDR chunk).
        $png = substr($png, 0, 33)
            . $chunk('eXIf', self::tiffWithGps('MM'))
            . $chunk('iTXt', "XML:com.adobe.xmp\0\0\0\0\0<exif:GPSLatitude>1</exif:GPSLatitude>")
            . substr($png, 33);

        $path = $this->dir . '/photo.png';
        file_put_contents($path, $png);

        $this->assertSame(METADATA_STRIPPED, stripLocationMetadata($path));
        $cleaned = file_get_contents($path);
        $this->assertStringNotContainsString('eXIf', $cleaned);
        $this->assertStringNotContainsString('GPSLatitude', $cleaned);
        $this->assertNotFalse(imagecreatefromstring($cleaned));
    }

    public function testWebpExifChunkIsDropped(): void
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagewebp($im);
        $webp = ob_get_clean();

        // Rebuild as extended WebP (VP8X) with an EXIF chunk.
        $bitstream = substr($webp, 12);
        $vp8x = 'VP8X' . pack('V', 10) . chr(0x08) . "\0\0\0" . substr(pack('V', 7), 0, 3) . substr(pack('V', 7), 0, 3);
        $exif = self::tiffWithGps('II');
        $exifChunk = 'EXIF' . pack('V', strlen($exif)) . $exif . (strlen($exif) % 2 ? "\0" : '');
        $body = 'WEBP' . $vp8x . $bitstream . $exifChunk;
        $path = $this->dir . '/photo.webp';
        file_put_contents($path, 'RIFF' . pack('V', strlen($body)) . $body);

        $this->assertSame(METADATA_STRIPPED, stripLocationMetadata($path));
        $cleaned = file_get_contents($path);
        $this->assertStringNotContainsString('EXIF', $cleaned);
        $this->assertSame(strlen($cleaned) - 8, unpack('V', $cleaned, 4)[1], 'RIFF size must match');
        $this->assertSame(0, ord($cleaned[20]) & 0x08, 'EXIF flag must be cleared');
        $this->assertNotFalse(imagecreatefromstring($cleaned));
    }

    public function testUnsupportedAndBrokenFiles(): void
    {
        $heic = $this->dir . '/photo.heic';
        file_put_contents($heic, "\0\0\0\x18ftypheic");
        $this->assertSame(METADATA_UNSUPPORTED, stripLocationMetadata($heic));

        $broken = $this->dir . '/broken.jpg';
        file_put_contents($broken, "\xFF\xD8\xFF\xE1\xFF\xFFExif");
        $this->assertSame(METADATA_FAILED, stripLocationMetadata($broken));
        $this->assertSame("\xFF\xD8\xFF\xE1\xFF\xFFExif", file_get_contents($broken), 'broken files stay untouched');
    }

    public static function byteOrders(): array
    {
        return ['little endian' => ['II'], 'big endian' => ['MM']];
    }

    private static function plainJpeg(): string
    {
        $im = imagecreatetruecolor(16, 16);
        imagefill($im, 0, 0, 0x3366FF);
        ob_start();
        imagejpeg($im);
        return ob_get_clean();
    }

    private static function jpegWithExif(string $byteOrder): string
    {
        return self::insertJpegSegment(self::plainJpeg(), 0xE1, "Exif\0\0" . self::tiffWithGps($byteOrder));
    }

    private static function insertJpegSegment(string $jpeg, int $marker, string $payload): string
    {
        return "\xFF\xD8\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** TIFF block: IFD0 = [Orientation=6, GPSInfo->GPS IFD]; GPS IFD = [LatRef, Lat, LonRef, Lon]. */
    private static function tiffWithGps(string $order): string
    {
        $le = $order === 'II';
        $u16 = static fn (int $v): string => pack($le ? 'v' : 'n', $v);
        $u32 = static fn (int $v): string => pack($le ? 'V' : 'N', $v);
        $entry = static fn (int $tag, int $type, int $count, string $value): string =>
            $u16($tag) . $u16($type) . $u32($count) . str_pad($value, 4, "\0");

        $ifd0Offset = 8;
        $gpsOffset = $ifd0Offset + 2 + 2 * 12 + 4;          // 38
        $latOffset = $gpsOffset + 2 + 4 * 12 + 4;           // 92
        $lonOffset = $latOffset + 24;
        $rationals = static fn (array $v): string => implode('', array_map(
            static fn (array $r): string => $u32($r[0]) . $u32($r[1]),
            $v
        ));

        return $order . $u16(42) . $u32($ifd0Offset)
            . $u16(2)
            . $entry(0x0112, 3, 1, $u16(6))
            . $entry(0x8825, 4, 1, $u32($gpsOffset))
            . $u32(0)
            . $u16(4)
            . $entry(0x0001, 2, 2, "N\0")
            . $entry(0x0002, 5, 3, $u32($latOffset))
            . $entry(0x0003, 2, 2, "E\0")
            . $entry(0x0004, 5, 3, $u32($lonOffset))
            . $u32(0)
            . $rationals([[52, 1], [31, 1], [1234, 100]])
            . $rationals([[13, 1], [24, 1], [5678, 100]]);
    }

    /** Everything from the start-of-scan marker on (the compressed image data). */
    private static function scanData(string $jpeg): string
    {
        return substr($jpeg, strpos($jpeg, "\xFF\xDA"));
    }
}
