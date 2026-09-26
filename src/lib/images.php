<?php
/*
 * Resized image variants (thumbnails for the gallery grid, "display" size for
 * lightbox/slideshow). Variants are cached next to the originals in
 * uploads/<event>/.variants/<size>/<name>.webp and are created on upload or
 * lazily on first request (see image.php).
 */

const IMAGE_VARIANTS = [
    'thumb' => 480,
    'display' => 1920,
];

function imageVariantRelPath(string $eventUuid, string $filename, string $size): string
{
    return "uploads/$eventUuid/.variants/$size/" . pathinfo($filename, PATHINFO_FILENAME) . '.webp';
}

/** URL for a variant: the static file if it exists, otherwise the generator endpoint. */
function imageVariantUrl(string $eventUuid, string $filename, string $size): string
{
    $rel = imageVariantRelPath($eventUuid, $filename, $size);
    if (is_file(dirname(__DIR__) . '/' . $rel)) {
        return implode('/', array_map('rawurlencode', explode('/', $rel)));
    }
    return 'image.php?' . http_build_query(['event' => $eventUuid, 'file' => $filename, 'size' => $size]);
}

/**
 * Creates a downscaled WebP copy of $source at $target (longest edge <= $maxEdge),
 * honouring the EXIF orientation. Returns false for formats GD can't read (e.g. HEIC).
 */
function createImageVariant(string $source, string $target, int $maxEdge): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    $info = @getimagesize($source);
    if ($info === false) {
        return false;
    }

    $image = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
        IMAGETYPE_PNG => @imagecreatefrompng($source),
        IMAGETYPE_WEBP => @imagecreatefromwebp($source),
        IMAGETYPE_GIF => @imagecreatefromgif($source),
        default => false,
    };
    if ($image === false) {
        return false;
    }

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($rotated !== false) {
            $image = $rotated;
        }
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $scale = min(1, $maxEdge / max($width, $height));
    if ($scale < 1) {
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));
        $scaled = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $image = $scaled;
    }

    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    // Write to a temp file first so concurrent requests never see half-written images.
    $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = imagewebp($image, $tmp, 80) && rename($tmp, $target);
    if (!$ok && is_file($tmp)) {
        unlink($tmp);
    }
    return $ok;
}

/** Deletes an uploaded photo together with its cached variants. */
function deleteUploadedImage(string $eventUuid, string $filename): void
{
    $root = dirname(__DIR__);
    $filename = basename($filename);
    foreach (array_keys(IMAGE_VARIANTS) as $size) {
        $variant = $root . '/' . imageVariantRelPath($eventUuid, $filename, $size);
        if (is_file($variant)) {
            unlink($variant);
        }
    }
    if (is_file("$root/uploads/$eventUuid/$filename")) {
        unlink("$root/uploads/$eventUuid/$filename");
    }
}

/** Pre-generates all variants for a freshly uploaded image. */
function createAllImageVariants(string $eventUuid, string $filename): void
{
    $root = dirname(__DIR__);
    foreach (IMAGE_VARIANTS as $size => $maxEdge) {
        createImageVariant(
            "$root/uploads/$eventUuid/$filename",
            $root . '/' . imageVariantRelPath($eventUuid, $filename, $size),
            $maxEdge
        );
    }
}
