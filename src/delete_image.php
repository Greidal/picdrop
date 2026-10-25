<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/images.php';

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

// One photo ('filename') or several at once ('filenames[]', gallery selection).
$names = isset($_POST['filenames']) && is_array($_POST['filenames']) ? $_POST['filenames'] : [$_POST['filename'] ?? ''];
$filenames = array_values(array_unique(array_filter(array_map(
    static fn ($name) => basename((string) $name),
    array_slice($names, 0, 1000)
), static fn ($name) => $name !== '')));
if (!$filenames) {
    http_response_code(400);
    die("Missing data.");
}

$stmt = $conn->prepare("DELETE FROM uploads WHERE event_id = ? AND filename = ?");
foreach ($filenames as $filename) {
    $stmt->bind_param("ss", $uuid, $filename);
    $stmt->execute();
    if ($stmt->affected_rows > 0) {
        deleteUploadedImage($uuid, $filename);
    }
}

echo "OK";
