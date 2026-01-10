<?php
require_once 'auth.php';
requireLogin();

ini_set('display_errors', 0);

$userId = getCurrentUserId();
$uuid = $_GET['event'] ?? '';
checkEventAccess($conn, $uuid);

$sourceDir = __DIR__ . "/uploads/" . $uuid;
if (!is_dir($sourceDir) && !isset($_GET['export_db'])) die("Keine Daten vorhanden.");

$zipFile = tempnam(sys_get_temp_dir(), 'zip');
$zip = new ZipArchive();

if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Kann Zip nicht erstellen");
}

if (is_dir($sourceDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
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
    $file = str_replace(';', '', $row['filename']);
    $user = str_replace(';', '', $row['uploader_name']);
    $time = $row['timestamp'];
    $drink = str_replace(';', '', $row['drink_name'] ?? '-');

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
    unlink($zipFile);
    exit;
} else {
    die("Fehler beim Erstellen des Backups.");
}
