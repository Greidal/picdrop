<?php
// Regenerates the binary E2E fixtures: php tests/e2e/fixtures/generate.php
$dir = __DIR__;

// photo.jpg: plain 1600x1200 JPEG
$im = imagecreatetruecolor(1600, 1200);
imagefilledrectangle($im, 0, 0, 799, 1199, 0xFF0055);
imagefilledrectangle($im, 800, 0, 1599, 1199, 0x00FF88);
imagejpeg($im, "$dir/photo.jpg", 85);

// gps.jpg: JPEG with EXIF (Orientation + GPS IFD). The GPS latitude seconds use the
// marker denominator 0x5EC7E7ED ("secret"), so tests can detect leftover GPS bytes.
ob_start();
imagejpeg($im, null, 85);
$jpeg = ob_get_clean();
$u16 = static fn (int $v) => pack('v', $v);
$u32 = static fn (int $v) => pack('V', $v);
$entry = static fn (int $tag, int $type, int $count, string $value) =>
    $u16($tag) . $u16($type) . $u32($count) . str_pad($value, 4, "\0");
$gpsOffset = 8 + 2 + 2 * 12 + 4;
$latOffset = $gpsOffset + 2 + 4 * 12 + 4;
$lonOffset = $latOffset + 24;
$tiff = 'II' . $u16(42) . $u32(8)
    . $u16(2) . $entry(0x0112, 3, 1, $u16(1)) . $entry(0x8825, 4, 1, $u32($gpsOffset)) . $u32(0)
    . $u16(4)
    . $entry(0x0001, 2, 2, "N\0") . $entry(0x0002, 5, 3, $u32($latOffset))
    . $entry(0x0003, 2, 2, "E\0") . $entry(0x0004, 5, 3, $u32($lonOffset))
    . $u32(0)
    . $u32(52) . $u32(1) . $u32(31) . $u32(1) . $u32(1234) . $u32(0x5EC7E7ED)
    . $u32(13) . $u32(1) . $u32(24) . $u32(1) . $u32(5678) . $u32(100);
$app1 = "Exif\0\0" . $tiff;
file_put_contents("$dir/gps.jpg", "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));

// disguised.jpg: PHP code with an image file name
file_put_contents("$dir/disguised.jpg", "<?php echo 'PWNED'; system(\$_GET['c'] ?? 'id');\n");
