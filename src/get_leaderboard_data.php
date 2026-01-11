<?php
require_once 'auth.php';

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

$users = [];
$resUsers = $conn->query("
    SELECT uploader_name, COUNT(*) as count 
    FROM uploads 
    WHERE event_id = '$eventId' AND drink_id IS NOT NULL AND uploader_name != '' 
    GROUP BY uploader_name ORDER BY count DESC LIMIT 30
");
while ($row = $resUsers->fetch_assoc()) {
    $users[] = $row;
}

$details = [];
$resDetails = $conn->query("
    SELECT u.uploader_name, d.name, d.image_path, COUNT(*) as qty
    FROM uploads u
    JOIN drinks d ON u.drink_id = d.id
    WHERE u.event_id = '$eventId' AND u.uploader_name != ''
    GROUP BY u.uploader_name, d.name
    ORDER BY u.uploader_name, qty DESC
");
while ($row = $resDetails->fetch_assoc()) {
    $details[$row['uploader_name']][] = [
        'drink' => $row['name'],
        'img' => $row['image_path'],
        'qty' => $row['qty']
    ];
}

$drinks = [];
$resDrinks = $conn->query("
    SELECT d.name, d.image_path, COUNT(*) as count 
    FROM uploads u JOIN drinks d ON u.drink_id = d.id 
    WHERE u.event_id = '$eventId' 
    GROUP BY d.id ORDER BY count DESC
");
while ($row = $resDrinks->fetch_assoc()) {
    $drinks[] = $row;
}

echo json_encode([
    'users' => $users,
    'details' => $details,
    'drinks' => $drinks
]);
