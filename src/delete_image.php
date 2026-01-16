<?php
require_once 'auth.php';
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uuid = $_POST['event_uuid'] ?? '';
    $filename = $_POST['filename'] ?? '';

    checkEventAccess($conn, $uuid);

    $filename = basename($filename);

    if (!$uuid || !$filename) {
        http_response_code(400);
        die("Missing data.");
    }

    $filePath = __DIR__ . "/uploads/$uuid/$filename";
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    $stmt = $conn->prepare("DELETE FROM uploads WHERE event_id = ? AND filename = ?");
    $stmt->bind_param("ss", $uuid, $filename);
    $stmt->execute();

    echo "OK";
} else {
    http_response_code(405);
    die("Method not allowed");
}
