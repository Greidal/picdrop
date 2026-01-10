<?php
require 'auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$eventId = $_GET['event'] ?? '';
if (!$eventId) {
    echo json_encode([]);
    exit;
}

checkEventAccess($conn, $eventId);

$stmt = $conn->prepare("
    SELECT u.*, d.name as drink_name, d.image_path 
    FROM uploads u 
    LEFT JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = ? 
    ORDER BY u.timestamp DESC
");
$stmt->bind_param("s", $eventId);
$stmt->execute();
$result = $stmt->get_result();

$images = [];
while ($row = $result->fetch_assoc()) {
    $images[] = [
        'file' => $row['filename'],
        'timestamp' => strtotime($row['timestamp']),
        'uploader' => $row['uploader_name'],
        'drink_name' => $row['drink_name'],
        'is_drink' => !empty($row['drink_id'])
    ];
}

echo json_encode($images);
