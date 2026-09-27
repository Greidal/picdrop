<?php
require_once __DIR__ . '/config.php';

const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

/** Escape a value for HTML (text and attribute context). */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isHttps(?string $appUrl = null): bool
{
    if (str_starts_with($appUrl ?? APP_URL, 'https://')) {
        return true;
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

/** Absolute base URL without trailing slash. Prefers APP_URL over the (spoofable) Host header. */
function appBaseUrl(?string $appUrl = null): string
{
    $appUrl = rtrim($appUrl ?? APP_URL, '/');
    if ($appUrl !== '') {
        return $appUrl;
    }
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
    return (isHttps('') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function generateUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function isValidUuid($uuid): bool
{
    return is_string($uuid)
        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) === 1;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function isValidCsrfToken($sent): bool
{
    return is_string($sent)
        && !empty($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

function requireCsrf(): void
{
    if (!isValidCsrfToken($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        http_response_code(403);
        die("⛔ Sitzung abgelaufen. Bitte Seite neu laden und erneut versuchen.");
    }
}

/** File extension for an allowed image type, detected from the file content; null otherwise. */
function detectImageExtension(string $path): ?string
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    return is_string($mime) ? (ALLOWED_IMAGE_TYPES[$mime] ?? null) : null;
}

/** Neutralises values that spreadsheet apps would interpret as formulas (CSV injection). */
function csvCell(?string $value): string
{
    $value = str_replace([';', "\r", "\n"], ' ', (string) $value);
    return preg_match('/^[=+\-@\t]/', $value) ? "'" . $value : $value;
}

/**
 * Validates an uploaded image by its content (not by name/extension) and moves it
 * to $targetDir under a random name. Returns the relative path or null on failure.
 */
function storeUploadedImage(array $file, string $targetDir): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    $extension = detectImageExtension($file['tmp_name']);
    if ($extension === null) {
        return null;
    }

    $targetDir = rtrim($targetDir, '/') . '/';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        return null;
    }

    $targetFile = $targetDir . time() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
        return null;
    }
    chmod($targetFile, 0644);

    return $targetFile;
}

/** Recursively removes a directory below the uploads folder. */
function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $fileinfo->isDir() ? rmdir($fileinfo->getPathname()) : unlink($fileinfo->getPathname());
    }
    rmdir($dir);
}
