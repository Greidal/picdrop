<?php
// Serves a resized variant of an uploaded photo, generating and caching it on first request.
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/images.php';

if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);

$filename = basename((string) ($_GET['file'] ?? ''));
$size = (string) ($_GET['size'] ?? 'thumb');
$original = __DIR__ . "/uploads/$uuid/$filename";

if ($filename === '' || !isset(IMAGE_VARIANTS[$size]) || !is_file($original)) {
    http_response_code(404);
    exit;
}

$variant = __DIR__ . '/' . imageVariantRelPath($uuid, $filename, $size);
if (!is_file($variant) && !createImageVariant($original, $variant, IMAGE_VARIANTS[$size])) {
    // Format not supported by GD (e.g. HEIC): fall back to the original.
    $variant = $original;
}

header('Content-Type: ' . (mime_content_type($variant) ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($variant));
header('Cache-Control: private, max-age=31536000, immutable');
readfile($variant);
