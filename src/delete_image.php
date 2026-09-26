<?php
require_once __DIR__ . '/lib/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Method not allowed");
}
if (!isLoggedIn()) {
    http_response_code(403);
    die("Not logged in");
}
requireCsrf();

$uuid = $_POST['event_uuid'] ?? '';
checkEventAccess($conn, $uuid);

$filename = basename((string) ($_POST['filename'] ?? ''));
if ($filename === '') {
    http_response_code(400);
    die("Missing data.");
}

$stmt = $conn->prepare("DELETE FROM uploads WHERE event_id = ? AND filename = ?");
$stmt->bind_param("ss", $uuid, $filename);
$stmt->execute();

$filePath = __DIR__ . "/uploads/$uuid/$filename";
if ($stmt->affected_rows > 0 && is_file($filePath)) {
    unlink($filePath);
}

echo "OK";
