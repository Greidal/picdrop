<?php
// QR code of the guest link as a file download (PNG for printing/sharing, SVG for print shops).
require_once __DIR__ . '/lib/auth.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;

requireLogin();
$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);
$eventName = getEventOrDie($conn, $uuid);

$format = ($_GET['format'] ?? 'png') === 'svg' ? 'svg' : 'png';
$options = new QROptions([
    'outputInterface' => $format === 'svg' ? QRMarkupSVG::class : QRGdImagePNG::class,
    'outputBase64' => false,
    'scale' => 20,
    'quietzoneSize' => 2,
]);
$data = (new QRCode($options))->render(appBaseUrl() . "/?event=" . urlencode($uuid));

$fileName = 'QR_' . (preg_replace('/[^A-Za-z0-9_-]+/', '_', $eventName) ?: 'Event') . '.' . $format;
header('Content-Type: ' . ($format === 'svg' ? 'image/svg+xml' : 'image/png'));
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . strlen($data));
echo $data;
