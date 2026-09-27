<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();

$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);

$sourceDir = __DIR__ . "/uploads/" . $uuid;
if (!is_dir($sourceDir) && !isset($_GET['export_db'])) die("Keine Daten vorhanden.");

// Build the archive on the uploads volume: /tmp is a small RAM-backed tmpfs in the container.
$tmpDir = __DIR__ . '/uploads/.tmp';
if (!is_dir($tmpDir)) {
    mkdir($tmpDir, 0750, true);
}
$zipFile = tempnam($tmpDir, 'zip');
// Make sure the temp file is removed even if the client aborts the download.
ignore_user_abort(true);
register_shutdown_function(static function () use ($zipFile): void {
    if (is_file($zipFile)) {
        unlink($zipFile);
    }
});
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Kann Zip nicht erstellen");
}

if (is_dir($sourceDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        // Cached thumbnails are not part of the backup.
        if (!$file->isDir() && !str_contains($file->getPathname(), '/.variants/')) {
            $filePath = $file->getPathname();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }
}

$csvData = "Dateiname;Uploader;Zeitstempel;Getraenk;EventID\n";

$stmt = $conn->prepare("
    SELECT u.filename, u.uploader_name, u.timestamp, d.name as drink_name 
    FROM uploads u 
    LEFT JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = ?
");
$stmt->bind_param("s", $uuid);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $file = csvCell($row['filename']);
    $user = csvCell($row['uploader_name']);
    $time = $row['timestamp'];
    $drink = $row['drink_name'] !== null ? csvCell($row['drink_name']) : '-';

    $csvData .= "$file;$user;$time;$drink;$uuid\n";
}

$zip->addFromString('datenbank_export.csv', $csvData);


$zip->close();

if (file_exists($zipFile)) {
    if (ob_get_length()) ob_clean();

    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="Event_Backup_' . $uuid . '.zip"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($zipFile));

    readfile($zipFile);
    exit;
} else {
    die("Fehler beim Erstellen des Backups.");
}
